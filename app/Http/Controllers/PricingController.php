<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Plan;
use App\Services\Billing\BillingGatewayRegistry;
use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    public function __construct(
        private CurrencyService $currency,
        private BillingGatewayRegistry $gateways
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $displayCurrency = $user?->display_currency
            ?? $user?->workspace?->currency_code
            ?? $request->session()->get('display_currency')
            ?? Currency::defaultCode();

        $plans = Plan::where('enabled', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (Plan $plan) use ($displayCurrency) {
                $monthlyCents = $plan->priceCentsForCycle('month');
                $yearlyCents = $plan->priceCentsForCycle('year');

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'description' => $plan->description,
                    'monthly_price_display' => $monthlyCents !== null
                        ? $this->currency->formatConverted($monthlyCents, $plan->currency_code, $displayCurrency)
                        : null,
                    'yearly_price_display' => $yearlyCents !== null
                        ? $this->currency->formatConverted($yearlyCents, $plan->currency_code, $displayCurrency)
                        : null,
                    'monthly_price_cents' => $monthlyCents,
                    'yearly_price_cents' => $yearlyCents,
                    'features' => is_array($plan->features) ? $plan->features : [],
                    'limits' => is_array($plan->limits) ? $plan->limits : [],
                    'white_label_enabled' => (bool) $plan->white_label_enabled,
                    'popular' => (bool) $plan->popular,
                    'featured' => (bool) $plan->featured,
                    'is_free' => ($monthlyCents ?? 0) === 0 && ($yearlyCents ?? 0) === 0,
                    'trial_days' => $plan->trial_days,
                ];
            });

        return Inertia::render('client/Pricing', [
            'plans' => $plans,
            'gateways' => $this->gateways->listForFrontend(),
            'is_authenticated' => (bool) $user,
            'register_url' => route('register'),
            'checkout_url' => route('client.checkout.store'),
            'flash' => [
                'error' => $request->session()->get('error'),
                'success' => $request->session()->get('success'),
            ],
        ]);
    }
}
