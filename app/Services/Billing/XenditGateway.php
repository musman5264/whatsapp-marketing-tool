<?php

namespace App\Services\Billing;

use App\Contracts\BillingGatewayInterface;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionStarted;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\WebhookIdempotencyService;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Xendit recurring billing via the Recurring Plans API (v6).
 *
 * Flow:
 *   1. Create or upsert a Xendit Customer (reference_id = app user ID).
 *   2. Create a Recurring Plan linked to that customer — Xendit returns an
 *      `actions[].url` hosted checkout page for the first payment authorization.
 *   3. Customer authorizes → Xendit charges each cycle automatically and
 *      notifies us via `recurring.plan.payment_created` webhooks.
 *
 * Supported countries: Indonesia, Philippines, Vietnam, Thailand, Malaysia.
 * Payment methods: cards, GoPay, OVO, Dana (ID), GCash, Maya (PH), and more.
 *
 * Webhook authentication: the `x-callback-token` header must match the
 * XENDIT_WEBHOOK_TOKEN configured in Xendit's dashboard notification settings.
 *
 * Amounts are in the smallest currency subunit (e.g. IDR uses no decimals,
 * PHP/THB use 2). The app's `amount_cents` (÷100) maps directly; amounts
 * will be a few orders of magnitude off for IDR unless the plan price is set
 * in IDR cents already — document this for the admin who sets plan prices.
 */
class XenditGateway implements BillingGatewayInterface
{
    private const BASE_URL = 'https://api.xendit.co';

    public function __construct(
        private string $secretKey,
        private string $webhookToken,
        private string $successUrl,
        private string $cancelUrl,
    ) {}

