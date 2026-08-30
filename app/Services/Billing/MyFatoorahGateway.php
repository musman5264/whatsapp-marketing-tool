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
 * MyFatoorah recurring billing via card tokenization (merchant-initiated transactions).
 *
 * Supported countries: Kuwait, Saudi Arabia, UAE, Bahrain, Oman, Qatar, Jordan.
 *
 * Flow:
 *   1. Call SendPayment to create a hosted invoice and redirect the customer.
 *   2. Customer pays → MyFatoorah redirects to the CallBackUrl with ?paymentId=<id>.
 *   3. fulfillCheckoutSession() calls GetPaymentStatus to retrieve the
 *      CustomerPaymentToken (card token) and activates the local subscription.
 *   4. Renewals: billing:charge-recurring-myfatoorah scheduler calls chargeRecurring(),
 *      which uses DirectPayment with the saved token (no customer interaction).
 *
 * MyFatoorah does not sign webhook payloads — instead the callback URL is your
 * success URL, and the paymentId query param is used to verify via GetPaymentStatus.
 *
 * Amounts are in MAJOR currency units (e.g. 10.500 for 10.5 KWD, not piastres).
 * The app stores `amount_cents` (×100); convert to major units for API calls.
 *
 * Currencies with 3 decimal places: KWD, BHD, OMR, JOD, TND.
 */
class MyFatoorahGateway implements BillingGatewayInterface
{
    private const PROD_URL = 'https://api.myfatoorah.com';

    private const TEST_URL = 'https://apitest.myfatoorah.com';

    /** Currencies that use 3 decimal places (ISO 4217 minor unit = 1000). */
    private const THREE_DECIMAL_CURRENCIES = ['KWD', 'BHD', 'OMR', 'JOD', 'TND', 'LYD'];

    private string $baseUrl;

    public function __construct(
        private string $apiKey,
        private bool $sandbox,
        private string $successUrl,
        private string $cancelUrl,
    ) {
        $this->baseUrl = $sandbox ? self::TEST_URL : self::PROD_URL;
    }

    public function name(): string
    {
        return 'MyFatoorah';
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

    /** Convert app amount_cents to MyFatoorah major-unit decimal. */
    private function majorAmount(int $cents, string $currency): float
    {
        $decimals = in_array(strtoupper($currency), self::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2;

        return round($cents / 100, $decimals);
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'MyFatoorah is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($plan->currency_code ?? 'KWD');
        $amount = $this->majorAmount($priceCents, $currency);
        $name = $user->name ?? 'Customer';

        // Encode metadata in UserDefinedField (256 char limit).
        $userField = http_build_query([
            'uid' => $user->id,
            'pid' => $plan->id,
            'bc' => $billingCycle,
        ]);

        $body = [
            'NotificationOption' => 'LNK',
            'InvoiceValue' => $amount,
            'CurrencyIso' => $currency,
            'CustomerName' => $name,
            'CustomerEmail' => $user->email ?? '',
            'CallBackUrl' => $this->successUrl,
            'ErrorUrl' => $this->cancelUrl,
            'Language' => 'en',
            'UserDefinedField' => $userField,
            'DisplayCurrencyIso' => $currency,
        ];

        $res = $this->http()->post($this->baseUrl.'/v2/SendPayment', $body);

        if (! $res->successful() || ! $res->json('IsSuccess')) {
            Log::error('MyFatoorah SendPayment failed', ['body' => $res->json(), 'user_id' => $user->id]);
            $msg = $res->json('ValidationErrors.0.Error')
                ?? $res->json('Message')
                ?? 'MyFatoorah payment creation failed.';

            return ['error' => $msg];
        }

        $invoiceId = $res->json('Data.InvoiceId');
        $invoiceUrl = $res->json('Data.InvoiceURL');

        if (! $invoiceUrl) {
            return ['error' => 'No invoice URL in MyFatoorah response.'];
        }

        return ['url' => $invoiceUrl, 'subscription_id' => (string) $invoiceId];
    }

    /**
     * Called when customer returns to the success URL.
     * MyFatoorah appends ?paymentId=<id> to the CallBackUrl.
     */
    public function fulfillCheckoutSession(string $sessionId): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'MyFatoorah is not configured.'];
        }

        // $sessionId is the paymentId query param from the callback URL.
        $res = $this->http()->post($this->baseUrl.'/v2/GetPaymentStatus', [
            'Key' => $sessionId,
            'KeyType' => 'PaymentId',
        ]);

