<?php

namespace App\Modules\Automation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Automation\Services\WorkflowGenerator;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\External\ManifestFetcher;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $automations = Automation::where('workspace_id', $wid)
            ->withCount('runs')
            ->latest()->get();

        return Inertia::render('Automation/Index', ['automations' => $automations]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate(['name' => ['required', 'string', 'max:128']]);

        $auto = Automation::create(array_merge($validated, [
            'workspace_id' => $wid,
            'status' => 'draft',
            'nodes' => [['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 250, 'y' => 50], 'data' => ['label' => 'Trigger']]],
            'edges' => [],
        ]));

        return redirect()->route('client.automations.edit', $auto->uuid)->with('success', 'Automation created.');
    }

    /**
     * The linked E-POS systems and the questions each one offers, for the builder's E-POS node.
     * Loaded by the builder after it opens, so a slow EPOS never delays the page. A manifest older
     * than 10 minutes is re-read here. Never the key or the host; status and issues are for
     * workspace administrators only.
     */
    public function epos(Request $request, ManifestFetcher $manifests): JsonResponse
    {
        $isAdmin = $request->user()->isClientAdministrator();
        $workspaceId = $this->workspaceId($request);
        // E-POS and RentOyee share this step; a system the client has switched off is not offered.
        $rows = ExternalIntegration::where('workspace_id', $workspaceId)->whereIn('type', ExternalIntegration::TYPES)->orderBy('id')->get()
            ->filter(fn (ExternalIntegration $i) => ExternalIntegration::enabledFor($workspaceId, $i->type));

        $integrations = $rows->map(function (ExternalIntegration $i) use ($manifests, $isAdmin) {
            $manifests->refreshIfStale($i);

            return [
                'id' => $i->id,
                'type' => $i->type,
                'name' => $i->name,
                'manifest_version' => $i->manifest_version,
                'capabilities' => collect($i->manifest['capabilities'] ?? [])->map(fn ($c) => array_intersect_key($c, array_flip([
                    'key', 'kind', 'group', 'label', 'description', 'params', 'outputs', 'summary_template',
                ])))->values(),
            ] + ($isAdmin ? [
                'status' => $i->status,
                'issues' => $i->manifest_issues ?? [],
            ] : []);
        });

        return response()->json(['integrations' => $integrations->values()]);
    }

    /**
     * Import an exported automation file as a NEW draft in the importer's own workspace. The file is untrusted:
     * its workspace, status, token and ids are never used, it is size-limited, and only its steps, connections
     * and trigger settings are kept. It never touches an existing automation.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['payload' => ['required', 'string', 'max:1000000']]);
        $file = json_decode($request->input('payload'), true);

        $nodes = $file['automation']['nodes'] ?? null;
        $fail = fn (string $why) => throw ValidationException::withMessages(['payload' => $why]);

        if (! is_array($file) || ($file['format'] ?? null) !== 'whatsmine.automation' || ! is_array($file['automation'] ?? null)) {
            $fail('This is not an exported automation file.');
        }
        if (! is_int($file['version'] ?? null) || $file['version'] < 1 || $file['version'] > 1) {
            $fail('This file was made by a newer version and cannot be imported.');
        }
        if (! is_array($nodes) || $nodes === [] || count($nodes) > 200) {
            $fail('The file must contain between 1 and 200 steps.');
        }

        $ids = [];
        $cleanNodes = [];
        foreach ($nodes as $node) {
            $id = is_array($node) ? ($node['id'] ?? null) : null;
            $type = is_array($node) ? ($node['type'] ?? null) : null;
            if (! is_string($id) || $id === '' || strlen($id) > 64 || isset($ids[$id])
                || ! is_string($type) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{1,39}$/', $type)) {
                $fail('A step in the file is not valid.');
            }
            $ids[$id] = true;
            $cleanNodes[] = [
                'id' => $id,
                'type' => $type,
                'position' => is_array($node['position'] ?? null) ? $node['position'] : ['x' => 0, 'y' => 0],
                'data' => is_array($node['data'] ?? null) ? $node['data'] : [],
            ];
        }

        $edges = [];
        foreach ((array) ($file['automation']['edges'] ?? []) as $edge) {
            if (is_array($edge) && isset($ids[$edge['source'] ?? null], $ids[$edge['target'] ?? null]) && count($edges) < 400) {
                $edges[] = [
                    'id' => is_string($edge['id'] ?? null) ? $edge['id'] : 'e'.(count($edges) + 1),
                    'source' => $edge['source'],
                    'target' => $edge['target'],
                    'sourceHandle' => is_string($edge['sourceHandle'] ?? null) ? $edge['sourceHandle'] : null,
                    'targetHandle' => is_string($edge['targetHandle'] ?? null) ? $edge['targetHandle'] : null,
                ];
            }
        }

        $triggerType = $file['automation']['trigger_type'] ?? null;
        $automation = Automation::create([
            'workspace_id' => $this->workspaceId($request),
            'name' => Str::limit((string) ($file['automation']['name'] ?? 'Automation'), 110, '').' (imported)',
            'status' => 'draft',
            'trigger_type' => is_string($triggerType) && preg_match('/^[a-z0-9_.]{1,64}$/', $triggerType) ? $triggerType : null,
            'trigger_config' => is_array($file['automation']['trigger_config'] ?? null) ? $file['automation']['trigger_config'] : [],
            'nodes' => $cleanNodes,
            'edges' => $edges,
        ]);

        return redirect()->route('client.automations.edit', $automation->uuid)->with('success', 'Automation imported as a draft. Check its steps, then activate it.');
    }

    public function edit(Request $request, Automation $automation): Response
    {
        $this->authorise($request, $automation);
        $wid = (int) $automation->workspace_id;

        return Inertia::render('Automation/Builder', [
            'automation' => $automation,
            'resources' => $this->builderResources($wid, $automation->id),
        ]);
    }

    /**
     * Reference data the builder needs to populate node config dropdowns
     * (templates, campaigns, chatbots, sub-flows, agents, stores) plus a map of
     * which optional integrations are connected.
     */
    private function builderResources(int $workspaceId, int $currentAutomationId): array
    {
        return [
            // All templates (approved first) so the builder can list existing ones and
            // surface their body variables; non-approved are shown but flagged in the UI.
            'templates' => WhatsappTemplate::where('workspace_id', $workspaceId)
                ->orderByRaw("CASE WHEN status = 'APPROVED' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get(['name', 'language', 'status', 'components'])
                ->map(fn ($t) => [
                    'name' => $t->name,
                    'language' => $t->language,
                    'status' => $t->status,
                    'components' => $t->components,
                ])
                ->values(),
            'campaigns' => Campaign::where('workspace_id', $workspaceId)
                ->latest()->limit(100)->get(['id', 'name'])->values(),
            'chatbots' => AiChatbot::where('workspace_id', $workspaceId)
                ->where('enabled', true)->orderBy('name')->get(['id', 'name'])->values(),
            'subflows' => Automation::where('workspace_id', $workspaceId)
                ->where('id', '!=', $currentAutomationId)
                ->orderBy('name')->get(['uuid', 'name', 'status'])->values(),
            'agents' => User::where('workspace_id', $workspaceId)
                ->orderBy('name')->get(['id', 'name'])->values(),
            'stores' => EcommerceStore::where('workspace_id', $workspaceId)
                ->get(['id', 'platform', 'name'])->values(),
            'integrations' => [
                'google' => (bool) optional(IntegrationConfig::forProvider('google_workspace'))->enabled,
            ],
        ];
    }

    public function update(Request $request, Automation $automation): RedirectResponse
    {
        $this->authorise($request, $automation);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'status' => ['sometimes', 'in:active,paused,draft'],
            'trigger_type' => ['nullable', 'string', 'max:64'],
            'trigger_config' => ['nullable', 'array'],
            'nodes' => ['nullable', 'array'],
            'edges' => ['nullable', 'array'],
        ]);
        $automation->update($validated);

        return back()->with('success', 'Automation saved.');
    }

    public function destroy(Request $request, Automation $automation): RedirectResponse
    {
        $this->authorise($request, $automation);
        $automation->delete();

        return redirect()->route('client.automations.index')->with('success', 'Automation deleted.');
    }

    public function runs(Request $request, Automation $automation): Response
    {
        $this->authorise($request, $automation);
        $runs = AutomationRun::where('automation_id', $automation->id)
            ->with(['logs', 'contact:id,phone_e164,first_name,last_name,avatar'])
            ->withCount('logs')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Automation/Runs', ['automation' => $automation, 'runs' => $runs]);
    }

    public function generateToken(Request $request, Automation $automation): JsonResponse
    {
        $this->authorise($request, $automation);
        $automation->update(['trigger_token' => Str::random(48)]);

        return response()->json(['trigger_token' => $automation->trigger_token]);
    }

    /**
     * Dry-run the automation and return a step-by-step trace. Tests the live builder
     * state (posted nodes/edges) when supplied, otherwise the saved version. The engine
     * simulates every action — nothing is actually sent and no data is written.
     */
    public function test(Request $request, Automation $automation): JsonResponse
    {
        $this->authorise($request, $automation);
        $validated = $request->validate([
            'nodes' => ['nullable', 'array'],
            'edges' => ['nullable', 'array'],
            'trigger_type' => ['nullable', 'string', 'max:64'],
            'trigger_config' => ['nullable', 'array'],
            'sample_message' => ['nullable', 'string', 'max:1000'],
        ]);

        $nodes = $validated['nodes'] ?? $automation->nodes ?? [];
        $edges = $validated['edges'] ?? $automation->edges ?? [];
        if (array_key_exists('trigger_type', $validated)) {
            $automation->trigger_type = $validated['trigger_type']; // in-memory only, never persisted
        }

        $context = [];
        if (! empty($validated['sample_message'])) {
            $context['message_body'] = $validated['sample_message'];
        }

        return response()->json(app(AutomationEngine::class)->testRun($automation, $nodes, $edges, $context));
    }

    /**
     * Generate a full automation graph from a natural-language prompt via the workspace LLM.
     * With persist=true a new automation is created and an edit URL returned; otherwise the
     * normalised graph is returned for the builder to drop onto the canvas for review.
     */
    public function generate(Request $request): JsonResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:8000'],
            'persist' => ['nullable', 'boolean'],
        ]);

        try {
            $graph = app(WorkflowGenerator::class)->generate($wid, $validated['prompt']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        if ($request->boolean('persist')) {
            $auto = Automation::create([
                'workspace_id' => $wid,
                'name' => $graph['name'],
                'status' => 'draft',
                'trigger_type' => $graph['trigger_type'],
                'trigger_config' => $graph['trigger_config'],
                'nodes' => $graph['nodes'],
                'edges' => $graph['edges'],
            ]);

            return response()->json(['ok' => true, 'redirect' => route('client.automations.edit', $auto->uuid)]);
        }

        return response()->json(['ok' => true, 'graph' => $graph]);
    }

    private function authorise(Request $request, Automation $automation): void
    {
        abort_unless((int) $automation->workspace_id === $this->workspaceId($request), 403);
    }
}
