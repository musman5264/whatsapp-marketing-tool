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
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * iyzico recurring billing via the Subscription API (Turkey).
 *
 * Flow:
 *   1. A price can't be attached to a checkout on the fly — every subscription must point
 *      at a pre-created pricing plan, which lives under a product. Both are immutable, so
 *      `iyzico_pricing_plans` fingerprints (plan, cycle, price, currency, mode) and reuses
 *      the reference codes. One product per mode holds every plan, because upgrades only
 *      work within a single product.
 *   2. `checkoutform/initialize` returns embedded form HTML — there is no hosted page to
 *      redirect to, unlike every other gateway here. We persist the markup in
 *      `iyzico_checkout_sessions` and serve it from our own route; that row is also the
 *      only link from the callback token back to the user and plan, since iyzico echoes
 *      no metadata.
 *   3. The customer's browser posts the token to `callbackUrl`, which fulfils the
 *      subscription. Renewals then arrive as `subscription.order.success` webhooks.
 *
 * Auth (IYZWSv2): signature = hex(hmac_sha256(randomKey + uriPath + jsonBody, secretKey)),
 * sent as base64("apiKey:…&randomKey:…&signature:…"). The uriPath excludes host and query
 * string. The signed JSON and the transmitted body must be byte-identical, so every request
 * encodes once and reuses that string.
 *
 * Errors are reported in the body (`status: "failure"`) and not reliably by HTTP status,
 * so success means 2xx *and* `status === "success"`.
 *
 * Subscriptions are limited to TRY, USD and EUR.
 */
class IyzicoGateway implements BillingGatewayInterface
{
    private const CLIENT_VERSION = 'whatsmine-laravel-1.0';

    /** iyzico rejects any other currency on a subscription pricing plan. */
    private const SUPPORTED_CURRENCIES = ['TRY', 'USD', 'EUR'];

    public function __construct(
        private string $apiKey,
        private string $secretKey,
        private string $merchantId,
        private bool $testMode,
        private string $successUrl,
        private string $cancelUrl,
    ) {}

    public function name(): string
    {
        return 'iyzico';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->secretKey !== '';
    }

    private function baseUrl(): string
    {
        return $this->testMode
            ? 'https://sandbox-api.iyzipay.com'
            : 'https://api.iyzipay.com';
    }

    private function mode(): string
    {
        return $this->testMode ? 'test' : 'live';
    }

    /**
     * Signed request. $path must be the URI path only — no host, no query string — because
     * that is exactly what the signature covers.
     */
    private function send(string $method, string $path, ?array $body = null): ClientResponse
    {
        // Encode once: the bytes we sign have to be the bytes we send.
        $json = $body === null ? null : json_encode($body);
        $random = Str::random(24);
        $signature = bin2hex(hash_hmac('sha256', $random.$path.($json ?? ''), $this->secretKey, true));
        $auth = base64_encode('apiKey:'.$this->apiKey.'&randomKey:'.$random.'&signature:'.$signature);

        $request = Http::withHeaders([
            'Authorization' => 'IYZWSv2 '.$auth,
            'x-iyzi-rnd' => $random,
            'x-iyzi-client-version' => self::CLIENT_VERSION,
            'Accept' => 'application/json',
        ])->timeout(30);

        if ($json !== null) {
            $request = $request->withBody($json, 'application/json');
        }

        return $request->send($method, $this->baseUrl().$path);
    }

    /** iyzico signals failure in the body, so a 200 alone proves nothing. */
    private function ok(ClientResponse $res): bool
    {
        return $res->successful() && $res->json('status') === 'success';
    }

    private function errorOf(ClientResponse $res, string $fallback): string
    {
        $message = $res->json('errorMessage');

        return is_string($message) && $message !== '' ? $message : $fallback;
    }