    public function name(): string
    {
        return 'Xendit';
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    private function http(): PendingRequest
    {
        // Xendit uses HTTP Basic auth: secret key as username, empty password.
        return Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson();
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Xendit is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($plan->currency_code ?? 'IDR');
        $interval = $billingCycle === 'year' ? 'MONTH' : 'MONTH';
        $intervalCount = $billingCycle === 'year' ? 12 : 1;

        // 1) Create or update Xendit customer.
        $customerId = $this->upsertCustomer($user);
        if (! $customerId) {
            return ['error' => 'Failed to create Xendit customer.'];
        }

        // 2) Create recurring plan.
        $body = [
            'reference_id' => 'plan_'.$user->id.'_'.$plan->id.'_'.$billingCycle.'_'.time(),
            'customer_id' => $customerId,
            'recurring_action' => 'PAYMENT',
            'currency' => $currency,
            'amount' => $priceCents,
            'payment_methods' => [
                ['type' => 'CARD'],
                ['type' => 'EWALLET'],
            ],
            'schedule' => [
                'reference_id' => 'sched_'.$user->id.'_'.time(),
                'interval' => $interval,
                'interval_count' => $intervalCount,
                'anchor_date' => now()->toIso8601String(),
                'retry_interval' => 'DAY',
                'retry_interval_count' => 3,
                'total_retry' => 3,
                'failed_attempt_notifications' => [1, 3],
            ],
            'success_return_url' => $this->successUrl,
            'failure_return_url' => $this->cancelUrl,
            'metadata' => [
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'billing_cycle' => $billingCycle,
            ],
        ];

        if (($plan->trial_days ?? 0) > 0) {
            $body['schedule']['anchor_date'] = now()->addDays((int) $plan->trial_days)->toIso8601String();
        }

        $res = $this->http()->post(self::BASE_URL.'/recurring/plans', $body);

        if (! $res->successful()) {
            Log::error('Xendit create recurring plan failed', ['body' => $res->json(), 'user_id' => $user->id]);

            return ['error' => $res->json('message', 'Xendit recurring plan creation failed.')];
        }

        $planId = $res->json('id');
        $actions = $res->json('actions', []);
        $url = collect($actions)->firstWhere('action', 'AUTH')['url']
            ?? collect($actions)->first()['url']
            ?? null;

        if (! $url) {
            return ['error' => 'No checkout URL in Xendit response.'];
        }

        return ['url' => $url, 'subscription_id' => $planId];
    }

    private function upsertCustomer(User $user): ?string
    {
        $refId = 'user_'.$user->id;
        $email = $user->email ?? ('user'.$user->id.'@placeholder.invalid');
        $name = $user->name ?? 'Customer';

        // Try to fetch existing customer first.
        $getRes = $this->http()->get(self::BASE_URL.'/customers', [
            'reference_id' => $refId,
        ]);

        if ($getRes->successful()) {
            $existing = $getRes->json('data.0.id') ?? $getRes->json('0.id') ?? null;
            if ($existing) {
                return $existing;
            }
        }

        $createRes = $this->http()->post(self::BASE_URL.'/customers', [
            'reference_id' => $refId,
            'type' => 'INDIVIDUAL',
            'individual_detail' => [
                'given_names' => $name,
            ],
            'email' => $email,
        ]);

        if (! $createRes->successful()) {
            Log::error('Xendit create customer failed', ['body' => $createRes->json(), 'user_id' => $user->id]);

            return null;
        }

        return $createRes->json('id');
    }

    public function handleWebhook(Request $request): Response
    {
        // Verify callback token.
        $token = $request->header('x-callback-token', '');
        if ($this->webhookToken === '' && app()->environment('production')) {
            Log::warning('Xendit webhook: XENDIT_WEBHOOK_TOKEN not configured in production — rejecting request');

            return new Response('Webhook token not configured', 401);
        }
        if ($this->webhookToken !== '' && ! hash_equals($this->webhookToken, $token)) {
            Log::warning('Xendit webhook: invalid callback token');

            return new Response('Unauthorized', 401);
        }

        $data = $request->json()->all();
        $event = $data['event'] ?? '';
        $eventId = $data['data']['id'] ?? ($data['id'] ?? null);

        if ($eventId && ! app(WebhookIdempotencyService::class)->isNewEvent('xendit', (string) $eventId.'_'.$event)) {
            return new Response('OK', 200);
        }

        try {
            match ($event) {
                'recurring.plan.payment_created' => $this->handlePaymentCreated($data['data'] ?? []),
                'recurring.plan.activated' => $this->handlePlanActivated($data['data'] ?? []),
                'recurring.plan.inactivated', 'recurring.plan.stopped' => $this->handlePlanInactivated($data['data'] ?? []),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Xendit webhook handler failed', ['event' => $event, 'error' => $e->getMessage()]);
            if ($eventId) {
                app(WebhookIdempotencyService::class)->release('xendit', (string) $eventId.'_'.$event);
            }

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    private function handlePaymentCreated(array $payment): void
    {
        $planId = $payment['recurring_plan_id'] ?? $payment['plan_id'] ?? '';
        $paymentId = $payment['id'] ?? '';
        $status = strtoupper($payment['status'] ?? '');

        if (! $planId || $status !== 'SUCCEEDED') {
            return;
        }

        $subscription = Subscription::where('gateway', 'xendit')
            ->where('gateway_subscription_id', $planId)
            ->with('user', 'plan')
            ->first();

        if (! $subscription) {
            // First payment — activate the subscription from plan metadata.
            $this->activateFromPlan($planId, $payment);
            $subscription = Subscription::where('gateway', 'xendit')
                ->where('gateway_subscription_id', $planId)
                ->with('user', 'plan')
                ->first();
        }

        if (PaymentTransaction::where('gateway', 'xendit')->where('gateway_transaction_id', $paymentId)->exists()) {
            return;
        }

        $isRenewal = $subscription && PaymentTransaction::where('gateway', 'xendit')
            ->where('subscription_id', $subscription->id)
            ->where('status', 'paid')
            ->exists();

        $amountCents = (int) ($payment['amount'] ?? 0);
        $currency = strtoupper($payment['currency'] ?? 'IDR');

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription?->user_id,
            'subscription_id' => $subscription?->id,
            'gateway' => 'xendit',
            'gateway_transaction_id' => $paymentId,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $payment,
        ]);

        $this->generateInvoicePdf($transaction);

        if ($subscription) {
            $billing = $subscription->billing_cycle ?? 'month';
            $subscription->update([
                'status' => 'active',
                'renews_at' => $billing === 'year' ? now()->addYear() : now()->addMonth(),
            ]);

            if ($isRenewal && $subscription->user && $subscription->plan) {
                SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
            }
        }
    }

    private function activateFromPlan(string $xenditPlanId, array $payment): void
    {
        // Fetch the plan to retrieve metadata.
        $res = $this->http()->get(self::BASE_URL.'/recurring/plans/'.$xenditPlanId);
        if (! $res->successful()) {
            return;
        }

        $planData = $res->json();
        $meta = $planData['metadata'] ?? [];
        $userId = (int) ($meta['user_id'] ?? 0);
        $planId = (int) ($meta['plan_id'] ?? 0);
        $billingCycle = $meta['billing_cycle'] ?? 'month';

        if (! $userId || ! $planId) {
            return;
        }

        $isNew = ! Subscription::where('gateway', 'xendit')
            ->where('gateway_subscription_id', $xenditPlanId)
            ->exists();

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'xendit', 'gateway_subscription_id' => $xenditPlanId],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => $billingCycle === 'year' ? now()->addYear() : now()->addMonth(),
            ]
        );

