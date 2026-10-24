<?php

namespace App\Http\Controllers\Api\V1;

use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Services\External\ExternalCallException;
use App\Modules\Integrations\Services\External\IntegrationHealth;
use App\Modules\Integrations\Services\External\ManifestFetcher;
use App\Modules\Integrations\Services\External\SafeHttp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The "link" API a remote system (EPOS) calls to register itself. The key is write-only: it is never
 * returned by any endpoint.
 */
class IntegrationApiController extends WorkspaceScopedController
{
    public function __construct(
        private readonly ManifestFetcher $manifests,
        private readonly IntegrationHealth $health,
        private readonly SafeHttp $http,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = ExternalIntegration::where('workspace_id', $this->workspaceId($request))->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn ($i) => $this->format($i))]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->format($this->find($request, $id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(ExternalIntegration::TYPES)],
            'name' => ['required', 'string', 'max:191'],
            'base_url' => ['required', 'url', 'max:255'],
            'manifest_url' => ['required', 'url', 'max:512'],
            'key' => ['required', 'string', 'max:512'],
            'manifest_version' => ['nullable', 'string', 'max:64'],
        ]);
        $data['base_url'] = rtrim($data['base_url'], '/');

        $integration = ExternalIntegration::firstOrNew([
            'workspace_id' => $this->workspaceId($request),
            'type' => $data['type'],
            'base_url' => $data['base_url'],
        ]);
        $created = ! $integration->exists;
        $integration->fill($data);

        if ($failure = $this->fetchAndSave($integration)) {
            return $failure;
        }

        return response()->json($this->summary($integration), $created ? 201 : 200);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $integration = $this->find($request, $id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'base_url' => ['sometimes', 'url', 'max:255'],
            'manifest_url' => ['sometimes', 'url', 'max:512'],
            'key' => ['sometimes', 'string', 'max:512'],
            'manifest_version' => ['nullable', 'string', 'max:64'],
        ]);
        if (isset($data['base_url'])) {
            $data['base_url'] = rtrim($data['base_url'], '/');
        }
        $integration->fill($data);

        return $this->fetchAndSave($integration) ?? response()->json($this->summary($integration));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(null, 204);
    }

    public function check(Request $request, int $id): JsonResponse
    {
        $integration = $this->find($request, $id);
        $error = null;
        $ping = false;

        try {
            $this->manifests->refresh($integration);
            $ping = $this->health->ping($integration, force: true);
        } catch (ExternalCallException $e) {
            $error = $e->getMessage();
        }

        return response()->json($this->summary($integration) + [
            'ok' => $error === null && $ping,
            'ping' => $ping,
            'issues' => $integration->manifest_issues ?? [],
        ] + ($error ? ['error' => $error] : []));
    }

    /**
     * Fetch the manifest with the (possibly new) key BEFORE saving, so a bad key or URL is refused
     * at link time and nothing is stored. Returns a 422 response on failure, null when saved.
     */
    private function fetchAndSave(ExternalIntegration $integration): ?JsonResponse
    {
        try {
            $this->http->assertSafe($integration->base_url);
            $manifest = $this->manifests->fetch($integration->manifest_url, $integration->key);
        } catch (ExternalCallException $e) {
            return response()->json(['error' => 'manifest_unreachable', 'detail' => $e->getMessage()], 422);
        }

        $this->manifests->apply($integration, $manifest);
        $this->health->recordSuccess($integration);

        return null;
    }

    private function find(Request $request, int $id): ExternalIntegration
    {
        return ExternalIntegration::where('workspace_id', $this->workspaceId($request))->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function summary(ExternalIntegration $i): array
    {
        return [
            'id' => $i->id,
            'status' => $i->status,
            'capabilities' => $i->capabilityCount(),
            'manifest_version' => $i->manifest_version,
        ];
    }

    /** @return array<string, mixed> */
    private function format(ExternalIntegration $i): array
    {
        return $this->summary($i) + [
            'type' => $i->type,
            'name' => $i->name,
            'base_url' => $i->base_url,
            'manifest_url' => $i->manifest_url,
            'manifest_fetched_at' => $i->manifest_fetched_at?->toIso8601String(),
            'issues' => $i->manifest_issues ?? [],
        ];
    }
}
