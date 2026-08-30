<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CheckoutController extends Controller
{
    public function __construct(
        private BillingGatewayRegistry $gateways
    ) {}

    /**
     * Start checkout for a plan with the selected gateway and billing cycle.
     */
    public function store(Request $request): RedirectResponse|Response
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'billing_cycle' => ['required', 'string', Rule::in(['month', 'year'])],
            'gateway' => ['required', 'string', Rule::in(['stripe', 'paypal', 'paddle', 'razorpay', 'cashfree', 'tap', 'paystack', 'xendit', 'paymob', 'myfatoorah', 'mollie', 'square', 'iyzico', 'mercadopago'])],
        ]);

        $plan = Plan::where('enabled', true)->findOrFail($validated['plan_id']);
        $gateway = $this->gateways->get($validated['gateway']);

        if (! $gateway || ! $gateway->isConfigured()) {
            return back()->with('error', __('That payment gateway is not configured.'));
        }

        $result = $gateway->createCheckout($request->user(), $plan, $validated['billing_cycle']);

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        // Most gateways return a hosted redirect URL.
        if (isset($result['url'])) {
            return Inertia::location($result['url']);
        }

        // Some gateways (e.g. Cashfree) have no hosted redirect and require their JS SDK to
        // launch the authorization flow from a session id. Render an interstitial page that
        // loads the SDK and completes checkout, then returns to the billing page.
        if (isset($result['checkout'])) {
            return Inertia::render('client/Checkout/Sdk', [
                'checkout' => $result['checkout'],
                'plan_name' => $plan->name,
                'pricing_url' => route('client.pricing'),
            ]);
        }

        return back()->with('error', __('Checkout could not be started.'));
    }
}
