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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tap Payments (MENA/GCC) recurring billing.
 *
 * Tap has no hosted auto-renewing subscription product, so recurring is implemented as
 * merchant-initiated transactions (MIT):
 *
 *   1. First charge: a hosted Charge with save_card=true + 3DS. The customer is redirected
 *      to transaction.url. On the CAPTURED webhook we persist the saved-card identifiers
 *      (card_id, customer_id, payment_agreement_id) on the subscription and set renews_at.
 *   2. Renewals: the billing:charge-recurring scheduler calls chargeRecurring() for each
 *      Tap subscription whose renews_at has passed. That mints a fresh token from the saved
 *      card and creates an off-session charge (customer_initiated=false, threeDSecure=false,
 *      payment_agreement.id) — no customer interaction.
 *
 * Amounts are in MAJOR units with currency-specific precision (3 decimals for KWD/BHD/OMR).
 * Webhooks are verified with Tap's `hashstring` HMAC-SHA256 keyed by the secret API key.
 *
 * NOTE: Tap's `hashstring` field order and the exact JSON paths for reference.gateway /
 * reference.payment / transaction.created should be confirmed against a real sandbox
 * webhook before going live (Tap's docs render these in an interactive widget). The
 * computed-vs-received hash is logged at debug level in non-production to ease that check.
 */
class TapGateway implements BillingGatewayInterface
{
    private const BASE_URL = 'https://api.tap.company/v2';

    /** Currencies that use 3 decimal places (ISO 4217 minor unit = 1000). */
    private const THREE_DECIMAL_CURRENCIES = ['KWD', 'BHD', 'OMR', 'JOD', 'TND', 'LYD'];

    public function __construct(
        private string $secretKey,
        private string $successUrl,
        private string $cancelUrl,
        private string $webhookUrl,
    ) {}

    public function name(): string
    {
        return 'Tap';
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->secretKey)->acceptJson()->asJson();
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Tap is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($plan->currency_code ?? 'KWD');

        $res = $this->http()->post(self::BASE_URL.'/charges', [
            'amount' => $this->majorAmount($priceCents, $currency),
            'currency' => $currency,
            'threeDSecure' => true,
            'save_card' => true,
            'description' => $plan->name.' subscription ('.$billingCycle.')',
            'metadata' => [
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'billing_cycle' => $billingCycle,
                'kind' => 'subscription_initial',
            ],
            'reference' => ['transaction' => 'init_'.$user->id.'_'.now()->timestamp],
            'receipt' => ['email' => false, 'sms' => false],
            'customer' => [
                'first_name' => $user->name ?: ('User '.$user->id),
                'email' => $user->email,
            ],
            'source' => ['id' => 'src_all'],
            'post' => ['url' => $this->webhookUrl],
            'redirect' => ['url' => $this->successUrl],
        ]);

        if (! $res->successful()) {
            Log::error('Tap create charge failed', ['body' => $res->json(), 'user_id' => $user->id]);

            return ['error' => data_get($res->json(), 'errors.0.description', 'Tap charge creation failed.')];
        }

        $url = $res->json('transaction.url');
        if (! $url) {
            return ['error' => 'No payment URL in Tap response.'];
        }

        return ['url' => $url, 'charge_id' => $res->json('id')];
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $charge = json_decode($payload, true) ?: [];

        $hashstring = $request->header('hashstring', '');
        if (! $this->verifySignature($charge, $hashstring)) {
            Log::warning('Tap webhook signature verification failed', ['charge_id' => $charge['id'] ?? null]);

            return new Response('Invalid signature', 401);
        }

        $chargeId = $charge['id'] ?? null;
        $status = strtoupper($charge['status'] ?? '');
        $eventKey = $chargeId ? $chargeId.'_'.$status : null;

        if ($eventKey && ! app(WebhookIdempotencyService::class)->isNewEvent('tap', $eventKey)) {
            return new Response('OK', 200);
        }

        try {
            if (in_array($status, ['CAPTURED', 'AUTHORIZED'], true)) {
                $this->handleChargeCaptured($charge);
            }
            // FAILED/DECLINED first charges create no subscription — nothing to persist.
        } catch (\Throwable $e) {
            Log::error('Tap webhook handler failed', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
            if ($eventKey) {
                app(WebhookIdempotencyService::class)->release('tap', $eventKey);
            }

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    /** Verify Tap's `hashstring` header for a charge payload. */
    private function verifySignature(array $charge, string $hashstring): bool
    {
        if ($hashstring === '' || empty($charge['id'])) {
            return false;
        }

        $currency = strtoupper($charge['currency'] ?? '');
        $amount = $this->formatAmountString((float) ($charge['amount'] ?? 0), $currency);

        $toHash = 'x_id'.($charge['id'] ?? '')
            .'x_amount'.$amount
            .'x_currency'.$currency
            .'x_gateway_reference'.data_get($charge, 'reference.gateway', '')
            .'x_payment_reference'.data_get($charge, 'reference.payment', '')
            .'x_status'.($charge['status'] ?? '')
            .'x_created'.data_get($charge, 'transaction.created', '');

        $expected = hash_hmac('sha256', $toHash, $this->secretKey);

        if (! app()->environment('production')) {
            Log::debug('Tap signature check', ['expected' => $expected, 'received' => $hashstring, 'to_hash' => $toHash]);
        }

        return hash_equals($expected, $hashstring);
    }

    private function handleChargeCaptured(array $charge): void
    {
        $metadata = $charge['metadata'] ?? [];
        $kind = $metadata['kind'] ?? 'subscription_initial';

        if ($kind === 'subscription_initial') {
            $subscription = $this->activateFromInitialCharge($charge, $metadata);
            if ($subscription) {
                $this->recordChargePayment($charge, $subscription, isInitial: true);
            }

            return;
        }

        // Recurring charge (created by the scheduler) — resolve by the local subscription id.
        $localId = (int) ($metadata['subscription_local_id'] ?? 0);
        $subscription = $localId
            ? Subscription::where('gateway', 'tap')->with('user', 'plan')->find($localId)
            : null;

        if (! $subscription) {
            Log::warning('Tap recurring charge captured but subscription not resolved', ['charge_id' => $charge['id'] ?? null]);

            return;
        }

        $this->recordChargePayment($charge, $subscription, isInitial: false);
    }

    /** Create/activate the subscription and persist the saved-card identifiers. */
    private function activateFromInitialCharge(array $charge, array $metadata): ?Subscription
    {
        $userId = (int) ($metadata['user_id'] ?? 0);
        $planId = (int) ($metadata['plan_id'] ?? 0);
        $billingCycle = $metadata['billing_cycle'] ?? 'month';
        if (! $userId || ! $planId) {
            return null;
        }

        $saved = $this->extractSavedCard($charge);
        // The webhook payload may omit the payment agreement / saved-card ids. Those are
        // required to bill future cycles, so fetch the authoritative charge when missing.
        if (! $saved['payment_agreement_id'] || ! $saved['card_id'] || ! $saved['customer_id']) {
            $full = $this->fetchCharge($charge['id'] ?? '');
            if ($full) {
                $fetched = $this->extractSavedCard($full);
                foreach ($saved as $k => $v) {
                    if (! $v && ! empty($fetched[$k])) {
                        $saved[$k] = $fetched[$k];
                    }
                }
            }
        }

        if (! $saved['payment_agreement_id'] || ! $saved['card_id'] || ! $saved['customer_id']) {
            Log::warning('Tap: saved-card details incomplete after capture — recurring renewals may fail', [
                'charge_id' => $charge['id'] ?? null,
                'have' => array_keys(array_filter($saved)),
            ]);
        }

        // Use the payment agreement as the stable subscription identifier; fall back to the charge id.
        $gatewaySubId = $saved['payment_agreement_id'] ?: ('tap_'.($charge['id'] ?? ''));

        $isNew = ! Subscription::where('gateway', 'tap')
            ->where('gateway_subscription_id', $gatewaySubId)
            ->exists();

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'tap', 'gateway_subscription_id' => $gatewaySubId],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => $this->nextRenewal($billingCycle),
                'gateway_metadata' => array_merge($saved, [
                    'currency' => strtoupper($charge['currency'] ?? 'KWD'),
                ]),
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

    /**
     * Record a captured charge as a paid transaction exactly once. The first actor to
     * insert the row (webhook or synchronous scheduler response) advances renews_at and
     * fires the renewal event; the unique (gateway, gateway_transaction_id) index plus this
     * existence check make that idempotent.
     */
    private function recordChargePayment(array $charge, Subscription $subscription, bool $isInitial): void
    {
        $chargeId = $charge['id'] ?? '';
        if (! $chargeId) {
            return;
        }

        if (PaymentTransaction::where('gateway', 'tap')->where('gateway_transaction_id', $chargeId)->exists()) {
            return;
        }

        $currency = strtoupper($charge['currency'] ?? ($subscription->gateway_metadata['currency'] ?? 'KWD'));
        $amountCents = $this->minorAmount((float) ($charge['amount'] ?? 0), $currency);

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'gateway' => 'tap',
            'gateway_transaction_id' => $chargeId,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $charge,
        ]);

        $this->generateInvoicePdf($transaction);

        if (! $isInitial) {
            $subscription->update(['renews_at' => $this->nextRenewal($subscription->billing_cycle ?? 'month')]);
            $subscription->loadMissing('user', 'plan');
            if ($subscription->user && $subscription->plan) {
                SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
            }
        }
    }

    /**
     * Charge a saved card off-session for the next billing cycle. Called by the
     * billing:charge-recurring scheduler. Returns ['ok' => bool, 'error' => ?string].
     */
    public function chargeRecurring(Subscription $subscription): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Tap is not configured.'];
        }

        $meta = $subscription->gateway_metadata ?? [];
        $cardId = $meta['card_id'] ?? null;
        $customerId = $meta['customer_id'] ?? null;
        $agreementId = $meta['payment_agreement_id'] ?? null;
        if (! $cardId || ! $customerId || ! $agreementId) {
            return ['ok' => false, 'error' => 'Missing saved-card details for recurring charge.'];
        }

        $plan = $subscription->plan ?? Plan::find($subscription->plan_id);
        if (! $plan) {
            return ['ok' => false, 'error' => 'Plan not found for subscription.'];
        }

        $priceCents = $plan->priceCentsForCycle($subscription->billing_cycle ?? 'month');
        if ($priceCents === null || $priceCents <= 0) {
            return ['ok' => false, 'error' => 'Plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($meta['currency'] ?? ($plan->currency_code ?? 'KWD'));

        // 1) Mint a single-use token from the saved card (tokens expire in ~5 minutes).
        $tokenRes = $this->http()->post(self::BASE_URL.'/tokens', [
            'saved_card' => ['card_id' => $cardId, 'customer_id' => $customerId],
        ]);
        if (! $tokenRes->successful() || ! $tokenRes->json('id')) {
            Log::error('Tap token from saved card failed', ['subscription_id' => $subscription->id, 'body' => $tokenRes->json()]);

            return ['ok' => false, 'error' => 'Could not tokenize the saved card.'];
        }

        // 2) Create the off-session (merchant-initiated) charge.
        $chargeRes = $this->http()->post(self::BASE_URL.'/charges', [
            'amount' => $this->majorAmount($priceCents, $currency),
            'currency' => $currency,
            'customer_initiated' => false,
            'threeDSecure' => false,
            'save_card' => false,
            'description' => ($plan->name).' renewal ('.($subscription->billing_cycle ?? 'month').')',
            'metadata' => [
                'user_id' => (string) $subscription->user_id,
                'plan_id' => (string) $subscription->plan_id,
                'billing_cycle' => $subscription->billing_cycle ?? 'month',
                'kind' => 'subscription_recurring',
                'subscription_local_id' => (string) $subscription->id,
            ],
            'reference' => ['transaction' => 'rnw_'.$subscription->id.'_'.now()->timestamp],
            'payment_agreement' => ['id' => $agreementId],
            'customer' => ['id' => $customerId],
            'source' => ['id' => $tokenRes->json('id')],
            'post' => ['url' => $this->webhookUrl],
        ]);

        if (! $chargeRes->successful()) {
            Log::error('Tap recurring charge failed', ['subscription_id' => $subscription->id, 'body' => $chargeRes->json()]);

            return ['ok' => false, 'error' => data_get($chargeRes->json(), 'errors.0.description', 'Tap recurring charge failed.')];
        }

        $status = strtoupper($chargeRes->json('status', ''));
        if (! in_array($status, ['CAPTURED', 'AUTHORIZED'], true)) {
            // Charge created but not captured (e.g. declined). Mark past_due so the scheduler
            // stops retrying every run; the hourly billing:sync / admin can revive it.
            $subscription->update(['status' => 'past_due']);

            return ['ok' => false, 'error' => 'Recurring charge not captured (status: '.$status.').'];
        }

        // Synchronous capture — record immediately. The webhook is then a redundant confirm.
        $subscription->loadMissing('user', 'plan');
        $this->recordChargePayment($chargeRes->json(), $subscription, isInitial: false);

        return ['ok' => true, 'error' => null];
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'tap') {
            return false;
        }

        // Tap has no server-side subscription object to cancel; stop billing locally so the
        // scheduler no longer charges the saved card.
        $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

        return true;
    }

    public function sync(Subscription $subscription): bool
    {
        // Tap subscription state is driven entirely by our own charges; nothing to pull.
        return false;
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        if ($subscription->gateway !== 'tap') {
            return ['ok' => false, 'error' => 'Not a Tap subscription.'];
        }

        // Apply on the next cycle: the recurring charge reads the live plan price each run.
        $subscription->update(['plan_id' => $newPlan->id, 'billing_cycle' => $billingCycle]);

        return ['ok' => true, 'error' => null];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Tap is not configured.'];
        }

        $chargeId = $transaction->gateway_transaction_id;
        if (! $chargeId) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        $currency = strtoupper($transaction->currency_code ?? 'KWD');
        $cents = $amountCents ?? $transaction->amount_cents;

        $res = $this->http()->post(self::BASE_URL.'/refunds', [
            'charge_id' => $chargeId,
            'amount' => $this->majorAmount($cents, $currency),
            'currency' => $currency,
            'reason' => 'requested_by_customer',
        ]);

        if (! $res->successful()) {
            Log::error('Tap refund failed', ['transaction_id' => $transaction->id, 'response' => $res->json()]);

            return ['ok' => false, 'error' => data_get($res->json(), 'errors.0.description', 'Tap refund failed.')];
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
        return ['ok' => false, 'error' => 'Tap fulfillment is handled via webhook.'];
    }

    /** Fetch the authoritative charge object from Tap (used to recover saved-card ids). */
    private function fetchCharge(string $chargeId): ?array
    {
        if ($chargeId === '') {
            return null;
        }
        try {
            $res = $this->http()->get(self::BASE_URL.'/charges/'.$chargeId);
            if ($res->successful()) {
                return $res->json();
            }
        } catch (\Throwable $e) {
            Log::warning('Tap fetchCharge failed', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /** Pull saved-card identifiers from a captured charge, checking known/likely paths. */
    private function extractSavedCard(array $charge): array
    {
        return [
            'card_id' => data_get($charge, 'card.id', '') ?: data_get($charge, 'source.id', ''),
            'customer_id' => data_get($charge, 'customer.id', ''),
            'payment_agreement_id' => data_get($charge, 'payment_agreement.id', '')
                ?: data_get($charge, 'card.payment_agreement.id', ''),
        ];
    }

    private function nextRenewal(string $billingCycle): Carbon
    {
        return $billingCycle === 'year' ? now()->addYear() : now()->addMonth();
    }

    private function currencyDecimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2;
    }

    /** Convert the app's amount_cents (×100) to Tap's major-unit decimal for the currency. */
    private function majorAmount(int $cents, string $currency): float
    {
        return round($cents / 100, $this->currencyDecimals($currency));
    }

    /** Convert a Tap major-unit amount back to the app's amount_cents (×100). */
    private function minorAmount(float $amount, string $currency): int
    {
        return (int) round($amount * 100);
    }

    /** Format a major-unit amount to the currency's fixed precision (for signature checks). */
    private function formatAmountString(float $amount, string $currency): string
    {
        return number_format($amount, $this->currencyDecimals($currency), '.', '');
    }

    /** Best-effort PDF invoice generation; never let it break webhook processing. */
    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Tap: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
