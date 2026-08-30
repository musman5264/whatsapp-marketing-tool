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
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mollie recurring billing via the Customers + Subscriptions API (Europe).
 *
 * Flow:
 *   1. Create a Mollie Customer for the user.
 *   2. Create a "first" payment (sequenceType=first) linked to that customer — this
 *      returns a hosted checkout page (`_links.checkout.href`) and establishes a
 *      reusable mandate once paid.
 *   3. On the first payment's webhook (status=paid) we create a Subscription on the
 *      customer. Mollie then charges each interval automatically and calls the same
 *      webhook URL for every recurring payment.
 *
 * Supported methods: iDEAL, SEPA Direct Debit, cards, Bancontact, and more —
 * Mollie decides which are available for recurring based on the account.
 *
 * Webhook security: Mollie posts only an opaque, unguessable resource `id`; the
 * documented model is to re-fetch that resource from the API over TLS (we never
 * trust the POST body). No shared signature is used.
 *
 * Amounts use Mollie's decimal string format ({"currency":"EUR","value":"10.00"}).
 * The app stores minor units in `amount_cents`; we divide by 100 and format to two
 * decimals, which is correct for EUR and all two-decimal currencies.
 */
class MollieGateway implements BillingGatewayInterface
{
    private const BASE_URL = 'https://api.mollie.com/v2';

    public function __construct(
        private string $apiKey,
        private string $successUrl,
        private string $cancelUrl,
    ) {}

    public function name(): string
    {
        return 'Mollie';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson();
    }

    private function webhookUrl(): string
    {
        return rtrim(config('app.url', ''), '/').'/webhooks/mollie';
    }

    private function money(int $cents, string $currency): array
    {
        return [
            'currency' => strtoupper($currency),
            'value' => number_format($cents / 100, 2, '.', ''),
        ];
    }

    /** Mollie interval string, e.g. "1 month" or "12 months". */
    private function interval(string $billingCycle): string
    {
        return $billingCycle === 'year' ? '12 months' : '1 month';
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Mollie is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($plan->currency_code ?? 'EUR');

        // 1) Create a Mollie customer.
        $custRes = $this->http()->post(self::BASE_URL.'/customers', [
            'name' => $user->name ?? ('User '.$user->id),
            'email' => $user->email ?? ('user'.$user->id.'@placeholder.invalid'),
            'metadata' => ['user_id' => (string) $user->id],
        ]);

        if (! $custRes->successful()) {
            Log::error('Mollie create customer failed', ['body' => $custRes->json(), 'user_id' => $user->id]);

            return ['error' => $custRes->json('detail', 'Mollie customer creation failed.')];
        }

        $customerId = $custRes->json('id');
        if (! $customerId) {
            return ['error' => 'No customer id in Mollie response.'];
        }

        // 2) Create the first (mandate-establishing) payment as a hosted checkout.
        $payRes = $this->http()->post(self::BASE_URL.'/payments', [
            'amount' => $this->money($priceCents, $currency),
            'customerId' => $customerId,
            'sequenceType' => 'first',
            'description' => $plan->name.' ('.$billingCycle.')',
            'redirectUrl' => $this->successUrl,
            'cancelUrl' => $this->cancelUrl,
            'webhookUrl' => $this->webhookUrl(),
            'metadata' => [
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'billing_cycle' => $billingCycle,
                'customer_id' => $customerId,
                'amount_cents' => (string) $priceCents,
                'currency' => $currency,
            ],
        ]);

        if (! $payRes->successful()) {
            Log::error('Mollie create first payment failed', ['body' => $payRes->json(), 'user_id' => $user->id]);

            return ['error' => $payRes->json('detail', 'Mollie checkout creation failed.')];
        }

        $url = $payRes->json('_links.checkout.href');
        if (! $url) {
            return ['error' => 'No checkout URL in Mollie response.'];
        }

        return ['url' => $url];
    }

