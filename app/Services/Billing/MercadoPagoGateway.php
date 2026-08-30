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
 * Mercado Pago recurring billing via the Preapproval (subscriptions) API.
 * Covers Latin America: Brazil, Argentina, Mexico, Chile, Colombia, Peru, Uruguay.
 *
 * Flow:
 *   1. Create a Preapproval (POST /preapproval) with the recurring amount + interval
 *      and status "pending". Mercado Pago returns an `init_point` hosted page.
 *   2. The payer authorizes and attaches a payment method → the preapproval becomes
 *      "authorized" and Mercado Pago charges each interval automatically.
 *   3. Each recurring charge is exposed as an "authorized payment" and notified via
 *      webhook (topic=subscription_authorized_payment); the preapproval lifecycle is
 *      notified via topic=subscription_preapproval.
 *
 * Webhook security: if a signing secret is configured, the `x-signature` header
 * (ts + v1 HMAC-SHA256 over "id:<data.id>;request-id:<x-request-id>;ts:<ts>;") is
 * verified. Otherwise every resource is re-fetched from the API before we trust it.
 *
 * Amounts: Mercado Pago uses MAJOR currency units (e.g. 10.00), unlike most gateways
 * here. We divide the app's `amount_cents` by 100.
 */
class MercadoPagoGateway implements BillingGatewayInterface
{
    private const BASE_URL = 'https://api.mercadopago.com';

    public function __construct(
        private string $accessToken,
        private string $webhookSecret,
        private string $successUrl,
        private string $cancelUrl,
    ) {}

    public function name(): string
    {
        return 'Mercado Pago';
    }