    private function price(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function callbackUrl(): string
    {
        return rtrim(config('app.url', ''), '/').'/billing/iyzico/callback';
    }

    // ─── Product & pricing plan provisioning ────────────────────────────────

    /**
     * Every pricing plan hangs off one product per mode — iyzico refuses to upgrade a
     * subscription onto a plan belonging to a different product.
     */
    private function resolveProductReference(): ?string
    {
        $existing = DB::table('iyzico_pricing_plans')
            ->where('mode', $this->mode())
            ->value('product_reference_code');

        if ($existing) {
            return $existing;
        }

        $res = $this->send('POST', '/v2/subscription/products', [
            'locale' => 'en',
            'name' => Str::limit(config('app.name', 'App').' Subscriptions', 60, ''),
            'description' => 'Recurring plans for '.config('app.name', 'this application').'.',
        ]);

        if (! $this->ok($res)) {
            Log::error('iyzico create product failed', ['body' => $res->json()]);

            return null;
        }

        return $res->json('data.referenceCode');
    }

    /**
     * Reference code for the pricing plan matching this plan/cycle/price, creating it on
     * first use. Any price or currency change fingerprints differently and provisions a new
     * plan, which is what iyzico requires — existing subscriptions keep billing the old one.
     */
    private function resolvePricingPlanReference(Plan $plan, string $billingCycle, int $priceCents, string $currency): ?string
    {
        $fingerprint = hash('sha256', implode('|', [$this->mode(), $plan->id, $billingCycle, $priceCents, $currency]));

        $existing = DB::table('iyzico_pricing_plans')
            ->where('fingerprint', $fingerprint)
            ->value('pricing_plan_reference_code');

        if ($existing) {
            return $existing;
        }

        $productReference = $this->resolveProductReference();
        if (! $productReference) {
            return null;
        }

        $body = [
            'locale' => 'en',
            'conversationId' => (string) $plan->id,
            'productReferenceCode' => $productReference,
            // iyzico does not enforce name uniqueness; the fingerprint suffix keeps plans
            // distinguishable in the dashboard after a price change.
            'name' => Str::limit($plan->name, 40, '').' · '.strtoupper($billingCycle).' · '.substr($fingerprint, 0, 8),
            'price' => $this->price($priceCents),
            'currencyCode' => $currency,
            'paymentInterval' => $billingCycle === 'year' ? 'YEARLY' : 'MONTHLY',
            'paymentIntervalCount' => 1,
            'planPaymentType' => 'RECURRING',
        ];

        if (($plan->trial_days ?? 0) > 0) {
            $body['trialPeriodDays'] = (int) $plan->trial_days;
        }

        $res = $this->send('POST', '/v2/subscription/products/'.$productReference.'/pricing-plans', $body);

        if (! $this->ok($res)) {
            Log::error('iyzico create pricing plan failed', ['body' => $res->json(), 'plan_id' => $plan->id]);

            return null;
        }

        $reference = $res->json('data.referenceCode');
        if (! $reference) {
            return null;
        }

        DB::table('iyzico_pricing_plans')->insertOrIgnore([
            'plan_id' => $plan->id,
            'billing_cycle' => $billingCycle,
            'mode' => $this->mode(),
            'fingerprint' => $fingerprint,
            'product_reference_code' => $productReference,
            'pricing_plan_reference_code' => $reference,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A concurrent checkout may have won the insert; the stored code is authoritative.
        return DB::table('iyzico_pricing_plans')->where('fingerprint', $fingerprint)->value('pricing_plan_reference_code');
    }

    // ─── Checkout ───────────────────────────────────────────────────────────

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'iyzico is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($plan->currency_code ?? 'TRY');
        if (! in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            return ['error' => 'iyzico subscriptions support only TRY, USD and EUR.'];
        }

        $pricingPlanReference = $this->resolvePricingPlanReference($plan, $billingCycle, $priceCents, $currency);
        if (! $pricingPlanReference) {
            return ['error' => 'iyzico pricing plan creation failed.'];
        }

        $res = $this->send('POST', '/v2/subscription/checkoutform/initialize', [
            'locale' => 'en',
            'conversationId' => (string) $user->id,
            'callbackUrl' => $this->callbackUrl(),
            'pricingPlanReferenceCode' => $pricingPlanReference,
            'subscriptionInitialStatus' => 'ACTIVE',
            'customer' => $this->customerPayload($user),
        ]);

        if (! $this->ok($res)) {
            Log::error('iyzico initialize checkout failed', ['body' => $res->json(), 'user_id' => $user->id]);

            return ['error' => $this->errorOf($res, 'iyzico checkout could not be started.')];
        }

        // Unlike the retrieve endpoints, initialize returns these at the top level.
        $token = $res->json('token');
        $content = $res->json('checkoutFormContent');
        if (! $token || ! $content) {
            return ['error' => 'iyzico returned no checkout form.'];
        }

        $expiresInSeconds = (int) ($res->json('tokenExpireTime') ?: 1800);

        DB::table('iyzico_checkout_sessions')->updateOrInsert(
            ['token' => $token],
            [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'billing_cycle' => $billingCycle,
                'pricing_plan_reference_code' => $pricingPlanReference,
                'checkout_form_content' => $content,
                'expires_at' => now()->addSeconds($expiresInSeconds),
                'consumed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return ['url' => route('checkout.iyzico.form', ['token' => $token])];
    }

    /**
     * iyzico demands a full Turkish-format customer profile. The app stores no phone number
     * or national ID, so those fall back to configured placeholders — a live TR account will
     * reject them, hence the config knobs.
     */
    private function customerPayload(User $user): array
    {
        $defaults = config('billing.gateways.iyzico.customer_defaults', []);
        $fullName = trim((string) ($user->name ?? '')) ?: ('User '.$user->id);
        $parts = preg_split('/\s+/', $fullName) ?: [];
        $first = array_shift($parts) ?: $fullName;
        $last = $parts ? implode(' ', $parts) : $first;

        $address = [
            'contactName' => $fullName,
            'city' => $defaults['city'] ?? 'Istanbul',
            'country' => $defaults['country'] ?? 'Turkey',
            'address' => $defaults['address'] ?? 'Not collected',
        ];
        if (! empty($defaults['zip_code'])) {
            $address['zipCode'] = $defaults['zip_code'];
        }

        return [
            'name' => $first,
            'surname' => $last,
            'identityNumber' => $defaults['identity_number'] ?? '11111111111',
            'email' => $user->email ?: ('user'.$user->id.'@placeholder.invalid'),
            'gsmNumber' => $defaults['gsm_number'] ?? '+905350000000',
            'billingAddress' => $address,
        ];
    }

    /**
     * Fulfil a checkout token. Called from the iyzico callback and safe to re-run — the
     * subscription is keyed on its reference code.
     */
    public function fulfillCheckoutSession(string $sessionId): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'iyzico is not configured.'];
        }

        $session = DB::table('iyzico_checkout_sessions')->where('token', $sessionId)->first();
        if (! $session) {
            return ['ok' => false, 'error' => 'Unknown iyzico checkout token.'];
        }

        $res = $this->send('GET', '/v2/subscription/checkoutform/'.$sessionId);
        if (! $this->ok($res)) {
            return ['ok' => false, 'error' => $this->errorOf($res, 'iyzico checkout is not complete.')];
        }

        $data = $res->json('data') ?? [];
        $reference = $data['referenceCode'] ?? '';
        if (! $reference) {
            return ['ok' => false, 'error' => 'iyzico checkout is not complete.'];
        }

        $isNew = ! Subscription::where('gateway', 'iyzico')
            ->where('gateway_subscription_id', $reference)
            ->exists();

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'iyzico', 'gateway_subscription_id' => $reference],
            [
                'user_id' => $session->user_id,
                'plan_id' => $session->plan_id,
                'billing_cycle' => $session->billing_cycle,
                'status' => $this->mapStatus($data['subscriptionStatus'] ?? ''),
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => $this->nextRenewal($session->billing_cycle),
                'trial_ends_at' => ($data['trialDays'] ?? 0) > 0 ? now()->addDays((int) $data['trialDays']) : null,
                'gateway_metadata' => [
                    'customer_reference_code' => $data['customerReferenceCode'] ?? null,
                    'pricing_plan_reference_code' => $data['pricingPlanReferenceCode'] ?? $session->pricing_plan_reference_code,
                    'parent_reference_code' => $data['parentReferenceCode'] ?? null,
                    'mode' => $this->mode(),
                ],
            ]
        );

