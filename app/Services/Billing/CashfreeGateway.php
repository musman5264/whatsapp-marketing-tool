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
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashfree recurring billing via the Subscriptions API (x-api-version 2025-01-01).
 *
 * Unlike Stripe/PayPal/Razorpay, Cashfree subscriptions do NOT return a hosted redirect
 * URL — they return a `subscription_session_id` that must be handed to the Cashfree JS
 * SDK on the front end. So createCheckout() returns a `checkout` envelope (not a `url`);
 * the CheckoutController renders the client/Checkout/Sdk page which loads the SDK and
 * launches the mandate-authorization flow. After authorization Cashfree redirects the
 * customer to the `return_url` and the subscription is activated by webhook.
 *
 * Amounts are in MAJOR currency units (rupees, decimals) — NOT the smallest unit.
 * Webhook signatures are HMAC-SHA256(base64) over `timestamp + rawBody` keyed by the
 * client secret.
 */
class CashfreeGateway implements BillingGatewayInterface
{
    private const API_VERSION = '2025-01-01';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private bool $sandbox,
        private string $returnUrl,
    ) {}

    public function name(): string
    {
        return 'Cashfree';
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    private function baseUrl(): string
    {
        return $this->sandbox
            ? 'https://sandbox.cashfree.com/pg'
            : 'https://api.cashfree.com/pg';
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'x-client-id' => $this->clientId,
            'x-client-secret' => $this->clientSecret,
            'x-api-version' => self::API_VERSION,
        ])->acceptJson()->asJson();
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Cashfree is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $amount = round($priceCents / 100, 2); // rupees (major units)
        $currency = strtoupper($plan->currency_code ?? 'INR');
        $intervalType = $billingCycle === 'year' ? 'YEAR' : 'MONTH';
        $maxCycles = $billingCycle === 'year' ? 10 : 120;
        $subscriptionId = 'sub_'.$user->id.'_'.Str::lower(Str::random(18));

        $body = [
            'subscription_id' => $subscriptionId,
            'customer_details' => [
                'customer_id' => 'cust_'.$user->id,
                'customer_name' => $user->name ?: ('User '.$user->id),
                'customer_email' => $user->email,
                'customer_phone' => $user->phone ?? '9999999999',
            ],
            'plan_details' => [
                'plan_name' => Str::limit($plan->name, 38, ''),
                'plan_type' => 'PERIODIC',
                'plan_currency' => $currency,
                'plan_amount' => $amount,
                'plan_max_amount' => $amount,
                'plan_max_cycles' => $maxCycles,
                'plan_intervals' => 1,
                'plan_interval_type' => $intervalType,
            ],
            'authorization_details' => [
                'authorization_amount' => $amount,
                'payment_methods' => ['upi', 'enach', 'card'],
            ],
            'subscription_meta' => [
                'return_url' => $this->returnUrl,
                'notification_channel' => ['EMAIL'],
            ],
            'subscription_tags' => [
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'billing_cycle' => $billingCycle,
            ],
        ];

        $res = $this->http()->post($this->baseUrl().'/subscriptions', $body);

        if (! $res->successful()) {
            Log::error('Cashfree create subscription failed', ['body' => $res->json(), 'user_id' => $user->id]);

            return ['error' => $res->json('message', 'Cashfree subscription creation failed.')];
        }

        $sessionId = $res->json('subscription_session_id');
        if (! $sessionId) {
            return ['error' => 'No subscription session in Cashfree response.'];
        }

        return ['checkout' => [
            'provider' => 'cashfree',
            'session_id' => $sessionId,
            'mode' => $this->sandbox ? 'sandbox' : 'production',
        ]];
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $timestamp = $request->header('x-webhook-timestamp', '');
        $signature = $request->header('x-webhook-signature', '');

        if ($this->clientSecret) {
            $expected = base64_encode(hash_hmac('sha256', $timestamp.$payload, $this->clientSecret, true));
            if ($signature === '' || ! hash_equals($expected, $signature)) {
                Log::warning('Cashfree webhook signature verification failed');

                return new Response('Invalid signature', 401);
            }
        } elseif (app()->environment('production')) {
            Log::warning('Cashfree webhook secret not configured in production');

            return new Response('Webhook secret not configured', 401);
        }

        $data = json_decode($payload, true) ?: [];
        $type = $data['type'] ?? '';
        $eventId = $this->eventId($data, $timestamp);

        if ($eventId && ! app(WebhookIdempotencyService::class)->isNewEvent('cashfree', $eventId)) {
            return new Response('OK', 200);
        }

        try {
            match ($type) {
                'SUBSCRIPTION_STATUS_CHANGE' => $this->handleStatusChange($data),
                'SUBSCRIPTION_PAYMENT_SUCCESS', 'SUBSCRIPTION_NEW_PAYMENT' => $this->handlePaymentSuccess($data),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Cashfree webhook handler failed', ['type' => $type, 'error' => $e->getMessage()]);
            if ($eventId) {
                app(WebhookIdempotencyService::class)->release('cashfree', $eventId);
            }

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    private function handleStatusChange(array $data): void
    {
        $sub = $data['data']['subscription_details'] ?? [];
        $subId = $sub['subscription_id'] ?? '';
        if (! $subId) {
            return;
        }

        $status = $this->mapStatus($sub['subscription_status'] ?? '');

        if ($status === 'canceled') {
            Subscription::where('gateway', 'cashfree')
                ->where('gateway_subscription_id', $subId)
                ->update(['status' => 'canceled', 'ends_at' => now()]);

            return;
        }

        if ($status === 'active') {
            $this->upsertSubscription($subId, $sub);

            return;
        }

        Subscription::where('gateway', 'cashfree')
            ->where('gateway_subscription_id', $subId)
            ->update(['status' => $status]);
    }

    private function handlePaymentSuccess(array $data): void
    {
        $d = $data['data'] ?? [];
        $subId = $d['cf_subscription_id']
            ?? ($d['subscription_id'] ?? ($d['subscription_details']['subscription_id'] ?? ''));
        $paymentId = (string) ($d['cf_payment_id'] ?? ($d['payment_id'] ?? ''));
        if (! $subId || ! $paymentId) {
            return;
        }

        $subscription = Subscription::where('gateway', 'cashfree')
            ->where('gateway_subscription_id', $subId)
            ->with('user', 'plan')
            ->first();
        if (! $subscription) {
            $subscription = $this->upsertSubscription($subId, ['subscription_id' => $subId]);
            $subscription?->loadMissing('user', 'plan');
        }

        if (PaymentTransaction::where('gateway', 'cashfree')->where('gateway_transaction_id', $paymentId)->exists()) {
            return;
        }

        $isRenewal = $subscription
            && PaymentTransaction::where('gateway', 'cashfree')
                ->where('subscription_id', $subscription->id)
                ->where('status', 'paid')
                ->exists();

        $amount = (float) ($d['payment_amount'] ?? 0);
        $amountCents = (int) round($amount * 100);
        $currency = strtoupper($d['payment_currency'] ?? ($subscription?->plan?->currency_code ?? 'INR'));

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription?->user_id,
            'subscription_id' => $subscription?->id,
            'gateway' => 'cashfree',
            'gateway_transaction_id' => $paymentId,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $d,
        ]);

        $this->generateInvoicePdf($transaction);

        if ($isRenewal && $subscription && $subscription->user && $subscription->plan) {
            $this->sync($subscription);
            $subscription->refresh()->loadMissing('user', 'plan');
            SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
        }
    }

    /** Create/refresh the local subscription, resolving our metadata from subscription_tags. */
    private function upsertSubscription(string $subId, array $subDetails): ?Subscription
    {
        $tags = $subDetails['subscription_tags'] ?? null;
        if (! is_array($tags) || ! isset($tags['user_id'])) {
            $tags = $this->fetchTags($subId);
        }

        $userId = (int) ($tags['user_id'] ?? 0);
        $planId = (int) ($tags['plan_id'] ?? 0);
        $billingCycle = $tags['billing_cycle'] ?? 'month';
        if (! $userId || ! $planId) {
            return null;
        }

        $isNew = ! Subscription::where('gateway', 'cashfree')
            ->where('gateway_subscription_id', $subId)
            ->exists();

        $nextCharge = $subDetails['subscription_first_charge_time']
            ?? ($subDetails['next_charge_date'] ?? null);

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'cashfree', 'gateway_subscription_id' => $subId],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => $nextCharge ? Carbon::parse($nextCharge) : null,
            ]
        );

        if ($isNew) {
            $user = User::find($userId);
            $plan = Plan::find($planId);
            if ($user && $plan) {
                SubscriptionStarted::dispatch($user, $subscription, $plan);
            }
        }

        return $subscription;
    }

    private function fetchTags(string $subId): array
    {
        try {
            $res = $this->http()->get($this->baseUrl().'/subscriptions/'.$subId);
            if ($res->successful()) {
                return $res->json('subscription_tags', []) ?: [];
            }
        } catch (\Throwable $e) {
            Log::warning('Cashfree fetchTags failed', ['sub_id' => $subId, 'error' => $e->getMessage()]);
        }

        return [];
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'cashfree' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->post($this->baseUrl().'/subscriptions/'.$subscription->gateway_subscription_id.'/manage', [
            'subscription_id' => $subscription->gateway_subscription_id,
            'action' => 'CANCEL',
        ]);

        if ($res->successful()) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        Log::error('Cashfree cancel failed', ['subscription_id' => $subscription->id, 'body' => $res->body()]);

        return false;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'cashfree' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->get($this->baseUrl().'/subscriptions/'.$subscription->gateway_subscription_id);
        if (! $res->successful()) {
            return false;
        }

        $next = $res->json('next_charge_date') ?? $res->json('subscription_first_charge_time');
        $subscription->update([
            'status' => $this->mapStatus($res->json('subscription_status', $subscription->status)),
            'renews_at' => $next ? Carbon::parse($next) : $subscription->renews_at,
        ]);

        return true;
    }

    /** Map Cashfree subscription statuses to the app's canonical status vocabulary. */
    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'ACTIVE' => 'active',
            'INITIALIZED', 'BANK_APPROVAL_PENDING' => 'incomplete',
            'ON_HOLD', 'CUSTOMER_PAUSED' => 'past_due',
            'CANCELLED', 'CUSTOMER_CANCELLED', 'COMPLETED', 'EXPIRED', 'LINK_EXPIRED' => 'canceled',
            default => strtolower($status),
        };
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        // Cashfree plan changes require a new mandate; not supported in-place.
        return ['ok' => false, 'error' => 'Plan changes for Cashfree require cancelling and re-subscribing.'];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Cashfree is not configured.'];
        }

        $subId = $transaction->subscription?->gateway_subscription_id;
        $paymentId = $transaction->gateway_transaction_id;
        if (! $subId || ! $paymentId) {
            return ['ok' => false, 'error' => 'Missing subscription or payment ID for refund.'];
        }

        $refundAmount = round(($amountCents ?? $transaction->amount_cents) / 100, 2);

        $res = $this->http()->post($this->baseUrl()."/subscriptions/{$subId}/refunds", [
            'subscription_id' => $subId,
            'cf_payment_id' => $paymentId,
            'refund_id' => 'rfnd_'.$transaction->id.'_'.now()->timestamp,
            'refund_amount' => $refundAmount,
            'refund_speed' => 'STANDARD',
        ]);

        if (! $res->successful()) {
            Log::error('Cashfree refund failed', ['transaction_id' => $transaction->id, 'response' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('message') ?? 'Cashfree refund failed.'];
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
        return ['ok' => false, 'error' => 'Cashfree fulfillment is handled via webhook.'];
    }

    /** Build a stable idempotency key from the event's payment/subscription id + timestamp. */
    private function eventId(array $data, string $timestamp): ?string
    {
        $d = $data['data'] ?? [];
        $type = $data['type'] ?? 'event';
        $id = $d['cf_payment_id']
            ?? ($d['payment_id']
                ?? ($d['subscription_details']['cf_subscription_id']
                    ?? ($d['subscription_details']['subscription_id']
                        ?? ($d['cf_subscription_id'] ?? ''))));

        if (! $id) {
            return $timestamp ? $type.'_'.md5($timestamp) : null;
        }

        return $type.'_'.$id.'_'.$timestamp;
    }

    /** Best-effort PDF invoice generation; never let it break webhook processing. */
    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Cashfree: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
