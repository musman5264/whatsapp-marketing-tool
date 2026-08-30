<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Modules\Broadcasting\Models\UsageMeter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to enforce plan-based feature limits.
 *
 * Usage in routes:
 *   Route::post('/...')->middleware('limit:campaigns_per_month,campaigns');
 *
 * Parameters:
 *   $limitKey  – key in Plan.limits JSON (e.g. 'campaigns_per_month')
 *   $countKey  – usage_meters meter_key (e.g. 'campaigns')
 *
 * The middleware checks whether the workspace has reached its plan limit for the
 * given meter. If so, it aborts with HTTP 402 or redirects back with an error.
 */
class EnforceLimit
{
    public function handle(Request $request, Closure $next, string $limitKey, string $countKey = ''): Response
    {
        $user = $request->user();
        $workspaceId = $user?->current_workspace_id ?? $user?->workspace_id;

        if (! $workspaceId || ! $user) {
            return $next($request);
        }

        // Retrieve the current workspace's active plan limits
        $workspace = Workspace::with('client')->find($workspaceId);
        $plan = $workspace?->client?->activePlan();
        $limits = $plan?->limits ?? [];

        $limit = $limits[$limitKey] ?? null;

        // null = unlimited
        if ($limit === null) {
            return $next($request);
        }

        // Check usage meter for this month
        $meterKey = $countKey ?: $limitKey;
        $usage = UsageMeter::current($workspaceId, $meterKey);

        if ($usage >= $limit) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'Plan limit reached.',
                    'upgrade_required' => true,
                    'limit' => $limit,
                    'current' => $usage,
                    'key' => $limitKey,
                ], 402);
            }

            return redirect('/billing')->with('upgrade_required', true)
                ->with('upgrade_reason', "You've reached your {$limitKey} limit ({$usage}/{$limit}).");
        }

        return $next($request);
    }
}
