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
 * Paystack recurring billing via the Subscriptions API.
 *
 * Flow: create a Plan on Paystack → initialize a Transaction with the plan code
 * (this is the hosted checkout) → Paystack auto-creates the subscription after the
 * first charge succeeds → subsequent charges happen on the plan interval with
 * webhooks notifying us of each renewal.
 *
 * Amounts are in the smallest currency subunit (kobo for NGN, pesewas for GHS,
 * cents for USD/ZAR/KES, etc.) — identical to the app's amount_cents convention.
 *
 * Webhook signature: HMAC-SHA512 of the raw request body keyed by the secret key,
 * delivered in the `x-paystack-signature` header.
 *
 * Cancellation uses Paystack's subscription disable endpoint which requires both
 * the subscription code and the email token (both stored in gateway_metadata).
 */
class PaystackGateway implements BillingGatewayInterface
{
    private const BASE_URL = 'https://api.paystack.co';

    public function __construct(
        private string $secretKey,
        private string $publicKey,
        private string $successUrl,
        private string $cancelUrl,
    ) {}

    public function name(): string
    {
        return 'Paystack';
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->secretKey)
            ->acceptJson()
            ->asJson();
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Paystack is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $currency = strtoupper($plan->currency_code ?? 'NGN');
        $interval = $billingCycle === 'year' ? 'annually' : 'monthly';

        // 1) Create a plan on Paystack (idempotent: same name+interval+amount reuse it).
        $planName = $plan->name.' ('.$billingCycle.')';
        $planRes = $this->http()->post(self::BASE_URL.'/plan', [
            'name' => $planName,
            'interval' => $interval,
            'amount' => $priceCents,
            'currency' => $currency,
            'send_invoices' => true,
            'send_sms' => false,
        ]);

        if (! $planRes->successful()) {
            Log::error('Paystack create plan failed', ['body' => $planRes->json(), 'user_id' => $user->id]);

            return ['error' => $planRes->json('message', 'Paystack plan creation failed.')];
        }

        $planCode = $planRes->json('data.plan_code');
        if (! $planCode) {
            return ['error' => 'No plan code in Paystack response.'];
        }

        // 2) Initialize a transaction with the plan code — this creates the hosted checkout.
        $email = $user->email ?? ('user'.$user->id.'@placeholder.invalid');

        $txBody = [
            'email' => $email,
            'amount' => $priceCents,
            'currency' => $currency,
            'plan' => $planCode,
            'callback_url' => $this->successUrl,
            'metadata' => [
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'billing_cycle' => $billingCycle,
                'gateway' => 'paystack',
            ],
        ];

        if (($plan->trial_days ?? 0) > 0) {
            $txBody['plan_trial_end'] = now()->addDays((int) $plan->trial_days)->format('Y-m-d');
        }

        $txRes = $this->http()->post(self::BASE_URL.'/transaction/initialize', $txBody);

        if (! $txRes->successful()) {
            Log::error('Paystack initialize transaction failed', ['body' => $txRes->json(), 'user_id' => $user->id]);

            return ['error' => $txRes->json('message', 'Paystack checkout initialization failed.')];
        }

        $url = $txRes->json('data.authorization_url');
        if (! $url) {
            return ['error' => 'No authorization URL in Paystack response.'];
        }

        return ['url' => $url];
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();

        // Verify HMAC-SHA512 signature.
        $sig = $request->header('x-paystack-signature', '');
        $expected = hash_hmac('sha512', $payload, $this->secretKey);
        if (! hash_equals($expected, $sig)) {
            Log::warning('Paystack webhook signature verification failed');

            return new Response('Invalid signature', 401);
        }

        $data = json_decode($payload, true) ?: [];
        $event = $data['event'] ?? '';
        $eventId = $data['data']['id'] ?? null;

        if ($eventId && ! app(WebhookIdempotencyService::class)->isNewEvent('paystack', (string) $eventId.'_'.$event)) {
            return new Response('OK', 200);
        }

