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
 * Square recurring billing via the Catalog + Subscriptions API.
 * Available markets: United States, Canada, United Kingdom, Australia, Ireland,
 * France, Spain, Japan.
 *
 * Flow:
 *   1. Upsert a catalog Subscription Plan + Plan Variation for the app plan+cycle
 *      (cadence MONTHLY / ANNUAL) and read back the variation id.
 *   2. Create (or reuse) a Square Customer for the user.
 *   3. Create a Subscription with no card on file → Square generates an Invoice each
 *      billing cycle and emails the payer a hosted payment page. We return the first
 *      invoice's `public_url` as the checkout redirect.
 *   4. Each paid invoice fires an `invoice.payment_made` webhook that records the
 *      transaction and (on the first one) activates the subscription.
 *
 * Amounts use Square Money (integer minor units), which maps directly to the app's
 * `amount_cents`.
 *
 * Credential mapping: secret_key = Access Token, publishable_key = Location ID,
 * webhook_secret = Webhook Signature Key.
 *
 * Webhook security: HMAC-SHA256 of (notificationUrl + rawBody) keyed by the signature
 * key, base64-encoded, delivered in the `x-square-hmacsha256-signature` header.
 *
 * Refunds require a Square payment id, which the invoice webhook does not expose;
 * refunds must therefore be issued from the Square Dashboard.
 */
class SquareGateway implements BillingGatewayInterface
{
    private const API_VERSION = '2024-12-18';

    public function __construct(
        private string $accessToken,
        private string $locationId,
        private string $signatureKey,
        private bool $testMode,
        private string $successUrl,
        private string $cancelUrl,
    ) {}

    public function name(): string
    {
        return 'Square';
    }

    public function isConfigured(): bool
    {
        return $this->accessToken !== '' && $this->locationId !== '';
    }

