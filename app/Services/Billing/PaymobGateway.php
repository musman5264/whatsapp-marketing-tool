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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paymob recurring billing via card tokenization (merchant-initiated transactions).
 *
 * Supported countries: Egypt, Saudi Arabia, UAE, Jordan, Pakistan, Morocco.
 *
 * Flow:
 *   1. Authenticate against Paymob API to get a short-lived auth token.
 *   2. Register an order → obtain an order ID.
 *   3. Request a payment key (tokenized) for that order.
 *   4. Redirect customer to Paymob hosted checkout using the payment key.
 *   5. On first successful payment, Paymob sends a webhook containing a
 *      card `token` → save to subscription gateway_metadata.
 *   6. Renewals: the billing:charge-recurring-paymob scheduler uses the saved
 *      token to charge the card off-session (no customer interaction).
 *
 * Webhook verification: HMAC-SHA512 computed from a fixed set of transaction
 * fields concatenated in alphabetical order, keyed by `PAYMOB_HMAC_SECRET`.
 *
 * The `integration_id` is the Paymob card-payment integration ID configured
 * in your Paymob dashboard → Developers → Integrations.
 *
 * Amounts are in the smallest currency subunit (piastres for EGP, halalas for SAR).
 */
class PaymobGateway implements BillingGatewayInterface
{
    private const BASE_URL = 'https://accept.paymob.com';

    /** Fields used in Paymob HMAC verification, in alphabetical order. */
    private const HMAC_FIELDS = [
        'amount_cents', 'created_at', 'currency', 'error_occured',
        'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
        'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
        'is_voided', 'order.id', 'owner', 'pending',
        'source_data.pan', 'source_data.sub_type', 'source_data.type',
        'success',
    ];

    public function __construct(
        private string $apiKey,
        private string $hmacSecret,
        private int $integrationId,
        private string $iframeId,
        private string $successUrl,
        private string $cancelUrl,
    ) {}

    public function name(): string
    {
        return 'Paymob';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->integrationId > 0;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->asJson();
    }

