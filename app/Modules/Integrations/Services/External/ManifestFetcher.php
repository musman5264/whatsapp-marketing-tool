<?php

namespace App\Modules\Integrations\Services\External;

use App\Modules\Integrations\Models\ExternalCallLog;
use App\Modules\Integrations\Models\ExternalIntegration;
use Illuminate\Support\Facades\Log;

/**
 * Fetches and validates the manifest a linked system publishes. One invalid capability is skipped and
 * reported; the rest still load. Unknown fields are dropped, so a newer EPOS never breaks an older tool.
 */
class ManifestFetcher
{
    public const MAX_BYTES = 204800;

    public const STALE_AFTER_MINUTES = 10;

    private const PARAM_TYPES = ['text', 'number', 'date', 'choice', 'phone'];

    private const PARAM_SOURCES = ['sender_phone', 'chat_answer', 'fixed', 'incoming_message'];

    private const OUTPUT_TYPES = ['text', 'number', 'boolean', 'date', 'datetime'];

    public function __construct(private readonly SafeHttp $http, private readonly IntegrationHealth $health) {}

    /**
     * @return array{version: string, business: array<string, mixed>, capabilities: list<array<string, mixed>>, issues: list<array{key: string, reason: string}>}
     *
     * @throws ExternalCallException
     */
    public function fetch(string $manifestUrl, string $key): array
    {
        $response = $this->http->request('GET', $manifestUrl, $key, null, self::MAX_BYTES);
        if (! $response->successful()) {
            throw new ExternalCallException('http_'.$response->status(), 'The manifest request was answered with HTTP '.$response->status().'.');
        }

        $json = $response->json();
        if (! is_array($json) || ! is_array($json['capabilities'] ?? null) || ! is_string($json['manifest_version'] ?? null) || $json['manifest_version'] === '') {
            throw new ExternalCallException('invalid_manifest', 'The manifest must be JSON with a manifest_version and a capabilities list.');
        }

        $capabilities = [];
        $issues = [];
        foreach ($json['capabilities'] as $index => $raw) {
            $name = is_array($raw) && is_string($raw['key'] ?? null) ? $raw['key'] : "#{$index}";
            $reason = is_array($raw) ? $this->invalidReason($raw) : 'not an object';
            if ($reason === null && isset($capabilities[$name])) {
                $reason = 'duplicate key';
            }
            if ($reason !== null) {
                $issues[] = ['key' => $name, 'reason' => $reason];

                continue;
            }
            $capabilities[$name] = $this->clean($raw);
        }

        return [
            'version' => $json['manifest_version'],
            'business' => is_array($json['business'] ?? null) ? $json['business'] : [],
            'capabilities' => array_values($capabilities),
            'issues' => $issues,
        ];
    }

    /** Re-reads the manifest. A failure is counted against the integration's health, then rethrown. */
    public function refresh(ExternalIntegration $integration): void
    {
        $started = microtime(true);
        try {
            $this->apply($integration, $this->fetch($integration->manifest_url, $integration->key));
            $this->health->recordSuccess($integration);
            Log::info('external.manifest', ['integration_id' => $integration->id, 'status' => 'ok', 'ms' => $this->ms($started)]);
            $this->record($integration, 'found', 'ok', $this->ms($started), 'Question list read.');
        } catch (ExternalCallException $e) {
            $this->health->recordFailure($integration);
            Log::warning('external.manifest', ['integration_id' => $integration->id, 'status' => $e->reason, 'ms' => $this->ms($started)]);
            $this->record($integration, 'no_data', $e->reason, $this->ms($started), $e->getMessage());

            throw $e;
        }
    }

    /** For the builder or panel: refresh when the cache is older than 10 minutes. Never throws. */
    public function refreshIfStale(ExternalIntegration $integration): void
    {
        $fresh = $integration->manifest_fetched_at?->gt(now()->subMinutes(self::STALE_AFTER_MINUTES));
        $backingOff = $integration->status === ExternalIntegration::STATUS_UNREACHABLE && $integration->next_retry_at?->isFuture();
        if ($fresh || $backingOff) {
            return;
        }

        try {
            $this->refresh($integration);
        } catch (ExternalCallException) {
            // already counted in health; the cached manifest stays usable
        }
    }

