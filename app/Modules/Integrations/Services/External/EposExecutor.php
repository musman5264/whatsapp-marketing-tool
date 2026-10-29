<?php

namespace App\Modules\Integrations\Services\External;

use App\Modules\Integrations\Models\ExternalCallLog;
use App\Modules\Integrations\Models\ExternalIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Runs one manifest capability against a linked system during a chat. It never throws and never
 * shows the customer an error: anything that goes wrong is a "no data" outcome. The customer's own
 * WhatsApp number is bound here, not by the flow author, so a flow can only ever ask about the
 * person who is chatting.
 */
class EposExecutor
{
    public const CALLS_PER_MINUTE = 60;

    public const CACHE_SECONDS = 60;

    /** Longest text value a manifest may ask for with max_length; 200 when it does not say. */
    public const MAX_TEXT = 2000;

    public const DEFAULT_TEXT = 200;

    /** Cap for a param whose value is the sender's profile photo (base64), the only text that is allowed to be this long. */
    public const MAX_PHOTO = 900000;

    public function __construct(
        private readonly SafeHttp $http,
        private readonly IntegrationHealth $health,
        private readonly ManifestFetcher $manifests,
    ) {}

    /**
     * @param  string|null  $phone  the chat contact's E.164 number, null when it is not a verified number
     * @param  array<string, mixed>  $authorParams  values the flow author mapped, keyed by manifest param name (tokens already rendered)
     * @return array{outcome: 'found'|'not_found'|'no_data', body: array<string, mixed>, message: string}
     */
    public function execute(ExternalIntegration $integration, string $capabilityKey, ?string $phone, array $authorParams, string $idempotencySeed): array
    {
        $started = microtime(true);
        $status = 'skipped';
        $debug = null;
        $result = $this->run($integration, $capabilityKey, $phone, $authorParams, $idempotencySeed, $status, $debug);
        $ms = (int) round((microtime(true) - $started) * 1000);

        Log::info('external.call', [
            'integration_id' => $integration->id,
            'capability' => $capabilityKey,
            'status' => $status,
            'ms' => $ms,
            'found' => $result['outcome'] === 'found',
        ]);

        // The table the admin reads. Never allowed to break a chat.
        try {
            ExternalCallLog::record($integration, $capabilityKey, $result['outcome'], $status, $ms, $result['message'], $debug['request'] ?? null, $debug['response'] ?? null);
        } catch (\Throwable) {
            Log::warning('external.call_log_failed', ['integration_id' => $integration->id]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $author
     * @param  array{request?: string, response?: string}|null  $debug  filled only while the admin has debug recording on
     * @return array{outcome: string, body: array<string, mixed>, message: string}
     */
    private function run(ExternalIntegration $integration, string $key, ?string $phone, array $author, string $idempotencySeed, string &$status, ?array &$debug): array
    {
        $capability = null;
        foreach ($integration->manifest['capabilities'] ?? [] as $candidate) {
            if (($candidate['key'] ?? null) === $key) {
                $capability = $candidate;
            }
        }
        if (! $capability) {
            return $this->noData('This E-POS question is no longer available.');
        }
        if ($integration->status === ExternalIntegration::STATUS_UNREACHABLE && $integration->next_retry_at?->isFuture()) {
            return $this->noData('E-POS is unreachable; not calling it until the next retry.');
        }

        $values = $this->bind($capability['params'] ?? [], $phone, $author, $error, $reason);
        if ($values === null) {
            return $this->noData($error, $reason);
        }

        $personSpecific = $this->carriesPhone($capability['params'] ?? []);
        $cacheKey = null;
        if ($capability['kind'] === 'query' && ! $personSpecific) {
            $cacheKey = "epos:{$integration->id}:{$key}:".md5(json_encode($values));
            if (is_array($cached = Cache::get($cacheKey))) {
                $status = 'cache';

                return $cached;
            }
        }

        $limiter = 'epos-calls:'.$integration->id;
        if (RateLimiter::tooManyAttempts($limiter, self::CALLS_PER_MINUTE)) {
            return $this->noData('Too many E-POS calls this minute.');
        }
        RateLimiter::hit($limiter, 60);

        $url = rtrim($integration->base_url, '/').$capability['path'];
        if ($integration->isDebugging()) {
            $isAction = $capability['kind'] === 'action';
            $debug = ['request' => ExternalCallLog::payload(
                ($isAction ? 'POST ' : 'GET ').$capability['path'].(! $isAction && $values ? '?'.http_build_query($values) : '').($isAction ? '
'.json_encode($values, JSON_UNESCAPED_UNICODE) : '')
            )];
        }
        try {
            // shortcut: queries are not retried (spec says MAY); add one retry on a connection error if EPOS proves flaky.
            $response = $capability['kind'] === 'action'
                ? $this->http->request('POST', $url, $integration->key, $values, SafeHttp::DEFAULT_MAX_BYTES, ['Idempotency-Key' => sha1($idempotencySeed.':'.$key)])
                : $this->http->request('GET', $url.($values ? '?'.http_build_query($values) : ''), $integration->key);
        } catch (ExternalCallException $e) {
            $status = $e->reason;
            if ($debug !== null) {
                $debug['response'] = 'ERROR '.$e->reason;
            }
            if ($e->reason !== 'unsafe_target') {
                $this->health->recordFailure($integration);
            }

            return $this->noData('E-POS call failed: '.$e->reason.'.');
        }

        $status = (string) $response->status();
        if ($debug !== null) {
            $debug['response'] = ExternalCallLog::payload($response->status().' '.$response->body());
        }
        $json = $response->json();
        if (! $response->successful() || ! is_array($json)) {
            $this->health->recordFailure($integration);

            return $this->noData('E-POS answered with HTTP '.$response->status().' or invalid JSON.');
        }

        if ($integration->consecutive_failures > 0) {
            $this->health->recordSuccess($integration);
        }
        $version = (string) $response->header('X-Epos-Manifest-Version');
        if ($version !== '' && $version !== $integration->manifest_version) {
            try {
                $this->manifests->refresh($integration);
            } catch (ExternalCallException) {
                // counted in health; this answer is still good
            }
        }

        $found = ! empty($json['found']) && ($capability['kind'] !== 'action' || ($json['ok'] ?? false) === true);
        $result = ['outcome' => $found ? 'found' : 'not_found', 'body' => $json, 'message' => $found ? 'E-POS answered.' : 'E-POS found nothing.'];
        if ($cacheKey) {
            Cache::put($cacheKey, $result, self::CACHE_SECONDS);
        }

        return $result;
    }

    /**
     * Build the values to send. Anything that identifies the customer comes from $phone, never from $author.
     *
     * @param  array<int, array<string, mixed>>  $params
     * @param  array<string, mixed>  $author
     * @param  string|null  $reason  set with $error: 'no_phone' (no verified number) or 'invalid' (a value is missing or wrong); the flow can reply to the customer by it, as for EPOS's own refusals
     * @return array<string, string>|null null (with $error set) when a required value is missing or invalid
     */
    private function bind(array $params, ?string $phone, array $author, ?string &$error = null, ?string &$reason = null): ?array
    {
        $raw = [];
        foreach ($params as $p) {
            $raw[$p['name']] = $this->isCustomerPhone($p) ? $phone : ($author[$p['name']] ?? $p['default'] ?? null);
            if ($this->isCustomerPhone($p) && $phone === null) {
                $error = 'This chat has no verified WhatsApp number.';
                $reason = 'no_phone';

                return null;
            }
        }

        $values = [];
        foreach ($params as $p) {
            $name = $p['name'];
            $value = trim((string) ($raw[$name] ?? ''));
            if ($value === '') {
                $requiredIf = $p['required_if'] ?? null;
                $required = empty($p['optional']) || (is_array($requiredIf) && $requiredIf !== [] && trim((string) ($raw[array_key_first($requiredIf)] ?? '')) === (string) reset($requiredIf));
                if ($required) {
                    $error = "Missing value for {$name}.";
                    $reason = 'invalid';

                    return null;
                }

                continue;
            }
            if (! $this->validValue($p, $value)) {
                $error = "Invalid value for {$name}.";
                $reason = 'invalid';

                return null;
            }
            $values[$name] = $value;
        }

        return $values;
    }

    /** @param array<string, mixed> $p */
    private function isCustomerPhone(array $p): bool
    {
        return ($p['type'] ?? '') === 'phone' || ($p['from'] ?? '') === 'sender_phone' || strtolower((string) ($p['name'] ?? '')) === 'phone';
    }

    /** @param array<int, array<string, mixed>> $params */
    private function carriesPhone(array $params): bool
    {
        foreach ($params as $p) {
            if ($this->isCustomerPhone($p)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $p */
    private function validValue(array $p, string $value): bool
    {
        if (! empty($p['enum']) && ! in_array($value, $p['enum'], true)) {
            return false;
        }

        return match ($p['type'] ?? 'text') {
            'number' => is_numeric($value),
            'date' => (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]),
            'choice' => in_array($value, array_map(fn ($c) => (string) ($c['value'] ?? ''), $p['choices'] ?? []), true),
            'phone' => true, // always the customer's own number, set by bind()
            default => mb_strlen($value) <= min((int) ($p['max_length'] ?? self::DEFAULT_TEXT), ($p['from'] ?? '') === 'profile_photo' ? self::MAX_PHOTO : self::MAX_TEXT),
        };
    }

    /** @return array{outcome: string, body: array<string, mixed>, message: string} */
    private function noData(string $message, string $reason = 'no_data'): array
    {
        return ['outcome' => 'no_data', 'body' => ['found' => false, 'ok' => false, 'reason' => $reason], 'message' => $message];
    }
}