    /** Step 1: Authenticate and return a short-lived auth token. */
    private function authToken(): ?string
    {
        $res = $this->http()->post(self::BASE_URL.'/api/auth/tokens', [
            'api_key' => $this->apiKey,
        ]);

        if (! $res->successful()) {
            Log::error('Paymob auth failed', ['body' => $res->json()]);

            return null;
        }

        return $res->json('token');
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Paymob is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($plan->currency_code ?? 'EGP');

        // 1) Auth token.
        $authToken = $this->authToken();
        if (! $authToken) {
            return ['error' => 'Paymob authentication failed.'];
        }

        // 2) Register order.
        $orderRes = $this->http()->post(self::BASE_URL.'/api/ecommerce/orders', [
            'auth_token' => $authToken,
            'delivery_needed' => false,
            'amount_cents' => (string) $priceCents,
            'currency' => $currency,
            'merchant_order_id' => 'sub_'.$user->id.'_'.time(),
            'items' => [
                [
                    'name' => $plan->name.' ('.$billingCycle.')',
                    'amount_cents' => (string) $priceCents,
                    'description' => $plan->name.' subscription',
                    'quantity' => 1,
                ],
            ],
        ]);

        if (! $orderRes->successful()) {
            Log::error('Paymob create order failed', ['body' => $orderRes->json(), 'user_id' => $user->id]);

            return ['error' => $orderRes->json('message', 'Paymob order creation failed.')];
        }

        $orderId = $orderRes->json('id');
        if (! $orderId) {
            return ['error' => 'No order ID in Paymob response.'];
        }

        // 3) Request payment key.
        $name = $user->name ?? 'Customer';
        $nameParts = explode(' ', trim($name), 2);

        $paymentKeyRes = $this->http()->post(self::BASE_URL.'/api/acceptance/payment_keys', [
            'auth_token' => $authToken,
            'amount_cents' => (string) $priceCents,
            'expiration' => 3600,
            'order_id' => $orderId,
            'currency' => $currency,
            'integration_id' => $this->integrationId,
            'billing_data' => [
                'first_name' => $nameParts[0],
                'last_name' => $nameParts[1] ?? 'N/A',
                'email' => $user->email ?? 'NA',
                'phone_number' => 'NA',
                'apartment' => 'NA',
                'floor' => 'NA',
                'street' => 'NA',
                'building' => 'NA',
                'shipping_method' => 'NA',
                'postal_code' => 'NA',
                'city' => 'NA',
                'country' => 'NA',
                'state' => 'NA',
            ],
            'lock_order_when_paid' => true,
            'redirection_url' => $this->successUrl,
            'user_metadata' => [
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'billing_cycle' => $billingCycle,
            ],
        ]);

        if (! $paymentKeyRes->successful()) {
            Log::error('Paymob payment key failed', ['body' => $paymentKeyRes->json(), 'user_id' => $user->id]);

            return ['error' => $paymentKeyRes->json('message', 'Paymob payment key request failed.')];
        }

        $paymentToken = $paymentKeyRes->json('token');
        if (! $paymentToken) {
            return ['error' => 'No payment token in Paymob response.'];
        }

        // 4) Build hosted checkout URL.
        $url = self::BASE_URL.'/api/acceptance/iframes/'.$this->iframeId.'?payment_token='.$paymentToken;

        return ['url' => $url, 'order_id' => $orderId];
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $data = json_decode($payload, true) ?: [];
        $type = $data['type'] ?? '';

        // Only process transaction notifications.
        if ($type !== 'TRANSACTION') {
            return new Response('OK', 200);
        }

        $obj = $data['obj'] ?? [];
        $txId = (string) ($obj['id'] ?? '');

        // Verify HMAC — mandatory in production; log warning and reject if secret not configured.
        if ($this->hmacSecret === '' && app()->environment('production')) {
            Log::warning('Paymob webhook: PAYMOB_HMAC_SECRET not configured in production — rejecting request');

            return new Response('HMAC secret not configured', 401);
        }
        if ($this->hmacSecret !== '' && ! $this->verifyHmac($request, $obj)) {
            Log::warning('Paymob webhook HMAC verification failed');

            return new Response('Invalid signature', 401);
        }

        if ($txId && ! app(WebhookIdempotencyService::class)->isNewEvent('paymob', $txId)) {
            return new Response('OK', 200);
        }

        try {
            $this->handleTransaction($obj);
        } catch (\Throwable $e) {
            Log::error('Paymob webhook handler failed', ['tx_id' => $txId, 'error' => $e->getMessage()]);
            if ($txId) {
                app(WebhookIdempotencyService::class)->release('paymob', $txId);
            }

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    private function verifyHmac(Request $request, array $obj): bool
    {
        // Paymob HMAC is computed from query string `hmac` parameter.
        $receivedHmac = $request->query('hmac', '');
        if ($receivedHmac === '') {
            return false;
        }

        $str = '';
        foreach (self::HMAC_FIELDS as $field) {
            $value = data_get($obj, $field, '');
            $str .= ($value === true || $value === 'true') ? 'true' : (($value === false || $value === 'false') ? 'false' : (string) $value);
        }

        $expected = hash_hmac('sha512', $str, $this->hmacSecret);

        return hash_equals($expected, strtolower($receivedHmac));
    }

    private function handleTransaction(array $obj): void
    {
        $success = (bool) ($obj['success'] ?? false);
        $isVoided = (bool) ($obj['is_voided'] ?? false);
        $isRefunded = (bool) ($obj['is_refunded'] ?? false);
        $txId = (string) ($obj['id'] ?? '');
        $token = $obj['token'] ?? null; // Card token for future MIT charges.
        $orderId = (string) ($obj['order']['id'] ?? '');

        if (! $success || $isVoided || $isRefunded || ! $txId) {
            return;
        }

        // Recover user/plan from the order's merchant_order_id or user_metadata.
        $userMetadata = $obj['payment_key_claims']['user_metadata'] ?? [];
        $userId = (int) ($userMetadata['user_id'] ?? 0);
        $planId = (int) ($userMetadata['plan_id'] ?? 0);
        $billingCycle = $userMetadata['billing_cycle'] ?? 'month';

        if (! $userId || ! $planId) {
            Log::warning('Paymob webhook: missing user_id/plan_id in user_metadata', ['tx_id' => $txId]);

            return;
        }

        $amountCents = (int) ($obj['amount_cents'] ?? 0);
        $currency = strtoupper($obj['currency'] ?? 'EGP');

        // Upsert subscription — use order_id as stable subscription identifier.
        $subRefId = 'paymob_order_'.$orderId;
        $isNew = ! Subscription::where('gateway', 'paymob')
            ->where('gateway_subscription_id', $subRefId)
            ->exists();

        $meta = ['currency' => $currency, 'order_id' => $orderId, 'integration_id' => $this->integrationId];
        if ($token) {
            $meta['card_token'] = $token;
        }

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'paymob', 'gateway_subscription_id' => $subRefId],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => $billingCycle === 'year' ? now()->addYear() : now()->addMonth(),
                'gateway_metadata' => $meta,
            ]
        );

