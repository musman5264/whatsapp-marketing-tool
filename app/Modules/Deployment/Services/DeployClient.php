<?php

namespace App\Modules\Deployment\Services;

use App\Modules\Deployment\Models\DeploymentSetting;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client to the standalone public/deploy.php on the target server.
 * All calls are GET with ?key=<deploy_key>&action=<action>; the script returns JSON.
 */
class DeployClient
{
    /**
     * @param  array<string,scalar>  $params
     * @return array{ok: bool, configured: bool, status?: int, data?: array<string,mixed>, error?: string, body?: string}
     */
    public function call(string $action, array $params = [], int $timeout = 180): array
    {
        $url = trim((string) DeploymentSetting::get('deploy_url'));
        $key = (string) DeploymentSetting::get('deploy_key');

        if ($url === '' || $key === '') {
            return ['ok' => false, 'configured' => false, 'error' => 'Deploy script URL and key are not configured.'];
        }

        try {
            $resp = Http::timeout($timeout)->acceptJson()->get($url, array_merge([
                'key' => $key,
                'action' => $action,
            ], $params));
        } catch (\Throwable $e) {
            return ['ok' => false, 'configured' => true, 'error' => 'Cannot reach the deploy script: '.$e->getMessage()];
        }

        $data = null;
        try {
            $data = $resp->json();
        } catch (\Throwable) {
            // non-JSON
        }

        if (! is_array($data)) {
            return [
                'ok' => false,
                'configured' => true,
                'status' => $resp->status(),
                'error' => 'The deploy script returned a non-JSON response (HTTP '.$resp->status().'). Check the URL.',
                'body' => mb_substr((string) $resp->body(), 0, 1000),
            ];
        }

        return [
            'ok' => $resp->successful() && ($data['ok'] ?? false),
            'configured' => true,
            'status' => $resp->status(),
            'data' => $data,
        ];
    }

    public function probe(): array
    {
        return $this->call('probe', [], 30);
    }

    public function status(): array
    {
        return $this->call('status', [], 30);
    }

    public function setupKey(): array
    {
        return $this->call('setup-key', [], 30);
    }

    public function gitInfo(): array
    {
        return $this->call('git-info', $this->tokenParam(), 60);
    }

    public function clone(): array
    {
        return $this->call('clone', $this->tokenParam(), 300);
    }

    public function deploy(): array
    {
        return $this->call('deploy', array_merge($this->tokenParam(), [
            'migrate' => DeploymentSetting::get('auto_migrate') === '1' ? '1' : '0',
            'composer' => DeploymentSetting::get('auto_composer') === '1' ? '1' : '0',
        ]), 600);
    }

    public function rollbackList(): array
    {
        return $this->call('rollback', ['list' => 20], 30);
    }

    public function rollback(?string $commit, ?int $steps): array
    {
        $p = $commit ? ['commit' => $commit] : ['steps' => max(1, min((int) $steps, 20))];

        return $this->call('rollback', $p, 300);
    }

    public function command(string $command, ?string $confirmKey = null): array
    {
        $p = ['command' => $command];
        if ($confirmKey !== null && $confirmKey !== '') {
            $p['key2'] = $confirmKey;
        }

        return $this->call('cmd', $p, 120);
    }

    public function remoteLog(int $lines = 200): array
    {
        return $this->call('log', ['lines' => max(10, min($lines, 500))], 30);
    }

    public function cleanup(): array
    {
        return $this->call('cleanup', [], 30);
    }

    public function selfDelete(): array
    {
        return $this->call('selfdelete', [], 30);
    }

    /** @return array<string,string> */
    private function tokenParam(): array
    {
        $token = (string) DeploymentSetting::get('github_token');

        return $token !== '' ? ['token' => $token] : [];
    }
}