    public function handleWebhook(Request $request): Response
    {
        // Mollie posts only the resource id; we re-fetch it from the API to verify.
        $paymentId = $request->input('id', '');
        if (! $paymentId || ! Str::startsWith($paymentId, 'tr_')) {
            return new Response('OK', 200);
        }

        if (! app(WebhookIdempotencyService::class)->isNewEvent('mollie', $paymentId)) {
            return new Response('OK', 200);
        }

        try {
            $res = $this->http()->get(self::BASE_URL.'/payments/'.$paymentId);
            if (! $res->successful()) {
                app(WebhookIdempotencyService::class)->release('mollie', $paymentId);

                return new Response('OK', 200);
            }

            $payment = $res->json();
            $sequenceType = $payment['sequenceType'] ?? '';
            $subscriptionId = $payment['subscriptionId'] ?? null;

            if ($subscriptionId) {
                $this->handleRecurringPayment($payment);
            } elseif ($sequenceType === 'first') {
                $this->handleFirstPayment($payment);
            }
        } catch (\Throwable $e) {
            Log::error('Mollie webhook handler failed', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
            app(WebhookIdempotencyService::class)->release('mollie', $paymentId);

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    /** First payment paid → record it, create the Mollie subscription, activate locally. */
    private function handleFirstPayment(array $payment): void
    {
        if (($payment['status'] ?? '') !== 'paid') {
            return;
        }

        $meta = $payment['metadata'] ?? [];
        $userId = (int) ($meta['user_id'] ?? 0);
        $planId = (int) ($meta['plan_id'] ?? 0);
        $billingCycle = $meta['billing_cycle'] ?? 'month';
        $customerId = $meta['customer_id'] ?? ($payment['customerId'] ?? '');
        $amountCents = (int) round(((float) ($payment['amount']['value'] ?? 0)) * 100);
        $currency = strtoupper($payment['amount']['currency'] ?? 'EUR');

        if (! $userId || ! $planId || ! $customerId) {
            return;
        }

        // Create the recurring subscription on the customer. Start one interval out so
        // the first charge we already collected is not immediately duplicated.
        $startDate = $billingCycle === 'year' ? now()->addYear() : now()->addMonth();

        $subRes = $this->http()->post(self::BASE_URL.'/customers/'.$customerId.'/subscriptions', [
            'amount' => $this->money($amountCents, $currency),
            'interval' => $this->interval($billingCycle),
            'startDate' => $startDate->format('Y-m-d'),
            'description' => 'Subscription plan '.$planId.' ('.$billingCycle.') user '.$userId,
            'webhookUrl' => $this->webhookUrl(),
            'metadata' => [
                'user_id' => (string) $userId,
                'plan_id' => (string) $planId,
                'billing_cycle' => $billingCycle,
            ],
        ]);

        if (! $subRes->successful()) {
            Log::error('Mollie create subscription failed', ['body' => $subRes->json(), 'user_id' => $userId]);

            return;
        }

        $mollieSubId = $subRes->json('id');
        if (! $mollieSubId) {
            return;
        }

        $isNew = ! Subscription::where('gateway', 'mollie')
            ->where('gateway_subscription_id', $mollieSubId)
            ->exists();

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'mollie', 'gateway_subscription_id' => $mollieSubId],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => $startDate,
                'gateway_metadata' => [
                    'customer_id' => $customerId,
                    'subscription_id' => $mollieSubId,
                    'currency' => $currency,
                ],
            ]
        );

        // Record the first payment transaction.
        if (! PaymentTransaction::where('gateway', 'mollie')->where('gateway_transaction_id', $payment['id'])->exists()) {
            $transaction = PaymentTransaction::create([
                'user_id' => $userId,
                'subscription_id' => $subscription->id,
                'gateway' => 'mollie',
                'gateway_transaction_id' => $payment['id'],
                'amount_cents' => $amountCents,
                'currency_code' => $currency,
                'status' => 'paid',
                'payload' => $payment,
            ]);
            $this->generateInvoicePdf($transaction);
        }

        if ($isNew) {
            $user = User::find($userId);
            $plan = Plan::find($planId);
            if ($user && $plan) {
                SubscriptionStarted::dispatch($user, $subscription, $plan);
            }
        }
    }