    private function baseUrl(): string
    {
        return $this->testMode
            ? 'https://connect.squareupsandbox.com'
            : 'https://connect.squareup.com';
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->accessToken)
            ->withHeaders(['Square-Version' => self::API_VERSION])
            ->acceptJson()
            ->asJson();
    }

    private function webhookUrl(): string
    {
        return rtrim(config('app.url', ''), '/').'/webhooks/square';
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Square is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($plan->currency_code ?? 'USD');

        // 1) Upsert a catalog subscription plan variation.
        $variationId = $this->upsertPlanVariation($plan, $billingCycle, $priceCents, $currency);
        if (! $variationId) {
            return ['error' => 'Square catalog plan creation failed.'];
        }

        // 2) Upsert the customer.
        $customerId = $this->upsertCustomer($user);
        if (! $customerId) {
            return ['error' => 'Square customer creation failed.'];
        }

        // 3) Create the subscription (invoice-billed — no card on file).
        $subRes = $this->http()->post($this->baseUrl().'/v2/subscriptions', [
            'idempotency_key' => (string) Str::uuid(),
            'location_id' => $this->locationId,
            'plan_variation_id' => $variationId,
            'customer_id' => $customerId,
        ]);

        if (! $subRes->successful()) {
            Log::error('Square create subscription failed', ['body' => $subRes->json(), 'user_id' => $user->id]);

            return ['error' => $subRes->json('errors.0.detail', 'Square subscription creation failed.')];
        }

        $subscriptionId = $subRes->json('subscription.id');
        if (! $subscriptionId) {
            return ['error' => 'No subscription id in Square response.'];
        }

        // Record the pending subscription locally; the invoice webhook activates it.
        Subscription::updateOrCreate(
            ['gateway' => 'square', 'gateway_subscription_id' => $subscriptionId],
            [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'billing_cycle' => $billingCycle,
                'status' => 'incomplete',
                'starts_at' => now(),
                'ends_at' => null,
                'gateway_metadata' => [
                    'customer_id' => $customerId,
                    'plan_variation_id' => $variationId,
                    'currency' => $currency,
                ],
            ]
        );

        // 4) Resolve the first invoice's hosted payment page.
        $url = $this->firstInvoiceUrl($subscriptionId) ?? $this->successUrl;

        return ['url' => $url];
    }

    private function upsertPlanVariation(Plan $plan, string $billingCycle, int $priceCents, string $currency): ?string
    {
        $cadence = $billingCycle === 'year' ? 'ANNUAL' : 'MONTHLY';

        $res = $this->http()->post($this->baseUrl().'/v2/catalog/object', [
            'idempotency_key' => (string) Str::uuid(),
            'object' => [
                'type' => 'SUBSCRIPTION_PLAN',
                'id' => '#plan',
                'subscription_plan_data' => [
                    'name' => $plan->name.' ('.$billingCycle.')',
                    'subscription_plan_variations' => [[
                        'type' => 'SUBSCRIPTION_PLAN_VARIATION',
                        'id' => '#variation',
                        'subscription_plan_variation_data' => [
                            'name' => ucfirst($billingCycle).'ly',
                            'phases' => [[
                                'cadence' => $cadence,
                                'pricing' => [
                                    'type' => 'STATIC',
                                    'price_money' => ['amount' => $priceCents, 'currency' => $currency],
                                ],
                            ]],
                        ],
                    ]],
                ],
            ],
        ]);

        if (! $res->successful()) {
            Log::error('Square upsert catalog plan failed', ['body' => $res->json(), 'plan_id' => $plan->id]);

            return null;
        }

        return $res->json('catalog_object.subscription_plan_data.subscription_plan_variations.0.id');
    }

    private function upsertCustomer(User $user): ?string
    {
        $refId = 'user_'.$user->id;

        // Reuse an existing customer for this user if one exists.
        $searchRes = $this->http()->post($this->baseUrl().'/v2/customers/search', [
            'query' => ['filter' => ['reference_id' => ['exact' => $refId]]],
            'limit' => 1,
        ]);
        if ($searchRes->successful()) {
            $existing = $searchRes->json('customers.0.id');
            if ($existing) {
                return $existing;
            }
        }

        $createRes = $this->http()->post($this->baseUrl().'/v2/customers', [
            'idempotency_key' => (string) Str::uuid(),
            'reference_id' => $refId,
            'given_name' => $user->name ?? ('User '.$user->id),
            'email_address' => $user->email ?? ('user'.$user->id.'@placeholder.invalid'),
        ]);

        if (! $createRes->successful()) {
            Log::error('Square create customer failed', ['body' => $createRes->json(), 'user_id' => $user->id]);

            return null;
        }

        return $createRes->json('customer.id');
    }

    private function firstInvoiceUrl(string $subscriptionId): ?string
    {
        $res = $this->http()->get($this->baseUrl().'/v2/subscriptions/'.$subscriptionId);
        if (! $res->successful()) {
            return null;
        }

        $invoiceId = $res->json('subscription.invoice_ids.0');
        if (! $invoiceId) {
            return null;
        }

        $invRes = $this->http()->get($this->baseUrl().'/v2/invoices/'.$invoiceId);
        if (! $invRes->successful()) {
            return null;
        }

        return $invRes->json('invoice.public_url');
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();

        // Verify HMAC-SHA256 over (notificationUrl + body).
        if ($this->signatureKey === '' && app()->environment('production')) {
            Log::warning('Square webhook: signature key not configured in production — rejecting request');

            return new Response('Signature key not configured', 401);
        }
        if ($this->signatureKey !== '') {
            $sig = $request->header('x-square-hmacsha256-signature', '');
            $expected = base64_encode(hash_hmac('sha256', $this->webhookUrl().$payload, $this->signatureKey, true));
            if (! hash_equals($expected, $sig)) {
                Log::warning('Square webhook signature verification failed');

                return new Response('Invalid signature', 401);
            }
        }

        $data = json_decode($payload, true) ?: [];
        $event = $data['type'] ?? '';
        $eventId = $data['event_id'] ?? null;

        if ($eventId && ! app(WebhookIdempotencyService::class)->isNewEvent('square', (string) $eventId)) {
            return new Response('OK', 200);
        }

        try {
            match ($event) {
                'invoice.payment_made' => $this->handleInvoicePaid($data['data']['object']['invoice'] ?? []),
                'invoice.scheduled_charge_failed' => $this->handleInvoiceFailed($data['data']['object']['invoice'] ?? []),
                'subscription.updated' => $this->handleSubscriptionUpdated($data['data']['object']['subscription'] ?? []),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Square webhook handler failed', ['event' => $event, 'error' => $e->getMessage()]);
            if ($eventId) {
                app(WebhookIdempotencyService::class)->release('square', (string) $eventId);
            }

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    private function handleInvoicePaid(array $invoice): void
    {
        $subscriptionId = $invoice['subscription_id'] ?? '';
        $invoiceId = $invoice['id'] ?? '';
        if (! $subscriptionId || ! $invoiceId) {
            return;
        }

        $subscription = Subscription::where('gateway', 'square')
            ->where('gateway_subscription_id', $subscriptionId)
            ->with('user', 'plan')
            ->first();

        if (! $subscription) {
            return;
        }

        if (PaymentTransaction::where('gateway', 'square')->where('gateway_transaction_id', $invoiceId)->exists()) {
            return;
        }

        $wasActive = PaymentTransaction::where('gateway', 'square')
            ->where('subscription_id', $subscription->id)
            ->where('status', 'paid')
            ->exists();

        // Invoice payloads don't carry the paid amount reliably; use the plan price.
        $amountCents = $subscription->plan?->priceCentsForCycle($subscription->billing_cycle ?? 'month') ?? 0;
        $currency = strtoupper($subscription->gateway_metadata['currency'] ?? 'USD');

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'gateway' => 'square',
            'gateway_transaction_id' => $invoiceId,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $invoice,
        ]);
        $this->generateInvoicePdf($transaction);

        $subscription->update([
            'status' => 'active',
            'renews_at' => $subscription->billing_cycle === 'year' ? now()->addYear() : now()->addMonth(),
        ]);

        if (! $wasActive) {
            if ($subscription->user && $subscription->plan) {
                SubscriptionStarted::dispatch($subscription->user, $subscription, $subscription->plan);
            }
        } elseif ($subscription->user && $subscription->plan) {
            SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
        }
    }

    private function handleInvoiceFailed(array $invoice): void
    {
        $subscriptionId = $invoice['subscription_id'] ?? '';
        if (! $subscriptionId) {
            return;
        }
        Subscription::where('gateway', 'square')
            ->where('gateway_subscription_id', $subscriptionId)
            ->update(['status' => 'past_due']);
    }

    private function handleSubscriptionUpdated(array $sub): void
    {
        $subscriptionId = $sub['id'] ?? '';
        if (! $subscriptionId) {
            return;
        }
        Subscription::where('gateway', 'square')
            ->where('gateway_subscription_id', $subscriptionId)
            ->update(['status' => $this->mapStatus($sub['status'] ?? '')]);
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'ACTIVE' => 'active',
            'PENDING' => 'incomplete',
            'PAUSED', 'DELINQUENT' => 'past_due',
            'CANCELED', 'DEACTIVATED' => 'canceled',
            default => 'incomplete',
        };
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'square' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->post(
            $this->baseUrl().'/v2/subscriptions/'.$subscription->gateway_subscription_id.'/cancel'
        );

        if ($res->successful()) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        Log::error('Square cancel failed', ['subscription_id' => $subscription->id, 'body' => $res->json()]);

        return false;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'square' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->get($this->baseUrl().'/v2/subscriptions/'.$subscription->gateway_subscription_id);
        if (! $res->successful()) {
            return false;
        }

        $subscription->update([
            'status' => $this->mapStatus($res->json('subscription.status', '')),
        ]);

        return true;
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        if ($subscription->gateway !== 'square' || ! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Square is not configured.'];
        }

        $priceCents = $newPlan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['ok' => false, 'error' => 'New plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($newPlan->currency_code ?? ($subscription->gateway_metadata['currency'] ?? 'USD'));

        $variationId = $this->upsertPlanVariation($newPlan, $billingCycle, $priceCents, $currency);
        if (! $variationId) {
            return ['ok' => false, 'error' => 'Square catalog plan creation failed.'];
        }

        $res = $this->http()->post(
            $this->baseUrl().'/v2/subscriptions/'.$subscription->gateway_subscription_id.'/swap-plan',
            ['new_plan_variation_id' => $variationId]
        );

        if (! $res->successful()) {
            Log::error('Square swap plan failed', ['subscription_id' => $subscription->id, 'body' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('errors.0.detail', 'Square plan change failed.')];
        }

        $meta = $subscription->gateway_metadata ?? [];
        $meta['plan_variation_id'] = $variationId;
        $meta['currency'] = $currency;
        $subscription->update([
            'plan_id' => $newPlan->id,
            'billing_cycle' => $billingCycle,
            'gateway_metadata' => $meta,
        ]);

        return ['ok' => true, 'error' => null];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        // Square invoice webhooks do not expose a refundable payment id.
        return ['ok' => false, 'error' => 'Square refunds must be issued from the Square Dashboard.'];
    }

    public function fulfillCheckoutSession(string $sessionId): array
    {
        return ['ok' => false, 'error' => 'Square fulfillment is handled via webhook.'];
    }

    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Square: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
