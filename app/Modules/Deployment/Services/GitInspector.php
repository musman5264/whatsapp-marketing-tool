<?php

namespace App\Modules\Deployment\Services;

use App\Modules\Deployment\Models\DeploymentSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Inspects the LOCAL repository (the copy this code runs from) and, when a repo
 * URL is configured, the latest commit on GitHub — so the admin can see whether
 * the running code is behind what's been pushed.
 *
 * On shared hosting where the deployed copy IS the target, this is the same repo
 * the DeployClient talks to; either signal is useful.
 */
class GitInspector
{
    /**
     * @return array<string,mixed>
     */
    public function local(): array
    {
        if (! is_dir(base_path('.git'))) {
            return ['is_git' => false];
        }

        return [
            'is_git' => true,
            'branch' => $this->git(['rev-parse', '--abbrev-ref', 'HEAD']),
            'commit' => $this->git(['log', '-1', '--format=%h %s']),
            'commit_hash' => $this->git(['rev-parse', 'HEAD']),
            'remote_url' => $this->git(['remote', 'get-url', 'origin']),
            'total_commits' => (int) $this->git(['rev-list', '--count', 'HEAD']),
            'recent_log' => array_values(array_filter(explode("\n", $this->git(['log', '--oneline', '-15'])))),
            'dirty' => $this->git(['status', '--porcelain']) !== '',
        ];
    }

    /**
     * Latest commit on the configured branch via the GitHub API (read-only).
     *
     * @return array<string,string>|null
     */
    public function githubHead(): ?array
    {
        $repoUrl = trim((string) DeploymentSetting::get('repo_url'));
        $branch = trim((string) DeploymentSetting::get('branch')) ?: 'main';
        $token = (string) DeploymentSetting::get('github_token');

        if ($repoUrl === '' || ! preg_match('#github\.com[/:]([^/]+)/([^/.]+)#', $repoUrl, $m)) {
            return null;
        }
        [$owner, $repo] = [$m[1], rtrim($m[2], '.git')];

        try {
            $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'wa-deploy/1.0'];
            if ($token !== '') {
                $headers['Authorization'] = "Bearer {$token}";
            }
            $resp = Http::withHeaders($headers)->timeout(10)
                ->get("https://api.github.com/repos/{$owner}/{$repo}/commits/".rawurlencode($branch));
        } catch (\Throwable) {
            return null;
        }

        if (! $resp->successful()) {
            return null;
        }
        $gh = $resp->json();

        return [
            'sha' => $gh['sha'] ?? '',
            'short_sha' => substr((string) ($gh['sha'] ?? ''), 0, 8),
            'message' => explode("\n", (string) ($gh['commit']['message'] ?? ''))[0],
            'author' => $gh['commit']['author']['name'] ?? '',
            'date' => $gh['commit']['author']['date'] ?? '',
            'html_url' => $gh['html_url'] ?? '',
        ];
    }

    /** @param list<string> $args */
    private function git(array $args): string
    {
        try {
            $r = Process::path(base_path())->timeout(15)->run(array_merge(['git'], $args));

            return trim($r->output());
        } catch (\Throwable) {
            return '';
        }
    }
}
