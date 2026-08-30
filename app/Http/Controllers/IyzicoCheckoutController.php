<?php

namespace App\Http\Controllers;

use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hosts iyzico's subscription checkout.
 *
 * iyzico is the only gateway here that hands back embedded form markup instead of a hosted
 * page URL, so the form has to be rendered from our own origin. iyzico then posts the result
 * token back to the callback.
 */
class IyzicoCheckoutController extends Controller
{
    public function __construct(
        private BillingGatewayRegistry $gateways
    ) {}

    /** Render the stored iyzico form markup for its owner. */
    public function form(Request $request, string $token): Response
    {
        $session = DB::table('iyzico_checkout_sessions')->where('token', $token)->first();

        abort_if(! $session, 404);
        abort_if((int) $session->user_id !== (int) $request->user()->id, 403);
        abort_if(Carbon::parse($session->expires_at)->isPast(), 410, 'This checkout link has expired.');

        return response()->view('billing.iyzico', [
            'checkoutFormContent' => $session->checkout_form_content,
            'cancelUrl' => route('client.pricing'),
        ]);
    }

    /**
     * iyzico posts the result token here from the customer's browser. This is a cross-site
     * POST, so it arrives without a session cookie — the unguessable token is what ties the
     * result back to a user, and the redirect below lands on an authenticated page.
     */
    public function callback(Request $request): RedirectResponse
    {
        $token = (string) $request->input('token', '');
        $gateway = $this->gateways->get('iyzico');

        if (! $token || ! $gateway) {
            return redirect()->to(config('billing.gateways.iyzico.cancel_url'))
                ->with('error', __('Checkout could not be completed.'));
        }

        try {
            $result = $gateway->fulfillCheckoutSession($token);
        } catch (\Throwable $e) {
            Log::error('iyzico callback fulfilment failed', ['error' => $e->getMessage()]);
            $result = ['ok' => false, 'error' => __('Checkout could not be completed.')];
        }

        if (! ($result['ok'] ?? false)) {
            return redirect()->to(config('billing.gateways.iyzico.cancel_url'))
                ->with('error', $result['error'] ?? __('Checkout could not be completed.'));
        }

        return redirect()->to(config('billing.gateways.iyzico.success_url'));
    }
}