        DB::table('iyzico_checkout_sessions')
            ->where('token', $sessionId)
            ->update(['consumed_at' => now(), 'updated_at' => now()]);

        // The first order's webhook can land before this row exists, in which case it was
        // dropped; backfilling from the subscription detail recovers that payment.
        $this->syncOrders($subscription);

        if ($isNew) {
            $user = User::find($session->user_id);
            $plan = Plan::find($session->plan_id);
            if ($user && $plan) {
                SubscriptionStarted::dispatch($user, $subscription, $plan);
            }
        }

        return ['ok' => true, 'error' => null, 'subscription' => $subscription];
    }

    // ─── Webhooks ───────────────────────────────────────────────────────────

    public function handleWebhook(Request $request): Response
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $eventType = (string) ($data['iyziEventType'] ?? '');
        $subscriptionReference = (string) ($data['subscriptionReferenceCode'] ?? '');
        $orderReference = (string) ($data['orderReferenceCode'] ?? '');
        $customerReference = (string) ($data['customerReferenceCode'] ?? '');

        if (! $this->verifyWebhook($request, $eventType, $subscriptionReference, $orderReference, $customerReference)) {
            return new Response('Invalid signature', 401);
        }

        $eventId = (string) ($data['iyziReferenceCode'] ?? '');
        if ($eventId && ! app(WebhookIdempotencyService::class)->isNewEvent('iyzico', $eventId)) {
            return new Response('OK', 200);
        }

        try {
            $subscription = Subscription::where('gateway', 'iyzico')
                ->where('gateway_subscription_id', $subscriptionReference)
                ->with('user', 'plan')
                ->first();

            if (! $subscription) {
                // The customer has not returned to the callback yet, and the payload carries
                // nothing that identifies the user. fulfillCheckoutSession() backfills the
                // order once they land.
                Log::info('iyzico webhook for unknown subscription — deferred to checkout callback', [
                    'subscription_reference' => $subscriptionReference,
                    'event' => $eventType,
                ]);

                return new Response('OK', 200);
            }

            match ($eventType) {
                'subscription.order.success' => $this->syncOrders($subscription, $orderReference),
                'subscription.order.failure' => $subscription->update(['status' => 'past_due']),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('iyzico webhook handler failed', ['event' => $eventType, 'error' => $e->getMessage()]);
            if ($eventId) {
                app(WebhookIdempotencyService::class)->release('iyzico', $eventId);
            }

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    /**
     * X-IYZ-SIGNATURE-V3 over merchantId + secretKey + event fields, keyed by the secret.
     * The merchant id is not in the payload, which is why it is a stored credential.
     */
    private function verifyWebhook(Request $request, string $eventType, string $subscriptionReference, string $orderReference, string $customerReference): bool
    {
        if ($this->merchantId === '') {
            if (app()->environment('production')) {
                Log::warning('iyzico merchant id not configured in production — webhook rejected');

                return false;
            }

            return true;
        }

        $signature = (string) $request->header('X-IYZ-SIGNATURE-V3', '');
        if ($signature === '') {
            return false;
        }

        $message = $this->merchantId.$this->secretKey.$eventType.$subscriptionReference.$orderReference.$customerReference;
        $expected = bin2hex(hash_hmac('sha256', $message, $this->secretKey, true));

        if (! hash_equals($expected, $signature)) {
            Log::warning('iyzico webhook signature verification failed', ['event' => $eventType]);

            return false;
        }

        return true;
    }

    // ─── Order → transaction reconciliation ─────────────────────────────────

    /**
     * Record any settled order on this subscription as a payment transaction. Orders are the
     * only place a payment id surfaces, so this is also what makes refunds possible later.
     */
    private function syncOrders(Subscription $subscription, ?string $onlyOrderReference = null): void
    {
        $detail = $this->subscriptionDetail($subscription->gateway_subscription_id);
        if ($detail === null) {
            return;
        }

        $orders = $detail['orders'] ?? [];
        if (! is_array($orders)) {
            return;
        }

        foreach ($orders as $order) {
            $reference = $order['referenceCode'] ?? '';
            if (! $reference || ($onlyOrderReference && $reference !== $onlyOrderReference)) {
                continue;
            }
            if (($order['orderStatus'] ?? '') !== 'SUCCESS') {
                continue;
            }
            if (PaymentTransaction::where('gateway', 'iyzico')->where('gateway_transaction_id', $reference)->exists()) {
                continue;
            }

            $amountCents = (int) round(((float) ($order['price'] ?? 0)) * 100);
            $currency = strtoupper($order['currencyCode'] ?? 'TRY');

            // The signup charge is announced by SubscriptionStarted; only later orders are
            // renewals. Checked before the insert, while the count still excludes this one.
            $isRenewal = PaymentTransaction::where('subscription_id', $subscription->id)->exists();

            $transaction = PaymentTransaction::create([
                'user_id' => $subscription->user_id,
                'subscription_id' => $subscription->id,
                'gateway' => 'iyzico',
                'gateway_transaction_id' => $reference,
                'amount_cents' => $amountCents,
                'currency_code' => $currency,
                'status' => 'paid',
                'payload' => [
                    'order' => $order,
                    // Refunds are issued against the payment, not the order.
                    'payment_id' => $this->paymentIdOf($order),
                ],
            ]);
            $this->generateInvoicePdf($transaction);

            $subscription->update([
                'status' => 'active',
                'renews_at' => $this->periodEnd($order) ?? $this->nextRenewal($subscription->billing_cycle),
            ]);

            $subscription->refresh()->loadMissing('user', 'plan');
            if ($isRenewal && $subscription->user && $subscription->plan) {
                SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
            }
        }
    }

    /** The detail endpoint is documented as paginated but responds flat; tolerate both. */
    private function subscriptionDetail(string $reference): ?array
    {
        $res = $this->send('GET', '/v2/subscription/subscriptions/'.$reference);
        if (! $this->ok($res)) {
            Log::warning('iyzico subscription detail failed', ['reference' => $reference, 'body' => $res->json()]);

            return null;
        }

        $data = $res->json('data') ?? [];

        return $data['items'][0] ?? $data;
    }

    private function paymentIdOf(array $order): ?string
    {
        foreach ($order['paymentAttempts'] ?? [] as $attempt) {
            if (($attempt['paymentStatus'] ?? '') === 'SUCCESS' && ! empty($attempt['paymentId'])) {
                return (string) $attempt['paymentId'];
            }
        }

        return null;
    }

    private function periodEnd(array $order): ?Carbon
    {
        $endPeriod = $order['endPeriod'] ?? null;

        return $endPeriod ? Carbon::createFromTimestampMs((int) $endPeriod) : null;
    }

    private function nextRenewal(string $billingCycle): Carbon
    {
        return $billingCycle === 'year' ? now()->addYear() : now()->addMonth();
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'ACTIVE' => 'active',
            'PENDING' => 'incomplete',
            'UNPAID' => 'past_due',
            'CANCELED', 'EXPIRED', 'UPGRADED' => 'canceled',
            default => 'incomplete',
        };
    }

    // ─── Lifecycle ──────────────────────────────────────────────────────────

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'iyzico' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->send('POST', '/v2/subscription/subscriptions/'.$subscription->gateway_subscription_id.'/cancel', [
            'locale' => 'en',
            'subscriptionReferenceCode' => $subscription->gateway_subscription_id,
        ]);

        if ($this->ok($res)) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        Log::error('iyzico cancel failed', ['subscription_id' => $subscription->id, 'body' => $res->json()]);

        return false;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'iyzico' || ! $this->isConfigured()) {
            return false;
        }

        $detail = $this->subscriptionDetail($subscription->gateway_subscription_id);
        if ($detail === null) {
            return false;
        }

        $subscription->update(['status' => $this->mapStatus($detail['subscriptionStatus'] ?? '')]);
        $this->syncOrders($subscription);

        return true;
    }

    /**
     * iyzico upgrades only within the same product and the same billing interval, and mint a
     * brand-new subscription rather than mutating the old one.
     */
    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        if ($subscription->gateway !== 'iyzico' || ! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'iyzico is not configured.'];
        }

        if ($billingCycle !== $subscription->billing_cycle) {
            return ['ok' => false, 'error' => 'iyzico cannot switch billing interval on an existing subscription — cancel and resubscribe instead.'];
        }

        $priceCents = $newPlan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['ok' => false, 'error' => 'New plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($newPlan->currency_code ?? 'TRY');
        if (! in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            return ['ok' => false, 'error' => 'iyzico subscriptions support only TRY, USD and EUR.'];
        }

        $pricingPlanReference = $this->resolvePricingPlanReference($newPlan, $billingCycle, $priceCents, $currency);
        if (! $pricingPlanReference) {
            return ['ok' => false, 'error' => 'iyzico pricing plan creation failed.'];
        }

        $res = $this->send('POST', '/v2/subscription/subscriptions/'.$subscription->gateway_subscription_id.'/upgrade', [
            'locale' => 'en',
            'subscriptionReferenceCode' => $subscription->gateway_subscription_id,
            'newPricingPlanReferenceCode' => $pricingPlanReference,
            'upgradePeriod' => 'NOW',
            'useTrial' => false,
            'resetRecurrenceCount' => false,
        ]);

        if (! $this->ok($res)) {
            Log::error('iyzico upgrade failed', ['subscription_id' => $subscription->id, 'body' => $res->json()]);

            return ['ok' => false, 'error' => $this->errorOf($res, 'iyzico plan change failed.')];
        }

        $data = $res->json('data') ?? [];
        $newReference = $data['referenceCode'] ?? $subscription->gateway_subscription_id;

        $subscription->update([
            'plan_id' => $newPlan->id,
            'billing_cycle' => $billingCycle,
            // The old reference is retired; follow the subscription to its new identity.
            'gateway_subscription_id' => $newReference,
            'status' => $this->mapStatus($data['subscriptionStatus'] ?? 'ACTIVE'),
            'gateway_metadata' => array_merge($subscription->gateway_metadata ?? [], [
                'pricing_plan_reference_code' => $pricingPlanReference,
                'parent_reference_code' => $data['parentReferenceCode'] ?? null,
            ]),
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Refunds go through the v1 payment API, which needs a paymentTransactionId — two hops
     * from the payment id we recorded with the order.
     */
    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'iyzico is not configured.'];
        }

        $paymentId = $transaction->payload['payment_id'] ?? null;
        if (! $paymentId) {
            return ['ok' => false, 'error' => 'No iyzico payment id recorded for this transaction — refund from the iyzico dashboard.'];
        }

        $paymentTransactionId = $this->paymentTransactionId((string) $paymentId);
        if (! $paymentTransactionId) {
            return ['ok' => false, 'error' => 'iyzico payment detail lookup failed — refund from the iyzico dashboard.'];
        }

        $cents = $amountCents ?? $transaction->amount_cents;

        $res = $this->send('POST', '/payment/refund', [
            'locale' => 'en',
            'conversationId' => (string) $transaction->id,
            'paymentTransactionId' => $paymentTransactionId,
            'price' => $this->price($cents),
            'currency' => strtoupper($transaction->currency_code ?? 'TRY'),
            'ip' => request()->ip() ?? '127.0.0.1',
        ]);

        if (! $this->ok($res)) {
            Log::error('iyzico refund failed', ['transaction_id' => $transaction->id, 'body' => $res->json()]);

            return ['ok' => false, 'error' => $this->errorOf($res, 'iyzico refund failed.')];
        }

        $transaction->update([
            'refunded_at' => now(),
            'refunded_cents' => $cents,
            'status' => 'refunded',
        ]);

        return ['ok' => true, 'error' => null];
    }

    private function paymentTransactionId(string $paymentId): ?string
    {
        $res = $this->send('POST', '/payment/detail', [
            'locale' => 'en',
            'paymentId' => $paymentId,
        ]);

        if (! $this->ok($res)) {
            Log::warning('iyzico payment detail failed', ['payment_id' => $paymentId, 'body' => $res->json()]);

            return null;
        }

        $id = $res->json('itemTransactions.0.paymentTransactionId');

        return $id ? (string) $id : null;
    }

    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('iyzico: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
