<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\LandingPageController;
use App\Models\Plan;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class LandingController extends Controller
{
    private function landingDisabledRedirect(): ?RedirectResponse
    {
        if (SystemSetting::get('landing.page_enabled', '1') === '1' || ! Route::has('login')) {
            return null;
        }

        return redirect()->route('login');
    }

    private function plans(): array
    {
        try {
            return Plan::where('enabled', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($p) => [
                    'id'            => $p->id,
                    'name'          => $p->name,
                    'description'   => $p->description ?? '',
                    'price_monthly' => round(($p->monthly_price_cents ?? 0) / 100, 2),
                    'price_yearly'  => round(($p->yearly_price_cents ?? 0) / 100, 2),
                    'features'      => is_array($p->features) ? $p->features : [],
                    'is_featured'   => (bool) ($p->featured ?? $p->popular ?? false),
                    'trial_days'    => $p->trial_days ?? 0,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function index(): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('Welcome', [
            'canLogin'    => Route::has('login'),
            'canRegister' => Route::has('register'),
            'landing'     => LandingPageController::getPublicSettings(),
            'plans'       => $this->plans(),
        ]);
    }

    public function pricing(): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('marketing/Pricing', [
            'canRegister' => Route::has('register'),
            'landing'     => LandingPageController::getPublicSettings(),
            'plans'       => $this->plans(),
        ]);
    }

    public function faq(): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('marketing/Faq', [
            'canRegister' => Route::has('register'),
            'landing'     => LandingPageController::getPublicSettings(),
        ]);
    }

    public function useCases(): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('marketing/UseCases', [
            'canRegister' => Route::has('register'),
            'landing'     => LandingPageController::getPublicSettings(),
        ]);
    }

    public function about(): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('marketing/About', [
            'canRegister' => Route::has('register'),
            'landing'     => LandingPageController::getPublicSettings(),
        ]);
    }

    public function integrations(): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('marketing/Integrations', [
            'canRegister' => Route::has('register'),
            'landing'     => LandingPageController::getPublicSettings(),
        ]);
    }
}