        // Merge token if it arrived on a later webhook.
        if ($token && empty($subscription->gateway_metadata['card_token'])) {
            $subscription->update(['gateway_metadata' => array_merge($subscription->gateway_metadata ?? [], ['card_token' => $token])]);
        }

        if (PaymentTransaction::where('gateway', 'paymob')->where('gateway_transaction_id', $txId)->exists()) {
            return;
        }

        $isRenewal = ! $isNew || PaymentTransaction::where('gateway', 'paymob')
            ->where('subscription_id', $subscription->id)
            ->where('status', 'paid')
            ->exists();

        $transaction = PaymentTransaction::create([
            'user_id' => $userId,
            'subscription_id' => $subscription->id,
            'gateway' => 'paymob',
            'gateway_transaction_id' => $txId,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $obj,
        ]);

        $this->generateInvoicePdf($transaction);

        if ($isNew) {
            $user = User::find($userId);
            $plan = Plan::find($planId);
            if ($user && $plan) {
                SubscriptionStarted::dispatch($user, $subscription, $plan);
            }
        } elseif ($isRenewal) {
            $subscription->refresh()->loadMissing('user', 'plan');
            if ($subscription->user && $subscription->plan) {
                SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
            }
        }
    }

    /**
     * Charge the saved card token off-session (merchant-initiated renewal).
     * Called by billing:charge-recurring-paymob scheduler.
     */
    public function chargeRecurring(Subscription $subscription): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Paymob is not configured.'];
        }

        $meta = $subscription->gateway_metadata ?? [];
        $cardToken = $meta['card_token'] ?? null;
        $currency = $meta['currency'] ?? 'EGP';

        if (! $cardToken) {
            return ['ok' => false, 'error' => 'No saved card token for recurring charge.'];
        }

        $plan = $subscription->plan ?? Plan::find($subscription->plan_id);
        if (! $plan) {
            return ['ok' => false, 'error' => 'Plan not found.'];
        }

        $priceCents = $plan->priceCentsForCycle($subscription->billing_cycle ?? 'month');
        if ($priceCents === null || $priceCents <= 0) {
            return ['ok' => false, 'error' => 'Plan has no price for this billing cycle.'];
        }

        // Auth.
        $authToken = $this->authToken();
        if (! $authToken) {
            return ['ok' => false, 'error' => 'Paymob auth failed.'];
        }

        // Register a new order for this renewal.
        $user = $subscription->user ?? User::find($subscription->user_id);
        $orderRes = $this->http()->post(self::BASE_URL.'/api/ecommerce/orders', [
            'auth_token' => $authToken,
            'delivery_needed' => false,
            'amount_cents' => (string) $priceCents,
            'currency' => $currency,
            'merchant_order_id' => 'renewal_'.$subscription->id.'_'.time(),
            'items' => [],
        ]);

        if (! $orderRes->successful()) {
            return ['ok' => false, 'error' => 'Paymob order creation for renewal failed.'];
        }

        $orderId = $orderRes->json('id');

        // Request payment key with saved token.
        $name = $user?->name ?? 'Customer';
        $nameParts = explode(' ', trim($name), 2);

        $pkRes = $this->http()->post(self::BASE_URL.'/api/acceptance/payment_keys', [
            'auth_token' => $authToken,
            'amount_cents' => (string) $priceCents,
            'expiration' => 3600,
            'order_id' => $orderId,
            'currency' => $currency,
            'integration_id' => $this->integrationId,
            'token' => $cardToken,
            'billing_data' => [
                'first_name' => $nameParts[0],
                'last_name' => $nameParts[1] ?? 'N/A',
                'email' => $user?->email ?? 'NA',
                'phone_number' => 'NA',
                'apartment' => 'NA', 'floor' => 'NA', 'street' => 'NA',
                'building' => 'NA', 'shipping_method' => 'NA',
                'postal_code' => 'NA', 'city' => 'NA', 'country' => 'NA', 'state' => 'NA',
            ],
            'user_metadata' => [
                'user_id' => (string) $subscription->user_id,
                'plan_id' => (string) $subscription->plan_id,
                'billing_cycle' => $subscription->billing_cycle,
            ],
        ]);

        if (! $pkRes->successful()) {
            return ['ok' => false, 'error' => 'Paymob payment key for renewal failed.'];
        }

        $paymentToken = $pkRes->json('token');
        if (! $paymentToken) {
            return ['ok' => false, 'error' => 'No payment token in Paymob renewal response.'];
        }

        // Execute the MIT charge (pay with saved token, no 3DS).
        $chargeRes = $this->http()->post(self::BASE_URL.'/api/acceptance/payments/pay', [
            'source' => [
                'identifier' => $cardToken,
                'subtype' => 'TOKEN',
            ],
            'payment_token' => $paymentToken,
        ]);

        if (! $chargeRes->successful() || ! ($chargeRes->json('success') ?? false)) {
            Log::error('Paymob MIT charge failed', [
                'subscription_id' => $subscription->id,
                'body' => $chargeRes->json(),
            ]);

            return ['ok' => false, 'error' => $chargeRes->json('message', 'Paymob recurring charge failed.')];
        }

        $txId = (string) ($chargeRes->json('id') ?? '');
        $amountCents = (int) ($chargeRes->json('amount_cents') ?? $priceCents);

        if ($txId && PaymentTransaction::where('gateway', 'paymob')->where('gateway_transaction_id', $txId)->exists()) {
            return ['ok' => true, 'error' => null];
        }

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'gateway' => 'paymob',
            'gateway_transaction_id' => $txId,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $chargeRes->json(),
        ]);

        $this->generateInvoicePdf($transaction);

        $subscription->update([
            'renews_at' => ($subscription->billing_cycle ?? 'month') === 'year' ? now()->addYear() : now()->addMonth(),
        ]);

        $subscription->refresh()->loadMissing('user', 'plan');
        if ($subscription->user && $subscription->plan) {
            SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
        }

        return ['ok' => true, 'error' => null];
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'paymob') {
            return false;
        }
        // Paymob MIT has no server-side subscription object to cancel; just stop scheduling.
        $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

        return true;
    }

    public function sync(Subscription $subscription): bool
    {
        // No server-side subscription object to sync for MIT pattern.
        return $subscription->gateway === 'paymob';
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        // Apply on next cycle; the recurring charge reads the live plan price.
        $subscription->update(['plan_id' => $newPlan->id, 'billing_cycle' => $billingCycle]);

        return ['ok' => true, 'error' => null];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Paymob is not configured.'];
        }

        $txId = $transaction->gateway_transaction_id;
        if (! $txId) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        $authToken = $this->authToken();
        if (! $authToken) {
            return ['ok' => false, 'error' => 'Paymob auth failed for refund.'];
        }

        $cents = $amountCents ?? $transaction->amount_cents;

        $res = $this->http()->post(self::BASE_URL.'/api/acceptance/void_refund/refund', [
            'auth_token' => $authToken,
            'transaction_id' => $txId,
            'amount_cents' => $cents,
        ]);

        if (! $res->successful()) {
            Log::error('Paymob refund failed', ['transaction_id' => $transaction->id, 'body' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('message', 'Paymob refund failed.')];
        }

        $transaction->update([
            'refunded_at' => now(),
            'refunded_cents' => $cents,
            'status' => 'refunded',
        ]);

        return ['ok' => true, 'error' => null];
    }

    public function fulfillCheckoutSession(string $sessionId): array
    {
        return ['ok' => false, 'error' => 'Paymob fulfillment is handled via webhook.'];
    }

    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Paymob: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
