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
 * Razorpay recurring billing via the Subscriptions API.
 *
 * Flow: create a Plan → create a Subscription (returns a hosted `short_url` the customer
 * is redirected to for mandate authorization) → Razorpay charges each cycle and notifies
 * us by webhook. All amounts are in the smallest currency unit (paise for INR), which
 * matches the app's `amount_cents` convention 1:1.
 *
 * Two distinct secrets are involved and must not be confused:
 *   - key_secret      → HTTP Basic auth + the redirect-return signature.
 *   - webhook secret  → the X-Razorpay-Signature HMAC on inbound webhooks.
 */
class RazorpayGateway implements BillingGatewayInterface
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function __construct(
        private string $keyId,
        private string $keySecret,
        private string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'Razorpay';
    }

    public function isConfigured(): bool
    {
        return $this->keyId !== '' && $this->keySecret !== '';
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->keyId, $this->keySecret)
            ->acceptJson()
            ->asJson();
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Razorpay is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($plan->currency_code ?? 'INR');
        $period = $billingCycle === 'year' ? 'yearly' : 'monthly';
        // Razorpay requires a finite cycle count; use a long horizon to emulate open-ended.
        $totalCount = $billingCycle === 'year' ? 10 : 120;

        // 1) Create a plan (item.amount in paise).
        $planRes = $this->http()->post(self::BASE_URL.'/plans', [
            'period' => $period,
            'interval' => 1,
            'item' => [
                'name' => $plan->name,
                'amount' => $priceCents,
                'currency' => $currency,
            ],
            'notes' => [
                'plan_id' => (string) $plan->id,
                'billing_cycle' => $billingCycle,
            ],
        ]);

        if (! $planRes->successful()) {
            Log::error('Razorpay create plan failed', ['body' => $planRes->json(), 'user_id' => $user->id]);

            return ['error' => $planRes->json('error.description', 'Razorpay plan creation failed.')];
        }

        $razorpayPlanId = $planRes->json('id');
        if (! $razorpayPlanId) {
            return ['error' => 'No plan ID in Razorpay response.'];
        }

        // 2) Create a subscription → short_url is the hosted authorization page.
        $body = [
            'plan_id' => $razorpayPlanId,
            'total_count' => $totalCount,
            'quantity' => 1,
            'customer_notify' => 1,
            'notes' => [
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'billing_cycle' => $billingCycle,
            ],
        ];

        // A free trial delays the first charge.
        if (($plan->trial_days ?? 0) > 0) {
            $body['start_at'] = now()->addDays((int) $plan->trial_days)->getTimestamp();
        }

        $subRes = $this->http()->post(self::BASE_URL.'/subscriptions', $body);

        if (! $subRes->successful()) {
            Log::error('Razorpay create subscription failed', ['body' => $subRes->json(), 'user_id' => $user->id]);

            return ['error' => $subRes->json('error.description', 'Razorpay subscription creation failed.')];
        }

        $url = $subRes->json('short_url');
        if (! $url) {
            return ['error' => 'No checkout URL in Razorpay response.'];
        }

        return ['url' => $url, 'subscription_id' => $subRes->json('id')];
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();

        // Signature verification is mandatory whenever a webhook secret is configured.
        if ($this->webhookSecret) {
            $sig = $request->header('X-Razorpay-Signature', '');
            $expected = hash_hmac('sha256', $payload, $this->webhookSecret);
            if ($sig === '' || ! hash_equals($expected, $sig)) {
                Log::warning('Razorpay webhook signature verification failed');

                return new Response('Invalid signature', 401);
            }
        } elseif (app()->environment('production')) {
            Log::warning('Razorpay webhook secret not configured in production');

            return new Response('Webhook secret not configured', 401);
        }

        $data = json_decode($payload, true) ?: [];
        $event = $data['event'] ?? '';
        $eventId = $request->header('X-Razorpay-Event-Id') ?: ($data['id'] ?? null);

        if ($eventId && ! app(WebhookIdempotencyService::class)->isNewEvent('razorpay', $eventId)) {
            return new Response('OK', 200);
        }

        try {
            match ($event) {
                'subscription.activated', 'subscription.authenticated', 'subscription.resumed' => $this->handleSubscriptionActivated($data),
                'subscription.charged' => $this->handleSubscriptionCharged($data),
                'subscription.cancelled', 'subscription.completed', 'subscription.expired' => $this->handleSubscriptionEnded($data, 'canceled'),
                'subscription.halted', 'subscription.pending', 'subscription.paused' => $this->handleSubscriptionEnded($data, 'past_due'),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Razorpay webhook handler failed', ['event' => $event, 'error' => $e->getMessage()]);
            // Release the idempotency lock so Razorpay's automatic retry can reprocess.
            if ($eventId) {
                app(WebhookIdempotencyService::class)->release('razorpay', $eventId);
            }

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    private function handleSubscriptionActivated(array $data): void
    {
        $entity = $data['payload']['subscription']['entity'] ?? [];
        $subId = $entity['id'] ?? '';
        $notes = $entity['notes'] ?? [];
        $userId = (int) ($notes['user_id'] ?? 0);
        $planId = (int) ($notes['plan_id'] ?? 0);
        $billingCycle = $notes['billing_cycle'] ?? 'month';
        if (! $subId || ! $userId || ! $planId) {
            return;
        }

        $isNew = ! Subscription::where('gateway', 'razorpay')
            ->where('gateway_subscription_id', $subId)
            ->exists();

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'razorpay', 'gateway_subscription_id' => $subId],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => $this->mapStatus($entity['status'] ?? 'active'),
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => isset($entity['current_end']) ? Carbon::createFromTimestamp($entity['current_end']) : null,
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

    private function handleSubscriptionCharged(array $data): void
    {
        $subEntity = $data['payload']['subscription']['entity'] ?? [];
        $payEntity = $data['payload']['payment']['entity'] ?? [];
        $subId = $subEntity['id'] ?? '';
        $paymentId = $payEntity['id'] ?? null;
        if (! $subId || ! $paymentId) {
            return;
        }

        // The charge event can arrive before/without an activation event — ensure the
        // local subscription exists (idempotent upsert) before recording the payment.
        $this->handleSubscriptionActivated($data);

        $subscription = Subscription::where('gateway', 'razorpay')
            ->where('gateway_subscription_id', $subId)
            ->with('user', 'plan')
            ->first();

        if (PaymentTransaction::where('gateway', 'razorpay')->where('gateway_transaction_id', $paymentId)->exists()) {
            return;
        }

        // A prior paid transaction means this is a recurring renewal, not the first charge.
        $isRenewal = $subscription
            && PaymentTransaction::where('gateway', 'razorpay')
                ->where('subscription_id', $subscription->id)
                ->where('status', 'paid')
                ->exists();

        $amountCents = (int) ($payEntity['amount'] ?? 0);
        $currency = strtoupper($payEntity['currency'] ?? 'INR');

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription?->user_id,
            'subscription_id' => $subscription?->id,
            'gateway' => 'razorpay',
            'gateway_transaction_id' => $paymentId,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $payEntity,
        ]);

        $this->generateInvoicePdf($transaction);

        if ($subscription && isset($subEntity['current_end'])) {
            $subscription->update(['renews_at' => Carbon::createFromTimestamp($subEntity['current_end'])]);
        }

        if ($isRenewal && $subscription && $subscription->user && $subscription->plan) {
            $subscription->refresh()->loadMissing('user', 'plan');
            SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
        }
    }

    private function handleSubscriptionEnded(array $data, string $status): void
    {
        $subId = $data['payload']['subscription']['entity']['id'] ?? '';
        if (! $subId) {
            return;
        }
        $update = ['status' => $status];
        if ($status === 'canceled') {
            $update['ends_at'] = now();
        }
        Subscription::where('gateway', 'razorpay')
            ->where('gateway_subscription_id', $subId)
            ->update($update);
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'razorpay' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->post(self::BASE_URL.'/subscriptions/'.$subscription->gateway_subscription_id.'/cancel', [
            'cancel_at_cycle_end' => 0,
        ]);

        if ($res->successful()) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        Log::error('Razorpay cancel failed', ['subscription_id' => $subscription->id, 'body' => $res->body()]);

        return false;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'razorpay' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->get(self::BASE_URL.'/subscriptions/'.$subscription->gateway_subscription_id);
        if (! $res->successful()) {
            return false;
        }

        $currentEnd = $res->json('current_end');
        $subscription->update([
            'status' => $this->mapStatus($res->json('status', $subscription->status)),
            'renews_at' => $currentEnd ? Carbon::createFromTimestamp($currentEnd) : $subscription->renews_at,
        ]);

        return true;
    }

    /** Map Razorpay subscription statuses to the app's canonical status vocabulary. */
    private function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'active', 'authenticated' => 'active',
            'created', 'pending' => 'incomplete',
            'halted', 'paused' => 'past_due',
            'cancelled', 'completed', 'expired' => 'canceled',
            default => strtolower($status),
        };
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        // Razorpay subscription plan changes require a fresh mandate authorization.
        return ['ok' => false, 'error' => 'Plan changes for Razorpay require cancelling and re-subscribing.'];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Razorpay is not configured.'];
        }

        $paymentId = $transaction->gateway_transaction_id;
        if (! $paymentId) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        $body = [];
        if ($amountCents) {
            $body['amount'] = $amountCents; // paise
        }

        $res = $this->http()->post(self::BASE_URL."/payments/{$paymentId}/refund", $body);

        if (! $res->successful()) {
            Log::error('Razorpay refund failed', ['transaction_id' => $transaction->id, 'response' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('error.description') ?? 'Razorpay refund failed.'];
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
        return ['ok' => false, 'error' => 'Razorpay fulfillment is handled via webhook.'];
    }

    /** Best-effort PDF invoice generation; never let it break webhook processing. */
    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Razorpay: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