        if ($isNew) {
            $user = User::find($userId);
            $plan = Plan::find($planId);
            if ($user && $plan) {
                SubscriptionStarted::dispatch($user, $subscription, $plan);
            }
        }
    }

    private function handlePlanActivated(array $plan): void
    {
        $planId = $plan['id'] ?? '';
        if (! $planId) {
            return;
        }
        Subscription::where('gateway', 'xendit')
            ->where('gateway_subscription_id', $planId)
            ->update(['status' => 'active']);
    }

    private function handlePlanInactivated(array $plan): void
    {
        $planId = $plan['id'] ?? '';
        if (! $planId) {
            return;
        }
        Subscription::where('gateway', 'xendit')
            ->where('gateway_subscription_id', $planId)
            ->update(['status' => 'canceled', 'ends_at' => now()]);
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'xendit' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->put(
            self::BASE_URL.'/recurring/plans/'.$subscription->gateway_subscription_id,
            ['status' => 'INACTIVATED']
        );

        if ($res->successful()) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        Log::error('Xendit cancel failed', ['subscription_id' => $subscription->id, 'body' => $res->json()]);

        return false;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'xendit' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->get(self::BASE_URL.'/recurring/plans/'.$subscription->gateway_subscription_id);
        if (! $res->successful()) {
            return false;
        }

        $subscription->update([
            'status' => $this->mapStatus($res->json('status', '')),
        ]);

        return true;
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'ACTIVE' => 'active',
            'INACTIVE', 'INACTIVATED', 'STOPPED' => 'canceled',
            'PENDING' => 'incomplete',
            default => 'incomplete',
        };
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        return ['ok' => false, 'error' => 'Plan changes for Xendit require cancelling and re-subscribing.'];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Xendit is not configured.'];
        }

        $paymentId = $transaction->gateway_transaction_id;
        if (! $paymentId) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        $body = [
            'payment_id' => $paymentId,
            'reason' => 'REQUESTED_BY_CUSTOMER',
        ];
        if ($amountCents) {
            $body['amount'] = $amountCents;
        }

        $res = $this->http()->post(self::BASE_URL.'/refunds', $body);

        if (! $res->successful()) {
            Log::error('Xendit refund failed', ['transaction_id' => $transaction->id, 'body' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('message', 'Xendit refund failed.')];
        }

        $transaction->update([
            'refunded_at' => now(),
            'refunded_cents' => $amountCents ?? $transaction->amount_cents,
            'status' => 'refunded',
        ]);

        return ['ok' => true, 'error' => null];
    }

    public function fulfillCheckoutSession(string $sessionId): array
    {
        return ['ok' => false, 'error' => 'Xendit fulfillment is handled via webhook.'];
    }

    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Xendit: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
