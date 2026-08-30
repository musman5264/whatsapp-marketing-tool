<?php

namespace App\Contracts;

use App\Models\Plan;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;

interface BillingGatewayInterface
{
    /** Human-readable gateway name. */
    public function name(): string;

    /** Whether this gateway is configured (env credentials present). */
    public function isConfigured(): bool;

    /**
     * Create checkout and return redirect URL or session URL.
     * billingCycle: 'month' | 'year'
     * Returns ['url' => string] or ['error' => string].
     */
    public function createCheckout(User $user, Plan $plan, string $billingCycle): array;

    /**
     * Handle gateway webhook (verify signature, update subscription/transactions).
     * Returns Symfony Response.
     */
    public function handleWebhook(Request $request): \Symfony\Component\HttpFoundation\Response;

    /** Cancel the subscription at the gateway. */
    public function cancel(Subscription $subscription): bool;

    /** Sync subscription status from gateway to local model. */
    public function sync(Subscription $subscription): bool;

    /**
     * Change subscription plan (upgrade/downgrade with proration).
     * Returns ['ok' => bool, 'error' => ?string].
     */
    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array;

    /**
     * Refund a payment (full or partial).
     * Returns ['ok' => bool, 'error' => ?string].
     */
    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array;

    /**
     * Fulfill checkout when user lands on success URL.
     * Returns ['ok' => bool, 'error' => ?string, 'subscription' => ?Subscription].
     */
    public function fulfillCheckoutSession(string $sessionId): array;
}