    /** A recurring subscription payment fired its webhook. */
    private function handleRecurringPayment(array $payment): void
    {
        $mollieSubId = $payment['subscriptionId'] ?? '';
        $status = $payment['status'] ?? '';

        $subscription = Subscription::where('gateway', 'mollie')
            ->where('gateway_subscription_id', $mollieSubId)
            ->with('user', 'plan')
            ->first();

        if (! $subscription) {
            return;
        }

        if (in_array($status, ['failed', 'expired', 'canceled'], true)) {
            $subscription->update(['status' => 'past_due']);

            return;
        }

        if ($status !== 'paid') {
            return;
        }

        if (PaymentTransaction::where('gateway', 'mollie')->where('gateway_transaction_id', $payment['id'])->exists()) {
            return;
        }

        $amountCents = (int) round(((float) ($payment['amount']['value'] ?? 0)) * 100);
        $currency = strtoupper($payment['amount']['currency'] ?? 'EUR');

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'gateway' => 'mollie',
            'gateway_transaction_id' => $payment['id'],
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $payment,
        ]);
        $this->generateInvoicePdf($transaction);

        $subscription->update([
            'status' => 'active',
            'renews_at' => $subscription->billing_cycle === 'year' ? now()->addYear() : now()->addMonth(),
        ]);

        if ($subscription->user && $subscription->plan) {
            SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
        }
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'mollie' || ! $this->isConfigured()) {
            return false;
        }

        $customerId = $subscription->gateway_metadata['customer_id'] ?? '';
        if (! $customerId) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        $res = $this->http()->delete(
            self::BASE_URL.'/customers/'.$customerId.'/subscriptions/'.$subscription->gateway_subscription_id
        );

        if ($res->successful()) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        Log::error('Mollie cancel failed', ['subscription_id' => $subscription->id, 'body' => $res->json()]);

        return false;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'mollie' || ! $this->isConfigured()) {
            return false;
        }

        $customerId = $subscription->gateway_metadata['customer_id'] ?? '';
        if (! $customerId) {
            return false;
        }

        $res = $this->http()->get(
            self::BASE_URL.'/customers/'.$customerId.'/subscriptions/'.$subscription->gateway_subscription_id
        );
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
            'active' => 'active',
            'pending' => 'incomplete',
            'suspended' => 'past_due',
            'canceled', 'completed' => 'canceled',
            default => 'incomplete',
        };
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        if ($subscription->gateway !== 'mollie' || ! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Mollie is not configured.'];
        }

        $customerId = $subscription->gateway_metadata['customer_id'] ?? '';
        if (! $customerId) {
            return ['ok' => false, 'error' => 'Missing Mollie customer id for this subscription.'];
        }

        $priceCents = $newPlan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['ok' => false, 'error' => 'New plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($newPlan->currency_code ?? ($subscription->gateway_metadata['currency'] ?? 'EUR'));

        $res = $this->http()->patch(
            self::BASE_URL.'/customers/'.$customerId.'/subscriptions/'.$subscription->gateway_subscription_id,
            [
                'amount' => $this->money($priceCents, $currency),
                'interval' => $this->interval($billingCycle),
                'description' => 'Subscription plan '.$newPlan->id.' ('.$billingCycle.') user '.$subscription->user_id,
            ]
        );

        if (! $res->successful()) {
            Log::error('Mollie change plan failed', ['subscription_id' => $subscription->id, 'body' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('detail', 'Mollie plan change failed.')];
        }

        $subscription->update(['plan_id' => $newPlan->id, 'billing_cycle' => $billingCycle]);

        return ['ok' => true, 'error' => null];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Mollie is not configured.'];
        }

        $paymentId = $transaction->gateway_transaction_id;
        if (! $paymentId) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        $cents = $amountCents ?? $transaction->amount_cents;
        $res = $this->http()->post(self::BASE_URL.'/payments/'.$paymentId.'/refunds', [
            'amount' => $this->money($cents, $transaction->currency_code ?? 'EUR'),
        ]);

        if (! $res->successful()) {
            Log::error('Mollie refund failed', ['transaction_id' => $transaction->id, 'body' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('detail', 'Mollie refund failed.')];
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
        return ['ok' => false, 'error' => 'Mollie fulfillment is handled via webhook.'];
    }

    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Mollie: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
