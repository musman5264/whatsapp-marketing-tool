<?php

namespace App\Http\Controllers\Client;

use App\Contracts\BillingGatewayInterface;
use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function __construct(
        private BillingGatewayRegistry $gateways
    ) {}

    /**
     * Attempt gateway-specific checkout fulfilment on the success redirect.
     * Each gateway uses a different query param; all are safe to call concurrently
     * with the webhook because duplicate handling is guarded at the DB level.
     */
    private function tryFulfil(Request $request, int $userId): void
    {
        // Stripe: ?session_id=cs_...
        $sessionId = $request->query('session_id');
        if ($sessionId) {
            $this->callFulfil('stripe', $sessionId, $userId);
        }

        // MyFatoorah: ?paymentId=<id>
        $paymentId = $request->query('paymentId');
        if ($paymentId) {
            $this->callFulfil('myfatoorah', $paymentId, $userId);
        }
    }

    private function callFulfil(string $gatewayKey, string $sessionId, int $userId): void
    {
        $gateway = $this->gateways->get($gatewayKey);
        if (! $gateway instanceof BillingGatewayInterface) {
            return;
        }
        try {
            $gateway->fulfillCheckoutSession($sessionId);
        } catch (\Throwable $e) {
            Log::warning('Billing: checkout fulfilment skipped', [
                'gateway' => $gatewayKey,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        // When returning from a gateway success URL, attempt to fulfil the checkout session.
        // Guarded: the webhook may fulfil concurrently; unique DB constraints prevent duplicates.
        if ($user) {
            $this->tryFulfil($request, $user->id);
        }

        $query = PaymentTransaction::where('user_id', $user->id)
            ->with(['subscription.plan:id,name,slug'])
            ->orderByDesc('created_at');

        $transactions = $query->paginate(20)->withQueryString()->through(function ($t) {
            return [
                'id' => $t->id,
                'amount_cents' => $t->amount_cents,
                'currency_code' => $t->currency_code,
                'status' => $t->status,
                'gateway' => $t->gateway,
                'created_at' => $t->created_at->toIso8601String(),
                'plan' => $t->subscription?->plan ? [
                    'name' => $t->subscription->plan->name,
                ] : null,
            ];
        });

        return Inertia::render('client/Billing/Index', [
            'transactions' => $transactions,
        ]);
    }
}