        try {
            match ($event) {
                'subscription.create' => $this->handleSubscriptionCreate($data['data'] ?? []),
                'charge.success' => $this->handleChargeSuccess($data['data'] ?? []),
                'subscription.disable', 'subscription.not_renew' => $this->handleSubscriptionDisabled($data['data'] ?? []),
                'invoice.payment_failed' => $this->handleInvoiceFailed($data['data'] ?? []),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Paystack webhook handler failed', ['event' => $event, 'error' => $e->getMessage()]);
            if ($eventId) {
                app(WebhookIdempotencyService::class)->release('paystack', (string) $eventId.'_'.$event);
            }

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    private function handleSubscriptionCreate(array $sub): void
    {
        $subCode = $sub['subscription_code'] ?? '';
        $emailToken = $sub['email_token'] ?? '';
        $metadata = $sub['metadata'] ?? [];
        $userId = (int) ($metadata['user_id'] ?? 0);
        $planId = (int) ($metadata['plan_id'] ?? 0);
        $billingCycle = $metadata['billing_cycle'] ?? 'month';

        // Try to recover user/plan from the customer email if metadata is missing.
        if (! $userId && isset($sub['customer']['email'])) {
            $u = User::where('email', $sub['customer']['email'])->first();
            $userId = $u?->id ?? 0;
        }

        if (! $subCode || ! $userId) {
            return;
        }

        $nextPaymentDate = isset($sub['next_payment_date'])
            ? Carbon::parse($sub['next_payment_date'])
            : now()->addMonth();

        $isNew = ! Subscription::where('gateway', 'paystack')
            ->where('gateway_subscription_id', $subCode)
            ->exists();

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'paystack', 'gateway_subscription_id' => $subCode],
            [
                'user_id' => $userId,
                'plan_id' => $planId ?: null,
                'billing_cycle' => $billingCycle,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => $nextPaymentDate,
                'gateway_metadata' => [
                    'subscription_code' => $subCode,
                    'email_token' => $emailToken,
                    'currency' => strtoupper($sub['plan']['currency'] ?? 'NGN'),
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

    private function handleChargeSuccess(array $charge): void
    {
        $txRef = $charge['reference'] ?? '';
        $subCode = $charge['subscription_code'] ?? ($charge['plan_object']['subscription_code'] ?? '');

        // Only process charges that belong to a subscription.
        if (! $subCode && empty($charge['plan'])) {
            return;
        }

        // Look up local subscription.
        $subscription = Subscription::where('gateway', 'paystack')
            ->where('gateway_subscription_id', $subCode)
            ->with('user', 'plan')
            ->first();

        if (! $subscription) {
            // Subscription may not yet exist (charge fires before subscription.create in some cases).
            $this->handleSubscriptionCreate($charge['subscription_data'] ?? []);
            $subscription = Subscription::where('gateway', 'paystack')
                ->where('gateway_subscription_id', $subCode)
                ->with('user', 'plan')
                ->first();
        }

        if (PaymentTransaction::where('gateway', 'paystack')->where('gateway_transaction_id', $txRef)->exists()) {
            return;
        }

        $isRenewal = $subscription && PaymentTransaction::where('gateway', 'paystack')
            ->where('subscription_id', $subscription->id)
            ->where('status', 'paid')
            ->exists();

        $amountCents = (int) ($charge['amount'] ?? 0);
        $currency = strtoupper($charge['currency'] ?? 'NGN');

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription?->user_id,
            'subscription_id' => $subscription?->id,
            'gateway' => 'paystack',
            'gateway_transaction_id' => $txRef,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $charge,
        ]);

        $this->generateInvoicePdf($transaction);

        if ($subscription) {
            $nextDate = isset($charge['subscription_data']['next_payment_date'])
                ? Carbon::parse($charge['subscription_data']['next_payment_date'])
                : now()->addMonth();
            $subscription->update(['renews_at' => $nextDate, 'status' => 'active']);

            if ($isRenewal && $subscription->user && $subscription->plan) {
                SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
            }
        }
    }

    private function handleSubscriptionDisabled(array $sub): void
    {
        $subCode = $sub['subscription_code'] ?? '';
        if (! $subCode) {
            return;
        }
        Subscription::where('gateway', 'paystack')
            ->where('gateway_subscription_id', $subCode)
            ->update(['status' => 'canceled', 'ends_at' => now()]);
    }

    private function handleInvoiceFailed(array $invoice): void
    {
        $subCode = $invoice['subscription']['subscription_code'] ?? '';
        if (! $subCode) {
            return;
        }
        Subscription::where('gateway', 'paystack')
            ->where('gateway_subscription_id', $subCode)
            ->update(['status' => 'past_due']);
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'paystack' || ! $this->isConfigured()) {
            return false;
        }

        $meta = $subscription->gateway_metadata ?? [];
        $code = $meta['subscription_code'] ?? $subscription->gateway_subscription_id;
        $token = $meta['email_token'] ?? '';

        if (! $code || ! $token) {
            Log::warning('Paystack cancel: missing subscription_code or email_token', ['subscription_id' => $subscription->id]);
            // Mark locally even if we can't call the API.
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        $res = $this->http()->post(self::BASE_URL.'/subscription/disable', [
            'code' => $code,
            'token' => $token,
        ]);

        if ($res->successful()) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        Log::error('Paystack cancel failed', ['subscription_id' => $subscription->id, 'body' => $res->json()]);

        return false;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'paystack' || ! $this->isConfigured()) {
            return false;
        }

        $code = $subscription->gateway_subscription_id;
        $res = $this->http()->get(self::BASE_URL.'/subscription/'.$code);
        if (! $res->successful()) {
            return false;
        }

        $data = $res->json('data', []);
        $nextDate = isset($data['next_payment_date']) ? Carbon::parse($data['next_payment_date']) : $subscription->renews_at;

        $subscription->update([
            'status' => $this->mapStatus($data['status'] ?? ''),
            'renews_at' => $nextDate,
        ]);

        return true;
    }

    private function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'active' => 'active',
            'non-renewing' => 'canceled',
            'attention' => 'past_due',
            'cancelled', 'canceled', 'completed' => 'canceled',
            default => 'incomplete',
        };
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        // Paystack does not expose a plan-change API; cancel and re-subscribe.
        return ['ok' => false, 'error' => 'Plan changes for Paystack require cancelling and re-subscribing.'];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Paystack is not configured.'];
        }

        $txRef = $transaction->gateway_transaction_id;
        if (! $txRef) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        $body = ['transaction' => $txRef];
        if ($amountCents) {
            $body['amount'] = $amountCents;
        }

        $res = $this->http()->post(self::BASE_URL.'/refund', $body);

        if (! $res->successful()) {
            Log::error('Paystack refund failed', ['transaction_id' => $transaction->id, 'body' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('message', 'Paystack refund failed.')];
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
        return ['ok' => false, 'error' => 'Paystack fulfillment is handled via webhook.'];
    }

    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Paystack: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
