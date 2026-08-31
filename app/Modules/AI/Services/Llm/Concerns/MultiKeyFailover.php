<?php

namespace App\Modules\AI\Services\Llm\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shared multi-API-key failover for LLM providers (Cloudflare, OpenRouter, …).
 *
 * A provider using this trait supplies an ordered list of keys and a per-key
 * request closure. On ANY failure for a key (4xx/5xx/timeout/network) that key
 * is benched for a cooldown window and the next key is tried. If every key
 * fails, the accumulated errors are thrown.
 */
trait MultiKeyFailover
{
    /** Seconds a key stays benched after a failure. */
    protected int $failoverCooldownSeconds = 300;

    /**
     * @param  string[]  $keys
     * @param  callable(string $key): array<string, mixed>  $attempt  returns ['ok' => bool, 'data' => array, 'error' => string]
     * @return array<mixed>
     */
    protected function tryKeys(string $cacheNamespace, array $keys, callable $attempt): array
    {
        $errors = [];

        foreach ($keys as $i => $key) {
            $label = 'key #'.($i + 1);

            if ($this->keyIsBenched($cacheNamespace, $key)) {
                $errors[] = $label.': skipped (cooling down)';

                continue;
            }

            try {
                $result = $attempt($key);
                if (($result['ok'] ?? false) === true) {
                    return is_array($result['data'] ?? null) ? $result['data'] : [];
                }
                $msg = is_string($result['error'] ?? null) ? $result['error'] : 'unknown error';
                $this->benchKey($cacheNamespace, $key, $msg);
                $errors[] = $label.': '.$msg;
            } catch (\Throwable $e) {
                $this->benchKey($cacheNamespace, $key, $e->getMessage());
                $errors[] = $label.': '.$e->getMessage();
            }
        }

        throw new \RuntimeException(
            'All '.$cacheNamespace.' API keys failed. '.implode(' | ', $errors)
        );
    }

    private function benchCacheKey(string $namespace, string $key): string
    {
        return $namespace.'_llm_key_unhealthy:'.substr(hash('sha256', $key), 0, 32);
    }

    protected function keyIsBenched(string $namespace, string $key): bool
    {
        return Cache::has($this->benchCacheKey($namespace, $key));
    }

    protected function benchKey(string $namespace, string $key, string $reason): void
    {
        Cache::put($this->benchCacheKey($namespace, $key), $reason, $this->failoverCooldownSeconds);
        Log::channel('json')->warning('llm.key_benched', [
            'provider' => $namespace,
            'key_hint' => substr($key, 0, 6).'…',
            'reason' => $reason,
            'cooldown_seconds' => $this->failoverCooldownSeconds,
        ]);
    }

    /**
     * Split a pasted list (newline / comma / whitespace separated) + a single
     * `api_key` fallback into a de-duplicated ordered key list.
     *
     * @param  array<string, mixed>  $credentials
     * @return string[]
     */
    public static function extractKeys(array $credentials): array
    {
        $keys = [];

        $multi = $credentials['api_keys'] ?? null;
        if (is_string($multi)) {
            $multi = preg_split('/[\s,]+/', $multi, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (is_array($multi)) {
            $keys = array_merge($keys, $multi);
        }

        if (! empty($credentials['api_key'])) {
            $keys[] = $credentials['api_key'];
        }

        return array_values(array_unique(array_filter(array_map('trim', $keys))));
    }
}