        if (! $res->successful() || ! $res->json('IsSuccess')) {
            Log::error('MyFatoorah GetPaymentStatus failed', ['payment_id' => $sessionId, 'body' => $res->json()]);

            return ['ok' => false, 'error' => 'Could not verify MyFatoorah payment status.'];
        }

        $data = $res->json('Data', []);
        $invoiceStatus = strtoupper($data['InvoiceStatus'] ?? '');
        $invoiceId = (string) ($data['InvoiceId'] ?? $sessionId);

        if ($invoiceStatus !== 'PAID') {
            return ['ok' => false, 'error' => 'Payment not yet completed (status: '.$invoiceStatus.').'];
        }

        // Extract card token from the first transaction.
        $transactions = $data['InvoiceTransactions'] ?? [];
        $cardToken = null;
        $amountCents = 0;
        $currency = strtoupper($data['CurrencyIso'] ?? 'KWD');

        foreach ($transactions as $tx) {
            if (strtoupper($tx['TransactionStatus'] ?? '') === 'SUCCSS' || strtoupper($tx['TransactionStatus'] ?? '') === 'SUCCESS') {
                $cardToken = $tx['CustomerPaymentToken'] ?? $tx['RecurringModel']['Token'] ?? null;
                $paid = (float) ($tx['PaidCurrencyValue'] ?? $tx['TransactionValue'] ?? 0);
                $paidCurrency = strtoupper($tx['PaidCurrency'] ?? $currency);
                $decimals = in_array($paidCurrency, self::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2;
                $amountCents = (int) round($paid * (10 ** $decimals));
                $currency = $paidCurrency;
                break;
            }
        }

        // Recover metadata from UserDefinedField.
        $userField = $data['UserDefinedField'] ?? '';
        parse_str($userField, $meta);
        $userId = (int) ($meta['uid'] ?? 0);
        $planId = (int) ($meta['pid'] ?? 0);
        $billingCycle = $meta['bc'] ?? 'month';

        if (! $userId || ! $planId) {
            return ['ok' => false, 'error' => 'Missing user/plan metadata in MyFatoorah response.'];
        }

        $isNew = ! Subscription::where('gateway', 'myfatoorah')
            ->where('gateway_subscription_id', $invoiceId)
            ->exists();

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'myfatoorah', 'gateway_subscription_id' => $invoiceId],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => $billingCycle === 'year' ? now()->addYear() : now()->addMonth(),
                'gateway_metadata' => [
                    'invoice_id' => $invoiceId,
                    'card_token' => $cardToken,
                    'currency' => $currency,
                ],
            ]
        );

        // Record the transaction.
        if ($amountCents > 0 && ! PaymentTransaction::where('gateway', 'myfatoorah')->where('gateway_transaction_id', $sessionId)->exists()) {
            $transaction = PaymentTransaction::create([
                'user_id' => $userId,
                'subscription_id' => $subscription->id,
                'gateway' => 'myfatoorah',
                'gateway_transaction_id' => $sessionId,
                'amount_cents' => $amountCents,
                'currency_code' => $currency,
                'status' => 'paid',
                'payload' => $data,
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

        return ['ok' => true, 'error' => null, 'subscription' => $subscription];
    }

    public function handleWebhook(Request $request): Response
    {
        // MyFatoorah sends payment status updates via a POST webhook.
        // There is no HMAC to verify — rely on the paymentId lookup.
        $data = $request->json()->all();
        $invoiceId = (string) ($data['InvoiceId'] ?? $data['Data']['InvoiceId'] ?? '');
        $status = strtoupper($data['InvoiceStatus'] ?? $data['Data']['InvoiceStatus'] ?? '');

        if (! $invoiceId) {
            return new Response('OK', 200);
        }

        if (! app(WebhookIdempotencyService::class)->isNewEvent('myfatoorah', $invoiceId.'_'.$status)) {
            return new Response('OK', 200);
        }

        try {
            match ($status) {
                'PAID' => $this->handleWebhookPaid($invoiceId, $data),
                'FAILED', 'EXPIRED' => $this->handleWebhookFailed($invoiceId),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('MyFatoorah webhook handler failed', ['invoice_id' => $invoiceId, 'error' => $e->getMessage()]);
            app(WebhookIdempotencyService::class)->release('myfatoorah', $invoiceId.'_'.$status);

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    private function handleWebhookPaid(string $invoiceId, array $data): void
    {
        // If the subscription already exists (fulfillCheckoutSession ran), update status.
        $subscription = Subscription::where('gateway', 'myfatoorah')
            ->where('gateway_subscription_id', $invoiceId)
            ->first();

        if ($subscription) {
            $subscription->update(['status' => 'active']);
        }

        // For renewal webhooks, MyFatoorah sends a new InvoiceId with the same UserDefinedField.
        // The recurring charge command handles renewals, so we just update status here.
    }

    private function handleWebhookFailed(string $invoiceId): void
    {
        Subscription::where('gateway', 'myfatoorah')
            ->where('gateway_subscription_id', $invoiceId)
            ->update(['status' => 'past_due']);
    }

    /**
     * Charge the saved card token off-session (merchant-initiated renewal).
     * Called by billing:charge-recurring-myfatoorah scheduler.
     */
    public function chargeRecurring(Subscription $subscription): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'MyFatoorah is not configured.'];
        }

        $meta = $subscription->gateway_metadata ?? [];
        $cardToken = $meta['card_token'] ?? null;
        $currency = $meta['currency'] ?? 'KWD';

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

        $user = $subscription->user ?? User::find($subscription->user_id);
        $amount = $this->majorAmount($priceCents, $currency);

        $res = $this->http()->post($this->baseUrl.'/v2/DirectPayment', [
            'InvoiceValue' => $amount,
            'CurrencyIso' => $currency,
            'CustomerName' => $user?->name ?? 'Customer',
            'CustomerEmail' => $user?->email ?? '',
            'CustomerCivilId' => null,
            'CustomerMobile' => null,
            'Language' => 'en',
            'InitiatedBy' => 'Merchant',
            'Token' => $cardToken,
            'CallBackUrl' => $this->successUrl,
            'ErrorUrl' => $this->cancelUrl,
        ]);

        if (! $res->successful() || ! $res->json('IsSuccess')) {
            Log::error('MyFatoorah DirectPayment failed', [
                'subscription_id' => $subscription->id,
                'body' => $res->json(),
            ]);

            return ['ok' => false, 'error' => $res->json('Message', 'MyFatoorah recurring charge failed.')];
        }

        $invoiceId = (string) ($res->json('Data.InvoiceId') ?? '');

        if ($invoiceId && PaymentTransaction::where('gateway', 'myfatoorah')->where('gateway_transaction_id', $invoiceId)->exists()) {
            return ['ok' => true, 'error' => null];
        }

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'gateway' => 'myfatoorah',
            'gateway_transaction_id' => $invoiceId ?: ('mf_'.time()),
            'amount_cents' => $priceCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $res->json('Data', []),
        ]);

        $this->generateInvoicePdf($transaction);

        $subscription->update([
            'renews_at' => ($subscription->billing_cycle ?? 'month') === 'year' ? now()->addYear() : now()->addMonth(),
        ]);

        $subscription->refresh()->loadMissing('user', 'plan');
        if ($subscription->user && $subscription->plan) {
            SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $priceCents, $currency);
        }

        return ['ok' => true, 'error' => null];
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'myfatoorah') {
            return false;
        }
        // MyFatoorah MIT has no server-side subscription to cancel; stop scheduling.
        $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

        return true;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'myfatoorah' || ! $this->isConfigured()) {
            return false;
        }

        $meta = $subscription->gateway_metadata ?? [];
        $invoiceId = $meta['invoice_id'] ?? $subscription->gateway_subscription_id;

        if (! $invoiceId) {
            return false;
        }

        $res = $this->http()->post($this->baseUrl.'/v2/GetPaymentStatus', [
            'Key' => $invoiceId,
            'KeyType' => 'InvoiceId',
        ]);

        if (! $res->successful() || ! $res->json('IsSuccess')) {
            return false;
        }

        $status = strtoupper($res->json('Data.InvoiceStatus', ''));
        $mappedStatus = match ($status) {
            'PAID' => 'active',
            'FAILED', 'EXPIRED' => 'canceled',
            'PENDING' => 'incomplete',
            default => $subscription->status,
        };

        $subscription->update(['status' => $mappedStatus]);

        return true;
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        // Apply on next cycle; the recurring charge reads the live plan price.
        $subscription->update(['plan_id' => $newPlan->id, 'billing_cycle' => $billingCycle]);

        return ['ok' => true, 'error' => null];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        // MyFatoorah does not have a programmatic refund API for all regions.
        // Refunds must be initiated from the MyFatoorah dashboard.
        Log::warning('MyFatoorah: refund must be initiated from the MyFatoorah dashboard', [
            'transaction_id' => $transaction->id,
        ]);

        return ['ok' => false, 'error' => 'MyFatoorah refunds must be processed from the MyFatoorah merchant dashboard.'];
    }

    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('MyFatoorah: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
