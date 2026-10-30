<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\Models\ExternalCallLog;
use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Services\External\ExternalCallException;
use App\Modules\Integrations\Services\External\IntegrationHealth;
use App\Modules\Integrations\Services\External\ManifestFetcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Status of the linked EPOS systems, for workspace administrators only. Shows the host and the
 * health of each link, never the key.
 */
class EposIntegrationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authoriseAdmin($request);

        $rows = ExternalIntegration::where('workspace_id', $this->workspaceId($request))->whereIn('type', ExternalIntegration::TYPES)->orderBy('id')->get();

        return Inertia::render('client/Integrations/Epos', [
            'integrations' => $rows->map(fn (ExternalIntegration $i) => [
                'id' => $i->id,
                'type' => $i->type,
                'name' => $i->name,
                'host' => parse_url($i->base_url, PHP_URL_HOST),
                'status' => $i->status,
                'consecutive_failures' => $i->consecutive_failures,
                'next_retry_at' => $i->next_retry_at?->toIso8601String(),
                'manifest_version' => $i->manifest_version,
                'manifest_fetched_at' => $i->manifest_fetched_at?->toIso8601String(),
                'capabilities' => array_map(fn ($c) => [
                    'key' => $c['key'],
                    'kind' => $c['kind'],
                    'group' => $c['group'] ?? null,
                    'label' => $c['label'],
                ], $i->manifest['capabilities'] ?? []),
                'issues' => $i->manifest_issues ?? [],
                'debug_until' => $i->isDebugging() ? $i->debug_until?->toIso8601String() : null,
                'calls' => ExternalCallLog::where('integration_id', $i->id)->orderByDesc('id')->limit(50)
                    ->get(['id', 'capability', 'outcome', 'status', 'ms', 'message', 'request', 'response', 'created_at'])
                    ->map(fn (ExternalCallLog $c) => $c->toArray())->all(),
            ])->values(),
        ]);
    }

    public function check(Request $request, int $integration, ManifestFetcher $manifests, IntegrationHealth $health): RedirectResponse
    {
        $this->authoriseAdmin($request);
        $row = ExternalIntegration::where('workspace_id', $this->workspaceId($request))->findOrFail($integration);

        try {
            $manifests->refresh($row);
            if (! $health->ping($row, force: true)) {
                return back()->with('error', __('E-POS did not answer the health check.'));
            }
        } catch (ExternalCallException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('E-POS answered and its question list is up to date.'));
    }

    /** Switch request/response recording on for one hour, or off. It switches itself off. */
    public function debug(Request $request, int $integration): RedirectResponse
    {
        $this->authoriseAdmin($request);
        $row = ExternalIntegration::where('workspace_id', $this->workspaceId($request))->findOrFail($integration);
        $on = $request->boolean('on');
        $row->forceFill(['debug_until' => $on ? now()->addHour() : null])->save();

        return back()->with('success', $on ? __('Recording requests and responses for one hour.') : __('Recording switched off.'));
    }

    private function authoriseAdmin(Request $request): void
    {
        abort_unless($request->user()->isClientAdministrator(), 403, __('Only workspace administrators can see integration status.'));
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
