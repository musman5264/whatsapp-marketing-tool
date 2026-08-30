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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PayPalGateway implements BillingGatewayInterface
{
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private bool $sandbox,
        private string $successUrl,
        private string $cancelUrl,
        private string $webhookId
    ) {}

    public function name(): string
    {
        return 'PayPal';
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    protected function baseUrl(): string
    {
        return $this->sandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    protected function getAccessToken(): ?string
    {
        $res = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if (! $res->successful()) {
            Log::warning('PayPal token failed', ['body' => $res->body()]);

            return null;
        }

        return $res->json('access_token');
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'PayPal is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return ['error' => 'Could not obtain PayPal access token.'];
        }

        $amount = number_format($priceCents / 100, 2, '.', '');
        $interval = $billingCycle === 'year' ? 'YEAR' : 'MONTH';
        $currency = $plan->currency_code ?? 'USD';

        // 1) Create product
        $productRes = Http::withToken($token)
            ->post($this->baseUrl().'/v1/catalogs/products', [
                'name' => $plan->name,
                'description' => $plan->name.' subscription',
                'type' => 'SERVICE',
                'category' => 'SOFTWARE',
            ]);

        if (! $productRes->successful()) {
            Log::error('PayPal create product failed', ['body' => $productRes->json(), 'user_id' => $user->id]);

            return ['error' => $productRes->json('message', 'PayPal product creation failed.')];
        }

        $productId = $productRes->json('id');
        if (! $productId) {
            return ['error' => 'No product ID in PayPal response.'];
        }

        // 2) Create plan
        $planRes = Http::withToken($token)
            ->post($this->baseUrl().'/v1/billing/plans', [
                'product_id' => $productId,
                'name' => $plan->name.' ('.$interval.')',
                'description' => $plan->name,
                'billing_cycles' => [
                    [
                        'frequency' => ['interval_unit' => $interval, 'interval_count' => 1],
                        'tenure_type' => 'REGULAR',
                        'sequence' => 1,
                        'total_cycles' => 0,
                        'pricing_scheme' => [
                            'fixed_price' => ['value' => $amount, 'currency_code' => $currency],
                        ],
                    ],
                ],
            ]);

        if (! $planRes->successful()) {
            Log::error('PayPal create plan failed', ['body' => $planRes->json(), 'user_id' => $user->id]);

            return ['error' => $planRes->json('message', 'PayPal plan creation failed.')];
        }

        $paypalPlanId = $planRes->json('id');
        if (! $paypalPlanId) {
            return ['error' => 'No plan ID in PayPal response.'];
        }

        // 3) Create subscription (returns approval link)
        $subRes = Http::withToken($token)
            ->post($this->baseUrl().'/v1/billing/subscriptions', [
                'plan_id' => $paypalPlanId,
                'custom_id' => (string) $user->id.'|'.$plan->id.'|'.$billingCycle,
                'subscriber' => [
                    'email_address' => $user->email,
                ],
                'application_context' => [
                    'brand_name' => config('app.name'),
                    'return_url' => $this->successUrl,
                    'cancel_url' => $this->cancelUrl,
                ],
            ]);

        if (! $subRes->successful()) {
            Log::error('PayPal create subscription failed', ['body' => $subRes->json(), 'user_id' => $user->id]);

            return ['error' => $subRes->json('message', 'PayPal subscription creation failed.')];
        }

        $links = $subRes->json('links', []);
        $approve = collect($links)->firstWhere('rel', 'approve');
        $url = $approve['href'] ?? null;
        if (! $url) {
            return ['error' => 'No approval URL in PayPal response.'];
        }

        return ['url' => $url, 'subscription_id' => $subRes->json('id')];
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();

        // Sanitize headers: omit Authorization / credentials before logging
        $safeHeaders = collect($request->headers->all())
            ->except(['authorization', 'paypal-transmission-sig', 'paypal-cert-url'])
            ->toArray();
        Log::info('PayPal webhook received', ['headers' => $safeHeaders]);

        // Signature verification is MANDATORY whenever a webhook ID is configured.
        // verifyPayPalSignature() returns false when the signature headers are missing,
        // so an attacker cannot bypass verification by simply omitting them.
        if ($this->webhookId) {
            if (! $this->verifyPayPalSignature($request)) {
                Log::warning('PayPal webhook signature verification failed');

                return new Response('Invalid signature', 401);
            }
        } elseif (app()->environment('production')) {
            Log::warning('PayPal webhook ID not configured in production');

            return new Response('Webhook ID not configured', 401);
        }

        $data = json_decode($payload, true);
        $eventType = $data['event_type'] ?? '';
        $eventId = $data['id'] ?? null;

        // Idempotency guard
        if ($eventId && ! app(WebhookIdempotencyService::class)->isNewEvent('paypal', $eventId)) {
            return new Response('OK', 200);
        }

        try {
            match ($eventType) {
                'BILLING.SUBSCRIPTION.ACTIVATED' => $this->handleSubscriptionActivated($data),
                'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.SUSPENDED' => $this->handleSubscriptionCanceled($data),
                'PAYMENT.SALE.COMPLETED' => $this->handlePaymentCompleted($data),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('PayPal webhook handler failed', ['type' => $eventType, 'error' => $e->getMessage()]);

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    private function handleSubscriptionActivated(array $data): void
    {
        $resource = $data['resource'] ?? [];
        $customId = $resource['custom_id'] ?? '';
        $parts = explode('|', $customId);
        if (count($parts) < 3) {
            return;
        }
        $userId = (int) $parts[0];
        $planId = (int) $parts[1];
        $billingCycle = $parts[2] ?? 'month';
        $subId = $resource['id'] ?? '';

        $existing = Subscription::where('gateway', 'paypal')
            ->where('gateway_subscription_id', $subId)
            ->first();
        $isNew = ! $existing;

        $nextBilling = data_get($resource, 'billing_info.next_billing_time');

        $subscription = Subscription::updateOrCreate(
            [
                'user_id' => $userId,
                'gateway' => 'paypal',
                'gateway_subscription_id' => $subId,
            ],
            [
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => $nextBilling ? Carbon::parse($nextBilling) : ($existing->renews_at ?? null),
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

    private function handleSubscriptionCanceled(array $data): void
    {
        $subId = $data['resource']['id'] ?? '';
        Subscription::where('gateway', 'paypal')
            ->where('gateway_subscription_id', $subId)
            ->update(['status' => 'canceled', 'ends_at' => now()]);
    }

    private function handlePaymentCompleted(array $data): void
    {
        $resource = $data['resource'] ?? [];
        $transactionId = $resource['id'] ?? null;
        if (! $transactionId) {
            return;
        }

        $alreadyRecorded = PaymentTransaction::where('gateway', 'paypal')
            ->where('gateway_transaction_id', $transactionId)
            ->exists();
        if ($alreadyRecorded) {
            return;
        }

        // Resolve subscription and user from PayPal subscription ID stored in billing_agreement_id
        $paypalSubId = $resource['billing_agreement_id'] ?? null;
        $subscription = $paypalSubId
            ? Subscription::where('gateway', 'paypal')->where('gateway_subscription_id', $paypalSubId)->with('user', 'plan')->first()
            : null;

        // A prior paid transaction means this is a recurring renewal, not the first charge.
        $isRenewal = $subscription
            && PaymentTransaction::where('gateway', 'paypal')
                ->where('subscription_id', $subscription->id)
                ->where('status', 'paid')
                ->exists();

        $amountCents = (int) round((float) ($resource['amount']['total'] ?? 0) * 100);
        $currency = $resource['amount']['currency'] ?? 'USD';

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription?->user_id,
            'subscription_id' => $subscription?->id,
            'gateway' => 'paypal',
            'gateway_transaction_id' => $transactionId,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $resource,
        ]);

        $this->generateInvoicePdf($transaction);

        if ($isRenewal && $subscription->user && $subscription->plan) {
            // Refresh renews_at from PayPal before notifying so the email shows the new date.
            $this->sync($subscription);
            $subscription->refresh()->loadMissing('user', 'plan');

            SubscriptionRenewed::dispatch(
                $subscription->user,
                $subscription,
                $subscription->plan,
                $amountCents,
                strtoupper($currency),
            );
        }
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'paypal' || ! $this->isConfigured()) {
            return false;
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return false;
        }

        $res = Http::withToken($token)
            ->post($this->baseUrl().'/v1/billing/subscriptions/'.$subscription->gateway_subscription_id.'/cancel');

        if ($res->successful()) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        Log::error('PayPal cancel failed', ['subscription_id' => $subscription->id, 'body' => $res->body()]);

        return false;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'paypal' || ! $this->isConfigured()) {
            return false;
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return false;
        }

        $res = Http::withToken($token)
            ->get($this->baseUrl().'/v1/billing/subscriptions/'.$subscription->gateway_subscription_id);

        if (! $res->successful()) {
            return false;
        }

        $nextBilling = $res->json('billing_info.next_billing_time');
        $subscription->update([
            'status' => $this->mapStatus($res->json('status', '')),
            'renews_at' => $nextBilling ? Carbon::parse($nextBilling) : $subscription->renews_at,
        ]);

        return true;
    }

    /** Map PayPal subscription statuses to the app's canonical status vocabulary. */
    private function mapStatus(string $paypalStatus): string
    {
        return match (strtoupper($paypalStatus)) {
            'ACTIVE' => 'active',
            'SUSPENDED' => 'past_due',
            'CANCELLED', 'EXPIRED' => 'canceled',
            'APPROVAL_PENDING', 'APPROVED' => 'incomplete',
            default => strtolower($paypalStatus),
        };
    }

    /** Best-effort PDF invoice generation; never let it break webhook processing. */
    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('PayPal: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }

    public function fulfillCheckoutSession(string $sessionId): array
    {
        return ['ok' => false, 'error' => 'PayPal fulfillment is handled via webhook.'];
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        // PayPal subscription plan changes typically require a new subscription flow.
        return ['ok' => false, 'error' => 'Plan changes must be completed by cancelling and re-subscribing via PayPal.'];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return ['ok' => false, 'error' => 'Could not retrieve PayPal access token.'];
        }

        $captureId = $transaction->gateway_transaction_id ?? null;
        if (! $captureId) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        $body = [];
        if ($amountCents) {
            $body['amount'] = [
                'value' => number_format($amountCents / 100, 2, '.', ''),
                'currency_code' => strtoupper($transaction->currency_code ?? 'USD'),
            ];
        }

        $res = Http::withToken($token)
            ->post($this->baseUrl()."/v2/payments/captures/{$captureId}/refund", $body);

        if (! $res->successful()) {
            Log::error('PayPal refund failed', ['transaction_id' => $transaction->id, 'response' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('message') ?? 'PayPal refund failed.'];
        }

        $transaction->update([
            'refunded_at' => now(),
            'refunded_cents' => $amountCents ?? $transaction->amount_cents,
            'status' => 'refunded',
        ]);

        return ['ok' => true, 'error' => null];
    }

    private function verifyPayPalSignature(Request $request): bool
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return false;
        }

        $resp = Http::withToken($token)
            ->timeout(10)
            ->post($this->baseUrl().'/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $request->header('Paypal-Auth-Algo', ''),
                'cert_url' => $request->header('Paypal-Cert-Url', ''),
                'transmission_id' => $request->header('Paypal-Transmission-Id', ''),
                'transmission_sig' => $request->header('Paypal-Transmission-Sig', ''),
                'transmission_time' => $request->header('Paypal-Transmission-Time', ''),
                'webhook_id' => $this->webhookId,
                'webhook_event' => json_decode($request->getContent(), true),
            ]);

        return $resp->successful() && ($resp->json('verification_status') === 'SUCCESS');
    }
}
