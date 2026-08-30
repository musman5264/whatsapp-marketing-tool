<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\License\LicenseManager;
use App\Services\License\Updater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LicenseController extends Controller
{
    public function __construct(private LicenseManager $license) {}

    public function index(): Response
    {
        return Inertia::render('Admin/License/Index', [
            'license' => [
                'enabled' => $this->license->enabled(),
                'activated' => $this->license->isActivated(),
                'verify_type' => $this->license->activatedType(),
                'verify_types' => $this->license->verifyTypes(),
                'masked_code' => $this->license->maskedCode(),
                'product_id' => (string) config('license.product_id'),
                'current_version' => (string) config('license.current_version'),
            ],
        ]);
    }

    public function checkUpdate(): JsonResponse
    {
        return response()->json($this->license->checkUpdate());
    }

    /** Download + install the available update (long-running). */
    public function applyUpdate(Updater $updater): JsonResponse
    {
        return response()->json($updater->apply());
    }

    public function activate(Request $request): RedirectResponse
    {
        $isEnvato = $request->input('verify_type', $this->license->defaultVerifyType()) === 'envato';

        $data = $request->validate([
            'license_code' => ['required', 'string'],
            'verify_type' => ['nullable', Rule::in(LicenseManager::TYPES)],
            'client_name' => [Rule::requiredIf($isEnvato), 'nullable', 'string', 'max:255'],
        ], [], ['client_name' => 'Envato buyer name']);

        $result = $this->license->activate(
            $data['license_code'],
            (string) ($data['client_name'] ?? ''),
            $data['verify_type'] ?? null,
        );

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function deactivate(): RedirectResponse
    {
        $result = $this->license->deactivate();

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
