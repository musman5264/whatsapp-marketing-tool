<?php

namespace App\Http\Controllers;

use App\Services\License\LicenseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Standalone (guest) license re-activation page. EnsureLicensed redirects an
 * unlicensed admin panel here; once a valid license is activated the operator
 * is sent back to sign in.
 */
class LicenseController extends Controller
{
    public function __construct(private LicenseManager $license) {}

    public function show(): InertiaResponse|RedirectResponse
    {
        if (! $this->license->enabled()) {
            return redirect()->route('admin.login');
        }

        $status = $this->license->verify();
        if ($status['ok']) {
            return redirect()->route('admin.login');
        }

        return Inertia::render('License/Activate', [
            'reason' => $status['message'] ?? null,
            'verify_type' => $this->license->defaultVerifyType(),
            'verify_types' => $this->license->verifyTypes(),
        ]);
    }

    public function activate(Request $request): RedirectResponse
    {
        if (! $this->license->enabled()) {
            return redirect()->route('admin.login');
        }

        $isEnvato = $request->input('verify_type', $this->license->defaultVerifyType()) === 'envato';

        $data = $request->validate([
            'license_code' => ['required', 'string'],
            'verify_type' => ['nullable', Rule::in(LicenseManager::TYPES)],
            'client_name' => [Rule::requiredIf($isEnvato), 'nullable', 'string', 'max:255'],
        ], [], ['client_name' => 'Envato buyer name']);

        $activation = $this->license->activate(
            $data['license_code'],
            (string) ($data['client_name'] ?? ''),
            $data['verify_type'] ?? null,
        );
        if (! $activation['ok']) {
            throw ValidationException::withMessages(['license_code' => $activation['message']]);
        }

        $verification = $this->license->verify(useCache: false);
        if (! $verification['ok']) {
            throw ValidationException::withMessages(['license_code' => $verification['message']]);
        }

        return redirect()->route('admin.login')->with('status', 'License activated — thank you!');
    }
}
