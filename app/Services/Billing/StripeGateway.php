<?php

namespace App\Services\Billing;

use App\Contracts\BillingGatewayInterface;
use App\Events\PlanChanged;
use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionExpired;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionStarted;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\BillingPaymentFailedNotification;
use App\Services\Mail\MailService;
use App\Services\WebhookIdempotencyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Response;

class StripeGateway implements BillingGatewayInterface
{
    private ?StripeClient $client = null;

    public function __construct(
        private string $secretKey,
        private string $webhookSecret,
        private string $successUrl,
        private string $cancelUrl
    ) {}

    public function name(): string
    {
        return 'Stripe';
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '' && $this->secretKey !== '0';
    }

    protected function client(): StripeClient
    {
        if ($this->client === null) {
            $this->client = new StripeClient($this->secretKey);
        }

        return $this->client;
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Stripe is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        try {
            $stripe = $this->client();
            $interval = $billingCycle === 'year' ? 'year' : 'month';

            // Use pre-configured catalog price ID when available, otherwise create ad-hoc
            $catalogPriceId = $billingCycle === 'year' ? $plan->stripe_yearly_id : $plan->stripe_monthly_id;
            if ($catalogPriceId) {
                $priceId = $catalogPriceId;
            } else {
                $price = $stripe->prices->create([
                    'currency' => strtolower($plan->currency_code ?? 'usd'),
                    'unit_amount' => $priceCents,
                    'recurring' => ['interval' => $interval],
                    'product_data' => ['name' => $plan->name.' ('.$interval.'ly)'],
                ]);
                $priceId = $price->id;
            }

            $subscriptionData = [
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'plan_id' => (string) $plan->id,
                ],
            ];
            if ($plan->trial_days && $plan->trial_days > 0) {
                $subscriptionData['trial_period_days'] = $plan->trial_days;
            }

            $session = $stripe->checkout->sessions->create([
                'payment_method_types' => ['card'],
                'mode' => 'subscription',
                'customer_email' => $user->email,
                'line_items' => [['price' => $priceId, 'quantity' => 1]],
                'success_url' => $this->successUrl.(str_contains($this->successUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->cancelUrl,
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'plan_id' => (string) $plan->id,
                    'billing_cycle' => $billingCycle,
                ],
                'subscription_data' => $subscriptionData,
            ]);

            return ['url' => $session->url];
        } catch (\Throwable $e) {
            Log::error('Stripe createCheckout failed', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            return ['error' => $e->getMessage()];
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature');

        try {
            if ($this->webhookSecret) {
                $event = Webhook::constructEvent($payload, $sig, $this->webhookSecret);
            } elseif (app()->environment('production')) {
                Log::warning('Stripe webhook secret not configured in production');

                return new Response('Webhook secret not configured', 401);
            } else {
                $event = Event::constructFrom(json_decode($payload, true));
            }
        } catch (\Throwable $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

            return new Response('Invalid signature', 400);
        }

        Log::info('Stripe webhook received', ['type' => $event->type, 'id' => $event->id ?? null]);

        // Idempotency: skip duplicate events
        if ($event->id && ! app(WebhookIdempotencyService::class)->isNewEvent('stripe', $event->id)) {
            return new Response('OK', 200);
        }
        try {
            match ($event->type) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted' => $this->handleSubscriptionUpdated($event->data->object),
                'invoice.paid',
                'invoice.payment_succeeded' => $this->handleInvoicePaid($event->data->object),
                'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event->data->object),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Stripe webhook handler failed', ['type' => $event->type, 'error' => $e->getMessage()]);

            // Release the idempotency lock so Stripe's automatic retry can reprocess this event;
            // otherwise a transient failure would be permanently deduped and the renewal lost.
            if ($event->id) {
                app(WebhookIdempotencyService::class)->release('stripe', $event->id);
            }

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    private function handleCheckoutCompleted(Session $session): void
    {
        $userId = (int) ($session->metadata->user_id ?? 0);
        $planId = (int) ($session->metadata->plan_id ?? 0);
        $billingCycle = $session->metadata->billing_cycle ?? 'month';
        $subId = $session->subscription;
        if (! $userId || ! $planId || ! $subId) {
            return;
        }

        $user = User::find($userId);
        $plan = Plan::find($planId);

        // The webhook only carries the subscription ID; retrieve the object so we can record the
        // accurate renewal date and trial end straight away (instead of waiting for a later event).
        $renewsAt = null;
        $trialEndsAt = ($plan && $plan->trial_days > 0) ? now()->addDays($plan->trial_days) : null;
        $initialStatus = ($plan && $plan->trial_days > 0) ? 'trialing' : 'active';
        try {
            $stripeSub = $this->client()->subscriptions->retrieve($subId, ['expand' => ['items']]);
            $initialStatus = $stripeSub->status ?: $initialStatus;
            $periodEnd = $this->subscriptionPeriodEnd($stripeSub);
            $renewsAt = $periodEnd ? Carbon::createFromTimestamp($periodEnd) : null;
            if (! empty($stripeSub->trial_end)) {
                $trialEndsAt = Carbon::createFromTimestamp($stripeSub->trial_end);
            }
        } catch (\Throwable $e) {
            Log::warning('Stripe handleCheckoutCompleted: subscription retrieve failed', ['sub' => $subId, 'error' => $e->getMessage()]);
        }

        $subscription = Subscription::updateOrCreate(
            [
                'gateway' => 'stripe',
                'gateway_subscription_id' => $subId,
            ],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => $initialStatus,
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => $renewsAt,
                'trial_ends_at' => $trialEndsAt,
            ]
        );

        if ($user && $plan && $subscription->wasRecentlyCreated) {
            SubscriptionStarted::dispatch($user, $subscription, $plan);
        }
    }

    private function handleSubscriptionUpdated(\Stripe\Subscription $sub): void
    {
        $subscription = Subscription::where('gateway', 'stripe')
            ->where('gateway_subscription_id', $sub->id)
            ->with('user', 'plan')
            ->first();
        if (! $subscription) {
            return;
        }

        $previousStatus = $subscription->status;
        $newStatus = $sub->status;
        $periodEnd = $this->subscriptionPeriodEnd($sub);

        $subscription->update([
            'status' => $newStatus,
            'ends_at' => $sub->cancel_at ? Carbon::createFromTimestamp($sub->cancel_at) : null,
            'renews_at' => $periodEnd ? Carbon::createFromTimestamp($periodEnd) : $subscription->renews_at,
            'trial_ends_at' => $sub->trial_end ? Carbon::createFromTimestamp($sub->trial_end) : $subscription->trial_ends_at,
        ]);

        $user = $subscription->user;
        $plan = $subscription->plan;

        if ($user && $plan) {
            if ($previousStatus !== 'canceled' && $newStatus === 'canceled') {
                SubscriptionCancelled::dispatch($user, $subscription, $plan);
            } elseif (! in_array($previousStatus, ['past_due', 'unpaid', 'incomplete_expired'], true)
                && in_array($newStatus, ['past_due', 'unpaid', 'incomplete_expired'], true)) {
                SubscriptionExpired::dispatch($user, $subscription, $plan);
            }
        }
        // Payment-failure notifications are handled exclusively by handleInvoicePaymentFailed
        // to avoid duplicate alerts on the same event.
    }

    private function handleInvoicePaid(Invoice $invoice): void
    {
        $subId = $this->invoiceSubscriptionId($invoice);
        $subscription = $subId
            ? Subscription::where('gateway', 'stripe')
                ->where('gateway_subscription_id', $subId)
                ->with('user', 'plan')
                ->first()
            : null;

        $alreadyRecorded = PaymentTransaction::where('gateway', 'stripe')
            ->where('gateway_transaction_id', $invoice->id)
            ->exists();

        $transaction = null;
        if (! $alreadyRecorded) {
            $transaction = PaymentTransaction::create([
                'user_id' => $subscription?->user_id,
                'subscription_id' => $subscription?->id,
                'gateway' => 'stripe',
                'gateway_transaction_id' => $invoice->id,
                'amount_cents' => $invoice->amount_paid,
                'currency_code' => strtoupper($invoice->currency ?? ''),
                'status' => 'paid',
                'payload' => $invoice->toArray(),
            ]);
            $this->generateInvoicePdf($transaction);
        }

        if (! $subscription) {
            return;
        }

        // Advance the local renewal date to the new period end so the
        // subscription page and the renewal email show the correct date.
        $periodEnd = $this->invoicePeriodEnd($invoice);
        if ($periodEnd) {
            $subscription->update(['renews_at' => Carbon::createFromTimestamp($periodEnd)]);
            $subscription->refresh();
            $subscription->loadMissing('user', 'plan');
        }

        // Only a genuine recurring renewal should fire the "renewed" email.
        // The first invoice (subscription_create) is covered by SubscriptionStarted,
        // and proration invoices (subscription_update) by PlanChanged.
        $isRenewal = ($invoice->billing_reason ?? null) === 'subscription_cycle';

        if ($isRenewal && $subscription->user && $subscription->plan) {
            SubscriptionRenewed::dispatch(
                $subscription->user,
                $subscription,
                $subscription->plan,
                (int) ($invoice->amount_paid ?? 0),
                strtoupper($invoice->currency ?? 'USD'),
            );
        }
    }

    private function handleInvoicePaymentFailed(Invoice $invoice): void
    {
        $subId = $this->invoiceSubscriptionId($invoice);
        $subscription = $subId
            ? Subscription::where('gateway', 'stripe')
                ->where('gateway_subscription_id', $subId)
                ->with('user')
                ->first()
            : null;

        if (! $subscription?->user) {
            return;
        }

        $invoiceId = $invoice->id ?? 'unknown';
        $amount = number_format(($invoice->amount_due ?? 0) / 100, 2);
        $currency = strtoupper($invoice->currency ?? 'USD');

        // Mark the subscription as past_due if the gateway hasn't already, so the
        // app gates access correctly while Stripe retries the payment.
        if (! in_array($subscription->status, ['past_due', 'unpaid', 'canceled'], true)) {
            $subscription->update(['status' => 'past_due']);
        }

        // Deliverable email to the account owner via the configured SMTP transport.
        $this->sendPaymentFailedEmail($subscription->user, $amount, $currency);

        // In-app / push notification to every workspace member.
        $workspace = Workspace::where('owner_id', $subscription->user_id)->first();
        $members = $workspace
            ? User::where('workspace_id', $workspace->id)->get()
            : collect([$subscription->user]);

        foreach ($members as $member) {
            try {
                $member->notify(new BillingPaymentFailedNotification($invoiceId, $amount, $currency));
            } catch (\Throwable $e) {
                Log::warning('Stripe: failed to dispatch BillingPaymentFailedNotification', ['user_id' => $member->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Fulfill checkout when user lands on success URL with session_id (e.g. when webhooks can't reach localhost).
     * Creates Subscription and first PaymentTransaction so plan and billing page show correctly.
     */
    public function fulfillCheckoutSession(string $sessionId): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Stripe not configured.'];
        }

        try {
            $session = $this->client()->checkout->sessions->retrieve($sessionId, [
                'expand' => ['subscription', 'subscription.latest_invoice'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Stripe fulfillCheckoutSession: retrieve failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // Accept 'paid' (normal) or 'no_payment_required' (trial start)
        $validPaymentStatuses = ['paid', 'no_payment_required'];
        if (! in_array($session->payment_status, $validPaymentStatuses, true) || ! $session->subscription) {
            return ['ok' => false, 'error' => 'Session not completed or no subscription.'];
        }

        $userId = (int) ($session->metadata->user_id ?? 0);
        $planId = (int) ($session->metadata->plan_id ?? 0);
        $billingCycle = $session->metadata->billing_cycle ?? 'month';
        if (! $userId || ! $planId) {
            return ['ok' => false, 'error' => 'Missing user_id or plan_id in session.'];
        }

        $plan = Plan::find($planId);
        $subId = is_object($session->subscription) ? $session->subscription->id : $session->subscription;
        $subscription = Subscription::where('gateway', 'stripe')
            ->where('gateway_subscription_id', $subId)
            ->first();

        $renewsAt = null;
        $trialEndsAt = null;
        if (is_object($session->subscription)) {
            $periodEnd = $this->subscriptionPeriodEnd($session->subscription);
            if ($periodEnd) {
                $renewsAt = Carbon::createFromTimestamp($periodEnd);
            }
            if (! empty($session->subscription->trial_end)) {
                $trialEndsAt = Carbon::createFromTimestamp($session->subscription->trial_end);
            }
        }

        // Determine status: trialing if plan has trial days or Stripe says so
        $isTrial = $trialEndsAt !== null || ($plan && $plan->trial_days > 0);
        $initialStatus = $isTrial ? 'trialing' : 'active';
        if (! $trialEndsAt && $isTrial && $plan) {
            $trialEndsAt = now()->addDays($plan->trial_days);
        }

        $isNew = ! $subscription;
        if ($isNew) {
            $subscription = Subscription::create([
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => $initialStatus,
                'starts_at' => now(),
                'ends_at' => null,
                'gateway' => 'stripe',
                'gateway_subscription_id' => $subId,
                'renews_at' => $renewsAt,
                'trial_ends_at' => $trialEndsAt,
            ]);
        } else {
            $subscription->update([
                'billing_cycle' => $billingCycle,
                'renews_at' => $renewsAt,
                'trial_ends_at' => $trialEndsAt ?? $subscription->trial_ends_at,
            ]);
        }

        if ($isNew && $plan) {
            $user = User::find($userId);
            if ($user) {
                SubscriptionStarted::dispatch($user, $subscription, $plan);
            }
        }

        $invoice = null;
        if (is_object($session->subscription) && isset($session->subscription->latest_invoice)) {
            $invoice = $session->subscription->latest_invoice;
        }
        $amountCents = 0;
        $currency = 'USD';
        $invoiceId = $session->id;
        if ($invoice && is_object($invoice)) {
            $amountCents = (int) ($invoice->amount_paid ?? 0);
            $currency = strtoupper($invoice->currency ?? 'usd');
            $invoiceId = $invoice->id;
        } else {
            $amountCents = (int) ($session->amount_total ?? 0);
            $currency = strtoupper($session->currency ?? 'usd');
        }

        // Only record a transaction if there was an actual charge (not a $0 trial start)
        $existing = PaymentTransaction::where('gateway', 'stripe')
            ->where('gateway_transaction_id', $invoiceId)
            ->exists();
        if (! $existing && $subscription && $amountCents > 0) {
            $transaction = PaymentTransaction::create([
                'user_id' => $userId,
                'subscription_id' => $subscription->id,
                'gateway' => 'stripe',
                'gateway_transaction_id' => $invoiceId,
                'amount_cents' => $amountCents,
                'currency_code' => $currency,
                'status' => 'paid',
                'payload' => [],
            ]);
            $this->generateInvoicePdf($transaction);
        }

        return ['ok' => true, 'subscription' => $subscription];
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'stripe' || ! $this->isConfigured()) {
            return false;
        }

        try {
            $this->client()->subscriptions->cancel($subscription->gateway_subscription_id);
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            $user = $subscription->user ?? User::find($subscription->user_id);
            $plan = $subscription->plan ?? Plan::find($subscription->plan_id);
            if ($user && $plan) {
                SubscriptionCancelled::dispatch($user, $subscription, $plan);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Stripe cancel failed', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'stripe' || ! $this->isConfigured()) {
            return false;
        }

        try {
            $sub = $this->client()->subscriptions->retrieve($subscription->gateway_subscription_id);
            $periodEnd = $this->subscriptionPeriodEnd($sub);
            $subscription->update([
                'status' => $sub->status,
                'ends_at' => $sub->cancel_at ? Carbon::createFromTimestamp($sub->cancel_at) : null,
                'renews_at' => $periodEnd ? Carbon::createFromTimestamp($periodEnd) : $subscription->renews_at,
                'trial_ends_at' => $sub->trial_end ? Carbon::createFromTimestamp($sub->trial_end) : $subscription->trial_ends_at,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Stripe sync failed', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Stripe is not configured.'];
        }

        $priceKey = $billingCycle === 'year' ? 'stripe_yearly_id' : 'stripe_monthly_id';
        $newPriceId = $newPlan->{$priceKey};

        if (! $newPriceId) {
            return ['ok' => false, 'error' => "New plan does not have a Stripe price configured for billing cycle '{$billingCycle}'."];
        }

        try {
            $stripeSub = $this->client()->subscriptions->retrieve($subscription->gateway_subscription_id);
            $itemId = $stripeSub->items->data[0]->id ?? null;

            if (! $itemId) {
                return ['ok' => false, 'error' => 'Could not find subscription item on Stripe.'];
            }

            $this->client()->subscriptions->update($subscription->gateway_subscription_id, [
                'items' => [['id' => $itemId, 'price' => $newPriceId]],
                'proration_behavior' => 'create_prorations',
            ]);

            $oldPlan = $subscription->plan ?? Plan::find($subscription->plan_id);
            $subscription->update([
                'plan_id' => $newPlan->id,
                'billing_cycle' => $billingCycle,
                'status' => 'active',
            ]);

            $user = $subscription->user ?? User::find($subscription->user_id);
            if ($user && $oldPlan) {
                PlanChanged::dispatch($user, $subscription, $oldPlan, $newPlan);
            }

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Stripe changePlan failed', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Stripe is not configured.'];
        }

        $gatewayTxId = $transaction->gateway_transaction_id ?? null;
        if (! $gatewayTxId) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        try {
            // gateway_transaction_id is stored as the Stripe invoice ID (in_xxx).
            // Stripe refunds require a charge or payment_intent ID, so resolve it first.
            $paymentIntentId = $gatewayTxId;
            if (str_starts_with($gatewayTxId, 'in_')) {
                // invoice.payment_intent was removed in the 2025-03-31.basil API version;
                // the payment now lives on invoice.payments[].payment.payment_intent.
                $invoice = $this->client()->invoices->retrieve($gatewayTxId, ['expand' => ['payments']]);
                $paymentIntentId = $this->invoicePaymentIntentId($invoice);
                if (! $paymentIntentId) {
                    return ['ok' => false, 'error' => 'Invoice has no payment intent to refund.'];
                }
            }

            $params = ['payment_intent' => $paymentIntentId];
            if ($amountCents) {
                $params['amount'] = $amountCents;
            }
            $this->client()->refunds->create($params);

            $transaction->update([
                'refunded_at' => now(),
                'refunded_cents' => $amountCents ?? $transaction->amount_cents,
                'status' => 'refunded',
            ]);

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Stripe refund failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Stripe API-shape helpers ────────────────────────────────────────────────
    // The 2025-03-31.basil API version (the default for accounts created since
    // March 2025, and what stripe-php v19 targets) moved several fields off the
    // top-level Subscription/Invoice objects. These helpers read the new location
    // and transparently fall back to the legacy field for older accounts.

    /** current_period_end moved from the Subscription to its line items. */
    private function subscriptionPeriodEnd(\Stripe\Subscription $sub): ?int
    {
        $arr = $sub->toArray();
        $end = data_get($arr, 'items.data.0.current_period_end')
            ?? data_get($arr, 'current_period_end');

        return $end ? (int) $end : null;
    }

    /** invoice.subscription moved to invoice.parent.subscription_details.subscription. */
    private function invoiceSubscriptionId(Invoice $invoice): ?string
    {
        $arr = $invoice->toArray();
        $sub = data_get($arr, 'parent.subscription_details.subscription')
            ?? data_get($arr, 'subscription');

        if (is_array($sub)) {
            $sub = $sub['id'] ?? null;
        }

        return $sub ? (string) $sub : null;
    }

    /** End of the period this invoice paid for — used to advance renews_at. */
    private function invoicePeriodEnd(Invoice $invoice): ?int
    {
        $arr = $invoice->toArray();
        // Subscription line items are listed after prorations; scan for the last
        // line that carries a period end (the renewed term).
        $lines = data_get($arr, 'lines.data', []);
        $end = null;
        foreach ($lines as $line) {
            $candidate = data_get($line, 'period.end');
            if ($candidate) {
                $end = $candidate;
            }
        }

        return $end ? (int) $end : null;
    }

    /** invoice.payment_intent moved to invoice.payments[].payment.payment_intent. */
    private function invoicePaymentIntentId(Invoice $invoice): ?string
    {
        $arr = $invoice->toArray();
        $pi = data_get($arr, 'payments.data.0.payment.payment_intent')
            ?? data_get($arr, 'payment_intent');

        if (is_array($pi)) {
            $pi = $pi['id'] ?? null;
        }

        return $pi ? (string) $pi : null;
    }

    /** Best-effort PDF invoice generation; never let it break webhook processing. */
    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Stripe: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }

    /** Deliver the payment-failed email through the configured SMTP transport. */
    private function sendPaymentFailedEmail(User $user, string $amount, string $currency): void
    {
        try {
            app(MailService::class)->sendWithTemplate('payment_failed', $user->email, [
                'app_name' => config('app.name'),
                'user_name' => $user->name,
                'amount' => $amount,
                'currency' => $currency,
                'billing_url' => route('client.billing.index'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Stripe: payment-failed email send failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