    /** @param array{version: string, business: array<string, mixed>, capabilities: list<array<string, mixed>>, issues: list<array{key: string, reason: string}>} $manifest */
    public function apply(ExternalIntegration $integration, array $manifest): void
    {
        $integration->manifest_version = $manifest['version'];
        $integration->manifest = ['business' => $manifest['business'], 'capabilities' => $manifest['capabilities']];
        $integration->manifest_issues = $manifest['issues'];
        $integration->manifest_fetched_at = now();
        $integration->save();
    }

    private function record(ExternalIntegration $integration, string $outcome, string $status, int $ms, string $message): void
    {
        try {
            ExternalCallLog::record($integration, 'manifest', $outcome, $status, $ms, $message);
        } catch (\Throwable) {
            Log::warning('external.call_log_failed', ['integration_id' => $integration->id]);
        }
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /** @param array<string, mixed> $c */
    private function invalidReason(array $c): ?string
    {
        if (! is_string($c['key'] ?? null) || ! preg_match('/^[a-z][a-z0-9_.]{0,63}$/i', $c['key'])) {
            return 'bad key';
        }
        $kind = $c['kind'] ?? null;
        if (! in_array($kind, ['query', 'action'], true)) {
            return 'kind must be query or action';
        }
        if (($c['method'] ?? null) !== ($kind === 'query' ? 'GET' : 'POST')) {
            return 'method must be GET for a query and POST for an action';
        }
        // A path only: a full URL here would let the manifest steer the call to another host.
        if (! is_string($c['path'] ?? null) || ! preg_match('#^/(?!/)[^\s\\\\]*$#', $c['path']) || str_contains($c['path'], '://')) {
            return 'path must start with a single /';
        }
        if (! is_string($c['label']['en'] ?? null) || $c['label']['en'] === '') {
            return 'label.en is required';
        }
        foreach ((array) ($c['params'] ?? []) as $p) {
            if (! is_array($p) || ! is_string($p['name'] ?? null) || ! preg_match('/^[a-z_][a-z0-9_]*$/i', $p['name'])) {
                return 'bad param name';
            }
            if (! in_array($p['type'] ?? null, self::PARAM_TYPES, true)) {
                return "param {$p['name']}: unknown type";
            }
            if (! in_array($p['from'] ?? null, self::PARAM_SOURCES, true)) {
                return "param {$p['name']}: unknown source";
            }
            if ($p['type'] === 'choice' && (empty($p['choices']) || ! is_array($p['choices']))) {
                return "param {$p['name']}: choice needs choices";
            }
            // Long chat text (an order, an alert). Without it a text value stops at 200 characters.
            // "1500" and 1500.0 are accepted too (other systems often send numbers as text); cast in clean().
            if (isset($p['max_length']) && (! is_numeric($p['max_length']) || (int) $p['max_length'] < 1 || (float) $p['max_length'] != (int) $p['max_length'])) {
                return "param {$p['name']}: max_length must be a whole number of 1 or more";
            }
            // The only values a text param accepts (alert.push `kind`: order / complaint).
            if (isset($p['enum']) && (! is_array($p['enum']) || $p['enum'] === [] || array_filter($p['enum'], fn ($v) => ! is_string($v) || $v === ''))) {
                return "param {$p['name']}: enum must be a list of non-empty text values";
            }
        }
        foreach ((array) ($c['outputs'] ?? []) as $o) {
            if (! is_array($o) || ! is_string($o['path'] ?? null) || $o['path'] === '' || ! in_array($o['type'] ?? null, self::OUTPUT_TYPES, true)) {
                return 'bad output';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function clean(array $c): array
    {
        $params = array_map(function ($p) {
            $p = array_intersect_key($p, array_flip(['name', 'type', 'from', 'optional', 'required_if', 'default', 'choices', 'max_length', 'enum']));
            if (isset($p['enum'])) {
                $p['enum'] = array_values($p['enum']);
            }
            if (isset($p['max_length'])) {
                $p['max_length'] = (int) $p['max_length'];
            }

            return $p;
        }, (array) ($c['params'] ?? []));

        return array_intersect_key($c, array_flip(['key', 'kind', 'group', 'label', 'description', 'method', 'path', 'outputs', 'summary_template']))
            + ['params' => $params];
    }
}
