<?php

namespace App\Http\Middleware;

use App\Services\License\LicenseManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the admin panel when the copy's license is missing or invalid, sending
 * the operator to the /license re-activation page. Verification is cached (with
 * a grace window on server outage) inside LicenseManager, so this adds no
 * per-request network cost once a license is confirmed.
 */
class EnsureLicensed
{
    public function __construct(private LicenseManager $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->license->enabled()) {
            return $next($request);
        }

        if ($this->license->verify()['ok']) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'A valid license is required.'], 403);
        }

        return redirect()->route('license.show');
    }
}
