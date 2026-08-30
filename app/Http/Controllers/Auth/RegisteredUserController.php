<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Plan;
use App\Models\SmtpConfiguration;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Register', [
            'plan_id' => $request->query('plan_id'),
            'cycle' => $request->query('cycle', 'month'),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'email'       => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password'    => ['required', 'confirmed', Rules\Password::defaults()],
            'agree_terms' => ['accepted'],
            'timezone'    => ['nullable', 'string', 'max:64'],
        ], [
            'agree_terms.accepted' => 'You must accept the Terms & Conditions to create an account.',
        ]);

        // Use browser-detected timezone from signup; fall back to Bangladesh Standard Time.
        $timezone = $this->resolveTimezone($request->input('timezone'));

        $user = DB::transaction(function () use ($request, $timezone) {
            $client = Client::create([
                'name' => $request->name,
                'email' => $request->email,
                'status' => Client::STATUS_ACTIVE,
                // Currency left null: inherit the platform default currency.
            ]);

            return User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => User::ROLE_CLIENT,
                'status' => User::STATUS_ACTIVE,
                'client_id' => $client->id,
                'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
                'timezone' => $timezone,
                // No active SMTP config = app cannot send verification mail,
                // so auto-verify the account instead of trapping the user.
                'email_verified_at' => SmtpConfiguration::isConfigured() ? null : now(),
            ]);
        });

        event(new Registered($user));
        Auth::login($user);

        // If registration came from the pricing page, redirect to checkout
        $planId = $request->input('plan_id');
        $cycle = $request->input('cycle', 'month');
        if ($planId) {
            $plan = Plan::find($planId);
            if ($plan && ! $plan->is_free) {
                return redirect()->route('client.pricing')->with([
                    'plan_id' => $planId,
                    'cycle' => $cycle,
                    'success' => 'Account created! Select a payment method to complete your subscription.',
                ]);
            }
        }

        return redirect(route('client.dashboard', absolute: false));
    }

    private function resolveTimezone(?string $tz): string
    {
        $default = 'Asia/Dhaka';
        if (! $tz) {
            return $default;
        }
        try {
            new \DateTimeZone($tz);
            return $tz;
        } catch (\Exception) {
            return $default;
        }
    }
}