    public function isConfigured(): bool
    {
        return $this->accessToken !== '';
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->accessToken)
            ->acceptJson()
            ->asJson();
    }

    private function notificationUrl(): string
    {
        return rtrim(config('app.url', ''), '/').'/webhooks/mercadopago';
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Mercado Pago is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($plan->currency_code ?? 'BRL');
        $amount = round($priceCents / 100, 2);

        $autoRecurring = [
            'frequency' => $billingCycle === 'year' ? 12 : 1,
            'frequency_type' => 'months',
            'transaction_amount' => $amount,
            'currency_id' => $currency,
        ];

        if (($plan->trial_days ?? 0) > 0) {
            $autoRecurring['free_trial'] = [
                'frequency' => (int) $plan->trial_days,
                'frequency_type' => 'days',
            ];
        }

        $body = [
            'reason' => $plan->name.' ('.$billingCycle.')',
            'external_reference' => 'user_'.$user->id.'_plan_'.$plan->id.'_'.$billingCycle,
            'payer_email' => $user->email ?? ('user'.$user->id.'@placeholder.invalid'),
            'auto_recurring' => $autoRecurring,
            'back_url' => $this->successUrl,
            'notification_url' => $this->notificationUrl(),
            'status' => 'pending',
        ];

        $res = $this->http()->post(self::BASE_URL.'/preapproval', $body);

        if (! $res->successful()) {
            Log::error('Mercado Pago create preapproval failed', ['body' => $res->json(), 'user_id' => $user->id]);

            return ['error' => $res->json('message', 'Mercado Pago checkout creation failed.')];
        }

        $url = $res->json('init_point') ?? $res->json('sandbox_init_point');
        if (! $url) {
            return ['error' => 'No init_point in Mercado Pago response.'];
        }

        return ['url' => $url];
    }

    public function handleWebhook(Request $request): Response
    {
        $type = $request->input('type', $request->input('topic', $request->query('type', $request->query('topic', ''))));
        $dataId = $request->input('data.id', $request->query('data.id', $request->query('id', '')));

        if (! $dataId) {
            return new Response('OK', 200);
        }

        if (! $this->verifySignature($request, (string) $dataId)) {
            Log::warning('Mercado Pago webhook signature verification failed');

            return new Response('Invalid signature', 401);
        }

        if (! app(WebhookIdempotencyService::class)->isNewEvent('mercadopago', $type.'_'.$dataId)) {
            return new Response('OK', 200);
        }

        try {
            match ($type) {
                'subscription_preapproval', 'preapproval' => $this->handlePreapproval((string) $dataId),
                'subscription_authorized_payment' => $this->handleAuthorizedPayment((string) $dataId),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Mercado Pago webhook handler failed', ['type' => $type, 'error' => $e->getMessage()]);
            app(WebhookIdempotencyService::class)->release('mercadopago', $type.'_'.$dataId);

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    /**
     * Verify the x-signature header when a signing secret is configured.
     * Manifest: "id:<data.id>;request-id:<x-request-id>;ts:<ts>;"
     */
    private function verifySignature(Request $request, string $dataId): bool
    {
        if ($this->webhookSecret === '') {
            return true; // No secret set — rely on API re-fetch for trust.
        }

        $signature = $request->header('x-signature', '');
        $requestId = $request->header('x-request-id', '');
        if ($signature === '') {
            return false;
        }

        $ts = '';
        $v1 = '';
        foreach (explode(',', $signature) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$k, $v] = $kv;
            if (trim($k) === 'ts') {
                $ts = trim($v);
            } elseif (trim($k) === 'v1') {
                $v1 = trim($v);
            }
        }

        if ($ts === '' || $v1 === '') {
            return false;
        }

        $manifest = 'id:'.strtolower($dataId).';request-id:'.$requestId.';ts:'.$ts.';';
        $expected = hash_hmac('sha256', $manifest, $this->webhookSecret);

        return hash_equals($expected, $v1);
    }

    private function handlePreapproval(string $preapprovalId): void
    {
        $res = $this->http()->get(self::BASE_URL.'/preapproval/'.$preapprovalId);
        if (! $res->successful()) {
            return;
        }

        $data = $res->json();
        $status = strtolower($data['status'] ?? '');
        [$userId, $planId, $billingCycle] = $this->parseReference($data['external_reference'] ?? '');

        if (! $userId || ! $planId) {
            return;
        }

        if (in_array($status, ['cancelled', 'canceled'], true)) {
            Subscription::where('gateway', 'mercadopago')
                ->where('gateway_subscription_id', $preapprovalId)
                ->update(['status' => 'canceled', 'ends_at' => now()]);

            return;
        }

        if ($status === 'paused') {
            Subscription::where('gateway', 'mercadopago')
                ->where('gateway_subscription_id', $preapprovalId)
                ->update(['status' => 'past_due']);

            return;
        }

        if ($status !== 'authorized') {
            return;
        }

        $isNew = ! Subscription::where('gateway', 'mercadopago')
            ->where('gateway_subscription_id', $preapprovalId)
            ->exists();

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'mercadopago', 'gateway_subscription_id' => $preapprovalId],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => $billingCycle === 'year' ? now()->addYear() : now()->addMonth(),
                'gateway_metadata' => [
                    'preapproval_id' => $preapprovalId,
                    'currency' => strtoupper($data['auto_recurring']['currency_id'] ?? 'BRL'),
                ],
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

    private function handleAuthorizedPayment(string $authorizedPaymentId): void
    {
        $res = $this->http()->get(self::BASE_URL.'/authorized_payments/'.$authorizedPaymentId);
        if (! $res->successful()) {
            return;
        }

        $data = $res->json();
        $preapprovalId = $data['preapproval_id'] ?? '';
        $status = strtolower($data['status'] ?? '');
        $paymentId = (string) ($data['payment']['id'] ?? $authorizedPaymentId);

        if (! $preapprovalId || $status !== 'processed') {
            return;
        }

        $subscription = Subscription::where('gateway', 'mercadopago')
            ->where('gateway_subscription_id', $preapprovalId)
            ->with('user', 'plan')
            ->first();

        // Preapproval webhook may lag the first payment — recover the subscription now.
        if (! $subscription) {
            $this->handlePreapproval($preapprovalId);
            $subscription = Subscription::where('gateway', 'mercadopago')
                ->where('gateway_subscription_id', $preapprovalId)
                ->with('user', 'plan')
                ->first();
        }

        if (! $subscription) {
            return;
        }

        if (PaymentTransaction::where('gateway', 'mercadopago')->where('gateway_transaction_id', $paymentId)->exists()) {
            return;
        }

        $isRenewal = PaymentTransaction::where('gateway', 'mercadopago')
            ->where('subscription_id', $subscription->id)
            ->where('status', 'paid')
            ->exists();

        $amountCents = (int) round(((float) ($data['transaction_amount'] ?? 0)) * 100);
        $currency = strtoupper($data['currency_id'] ?? ($subscription->gateway_metadata['currency'] ?? 'BRL'));

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'gateway' => 'mercadopago',
            'gateway_transaction_id' => $paymentId,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $data,
        ]);
        $this->generateInvoicePdf($transaction);

        $subscription->update([
            'status' => 'active',
            'renews_at' => $subscription->billing_cycle === 'year' ? now()->addYear() : now()->addMonth(),
        ]);

        if ($isRenewal && $subscription->user && $subscription->plan) {
            SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
        }
    }

    /** @return array{0:int,1:int,2:string} [userId, planId, billingCycle] */
    private function parseReference(string $ref): array
    {
        if (preg_match('/^user_(\d+)_plan_(\d+)_(month|year)$/', $ref, $m)) {
            return [(int) $m[1], (int) $m[2], $m[3]];
        }

        return [0, 0, 'month'];
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'mercadopago' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->put(
            self::BASE_URL.'/preapproval/'.$subscription->gateway_subscription_id,
            ['status' => 'cancelled']
        );

        if ($res->successful()) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        Log::error('Mercado Pago cancel failed', ['subscription_id' => $subscription->id, 'body' => $res->json()]);

        return false;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'mercadopago' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->get(self::BASE_URL.'/preapproval/'.$subscription->gateway_subscription_id);
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
        return match (strtolower($status)) {
            'authorized' => 'active',
            'pending' => 'incomplete',
            'paused' => 'past_due',
            'cancelled', 'canceled' => 'canceled',
            default => 'incomplete',
        };
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        if ($subscription->gateway !== 'mercadopago' || ! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Mercado Pago is not configured.'];
        }

        // Mercado Pago can update the recurring amount in place, but not the interval.
        if (($subscription->billing_cycle ?? 'month') !== $billingCycle) {
            return ['ok' => false, 'error' => 'Changing billing interval on Mercado Pago requires cancelling and re-subscribing.'];
        }

        $priceCents = $newPlan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['ok' => false, 'error' => 'New plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($newPlan->currency_code ?? ($subscription->gateway_metadata['currency'] ?? 'BRL'));

        $res = $this->http()->put(self::BASE_URL.'/preapproval/'.$subscription->gateway_subscription_id, [
            'auto_recurring' => [
                'transaction_amount' => round($priceCents / 100, 2),
                'currency_id' => $currency,
            ],
        ]);

        if (! $res->successful()) {
            Log::error('Mercado Pago change plan failed', ['subscription_id' => $subscription->id, 'body' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('message', 'Mercado Pago plan change failed.')];
        }

        $subscription->update(['plan_id' => $newPlan->id]);

        return ['ok' => true, 'error' => null];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Mercado Pago is not configured.'];
        }

        $paymentId = $transaction->gateway_transaction_id;
        if (! $paymentId) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        $body = [];
        if ($amountCents) {
            $body['amount'] = round($amountCents / 100, 2);
        }

        $res = $this->http()->post(self::BASE_URL.'/v1/payments/'.$paymentId.'/refunds', $body);

        if (! $res->successful()) {
            Log::error('Mercado Pago refund failed', ['transaction_id' => $transaction->id, 'body' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('message', 'Mercado Pago refund failed.')];
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
        return ['ok' => false, 'error' => 'Mercado Pago fulfillment is handled via webhook.'];
    }

    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Mercado Pago: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
