<?php

namespace App\Modules\Deployment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Deployment\Models\DeploymentLog;
use App\Modules\Deployment\Models\DeploymentSetting;
use App\Modules\Deployment\Services\DeployClient;
use App\Modules\Deployment\Services\GitInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DeploymentController extends Controller
{
    public function __construct(
        private readonly DeployClient $client,
        private readonly GitInspector $git,
    ) {}

    public function index(Request $request): Response
    {
        $this->guard($request);

        $s = DeploymentSetting::allValues();

        return Inertia::render('Admin/Deployment/Index', [
            'settings' => [
                'repo_url' => $s['repo_url'],
                'branch' => $s['branch'],
                'deploy_url' => $s['deploy_url'],
                'deploy_key_set' => $s['deploy_key'] !== '',
                'github_token_set' => $s['github_token'] !== '',
                'auto_migrate' => $s['auto_migrate'] === '1',
                'auto_composer' => $s['auto_composer'] === '1',
                'maintenance_mode' => $s['maintenance_mode'] === '1',
                'app_version' => $s['app_version'],
            ],
            'localGit' => $this->git->local(),
            'lastDeploy' => DeploymentLog::query()->latest('id')->first(),
            'history' => DeploymentLog::query()
                ->with('deployer:id,name,email')
                ->latest('id')
                ->paginate(15)
                ->through(fn (DeploymentLog $l) => [
                    'id' => $l->id,
                    'action' => $l->action,
                    'status' => $l->status,
                    'commit_short' => $l->commit_short,
                    'commit_message' => $l->commit_message,
                    'previous_commit' => $l->previous_commit,
                    'deployer' => $l->deployer?->only('id', 'name', 'email'),
                    'output' => $l->output,
                    'error_output' => $l->error_output,
                    'started_at' => $l->started_at,
                    'completed_at' => $l->completed_at,
                    'created_at' => $l->created_at,
                ]),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'repo_url' => ['nullable', 'string', 'max:512'],
            'branch' => ['nullable', 'string', 'max:100'],
            'deploy_url' => ['nullable', 'string', 'max:512', 'url'],
            'deploy_key' => ['nullable', 'string', 'max:512'],
            'github_token' => ['nullable', 'string', 'max:512'],
            'auto_migrate' => ['boolean'],
            'auto_composer' => ['boolean'],
            'maintenance_mode' => ['boolean'],
        ]);

        foreach (['repo_url', 'branch', 'deploy_url'] as $k) {
            if (array_key_exists($k, $data)) {
                DeploymentSetting::put($k, (string) ($data[$k] ?? ''));
            }
        }
        // Secrets: blank input = keep existing.
        foreach (['deploy_key', 'github_token'] as $k) {
            if (! empty($data[$k])) {
                DeploymentSetting::put($k, $data[$k]);
            }
        }
        foreach (['auto_migrate', 'auto_composer', 'maintenance_mode'] as $k) {
            DeploymentSetting::put($k, ! empty($data[$k]) ? '1' : '0');
        }

        return response()->json(['success' => true, 'message' => 'Deployment settings saved.']);
    }

    public function clearSecret(Request $request): JsonResponse
    {
        $this->guard($request);
        $key = $request->validate(['key' => ['required', 'in:deploy_key,github_token']])['key'];
        DeploymentSetting::put($key, '');

        return response()->json(['success' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json($this->client->status());
    }

    public function probe(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json($this->client->probe());
    }

    public function setupKey(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json($this->client->setupKey());
    }

    public function gitInfo(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json([
            'remote' => $this->client->gitInfo(),
            'github_head' => $this->git->githubHead(),
            'local' => $this->git->local(),
        ]);
    }

    public function cloneRepo(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json($this->client->clone());
    }

    public function deploy(Request $request): JsonResponse
    {
        $this->guard($request);

        $log = DeploymentLog::create([
            'branch' => DeploymentSetting::get('branch'),
            'action' => 'deploy',
            'status' => 'in_progress',
            'deployed_by' => $request->user()->id,
            'started_at' => now(),
        ]);

        $res = $this->client->deploy();
        $this->recordResult($log, $res);

        if ($res['ok']) {
            $this->bumpVersion();
        }

        return response()->json(array_merge($res, ['log_id' => $log->id]));
    }

    public function rollback(Request $request): JsonResponse
    {
        $this->guard($request);

        $mode = $request->input('mode', 'list');
        if ($mode === 'list') {
            $res = $this->client->rollbackList();

            return response()->json([
                'ok' => $res['configured'] ?? false,
                'configured' => $res['configured'] ?? false,
                'commits' => $res['data']['commits'] ?? [],
                'current' => $res['data']['current'] ?? null,
                'error' => $res['error'] ?? null,
            ]);
        }

        $data = $request->validate([
            'commit' => ['nullable', 'string', 'max:64', 'regex:/^[0-9a-f]+$/i'],
            'steps' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $log = DeploymentLog::create([
            'branch' => DeploymentSetting::get('branch'),
            'action' => 'rollback',
            'status' => 'in_progress',
            'deployed_by' => $request->user()->id,
            'started_at' => now(),
        ]);

        $res = $this->client->rollback($data['commit'] ?? null, $data['steps'] ?? null);
        $this->recordResult($log, $res, revertedOnSuccess: true);

        return response()->json(array_merge($res, ['log_id' => $log->id]));
    }

    public function runCommand(Request $request): JsonResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'command' => ['required', 'string', 'max:500'],
            'confirm_key' => ['nullable', 'string', 'max:512'],
        ]);

        // Allowlisted commands don't need the confirm key; anything else does — and
        // we forward it so the deploy script re-verifies (defence in depth).
        $allow = [
            'php artisan optimize:clear', 'php artisan migrate --force', 'php artisan migrate:status',
            'php artisan config:clear', 'php artisan cache:clear', 'php artisan queue:restart',
            'php artisan storage:link', 'git status', 'git log', 'git branch', 'composer install --no-dev',
        ];
        $isAllowed = false;
        foreach ($allow as $p) {
            if (str_starts_with($data['command'], $p)) {
                $isAllowed = true;
                break;
            }
        }
        if (! $isAllowed && empty($data['confirm_key'])) {
            return response()->json([
                'ok' => false,
                'error' => 'This command is not on the allowlist. Re-enter the deploy key to run it.',
            ], 422);
        }

        $log = DeploymentLog::create([
            'action' => 'command',
            'status' => 'in_progress',
            'commit_message' => 'cmd: '.mb_substr($data['command'], 0, 180),
            'deployed_by' => $request->user()->id,
            'started_at' => now(),
        ]);

        $res = $this->client->command($data['command'], $data['confirm_key'] ?? null);
        $ok = $res['ok'] ?? false;
        $log->finish(
            $ok ? 'success' : 'failed',
            json_encode($res['data'] ?? $res, JSON_PRETTY_PRINT),
            $ok ? null : ($res['error'] ?? null),
        );

        return response()->json(array_merge($res, ['log_id' => $log->id]));
    }

    public function remoteLog(Request $request): JsonResponse
    {
        $this->guard($request);
        $lines = (int) $request->input('lines', 200);

        return response()->json($this->client->remoteLog($lines));
    }

    public function cleanup(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json($this->client->cleanup());
    }

    public function selfDelete(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json($this->client->selfDelete());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Defence in depth on top of the route's `permission:manage_settings` gate:
     * deployment can execute code on the server, so require an actual admin
     * (super-admin where the guard supports it).
     */
    private function guard(Request $request): void
    {
        $user = $request->user();
        abort_if($user === null, 403);

        if (method_exists($user, 'isSuperAdmin')) {
            abort_unless($user->isSuperAdmin(), 403, 'Super admin access required.');

            return;
        }
        if (method_exists($user, 'isAdmin')) {
            abort_unless($user->isAdmin(), 403, 'Admin access required.');
        }
    }

    /** @param array<string,mixed> $res */
    private function recordResult(DeploymentLog $log, array $res, bool $revertedOnSuccess = false): void
    {
        $ok = $res['ok'] ?? false;
        $d = $res['data'] ?? [];
        $git = $d['git'] ?? [];
        $commitHash = $git['commit_hash'] ?? ($d['commit_hash'] ?? null);
        $commitLine = $git['commit'] ?? ($d['after'] ?? null);

        // The failure reason can live at the top level (transport/config error) or
        // inside the script's JSON body (data.error).
        $error = $ok
            ? null
            : ($res['error'] ?? ($d['error'] ?? ($res['body'] ?? 'Deployment reported issues — see output.')));

        $log->finish(
            status: $ok ? ($revertedOnSuccess ? 'reverted' : 'success') : 'failed',
            output: json_encode($d ?: $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            error: $error,
            git: array_filter([
                'commit_hash' => $commitHash,
                'commit_short' => $commitHash ? substr($commitHash, 0, 12) : null,
                'previous_commit' => $git['previous'] ?? ($d['previous_commit'] ?? null),
            ]),
        );

        if ($commitLine && ! $log->commit_message) {
            $log->update(['commit_message' => mb_substr((string) $commitLine, 0, 191)]);
        }

        if (! $ok) {
            Log::warning('deployment.failed', ['log_id' => $log->id, 'error' => $error]);
        }
    }

    private function bumpVersion(): void
    {
        $parts = explode('.', (string) DeploymentSetting::get('app_version', '1.0.0'));
        $major = (int) $parts[0];
        $minor = (int) ($parts[1] ?? 0);
        $patch = (int) ($parts[2] ?? 0);
        DeploymentSetting::put('app_version', "{$major}.{$minor}.".($patch + 1));
    }
}
