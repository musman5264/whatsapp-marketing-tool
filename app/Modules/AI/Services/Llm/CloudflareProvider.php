<?php

namespace App\Modules\AI\Services\Llm;

use App\Modules\AI\Services\Llm\Concerns\MultiKeyFailover;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Workers AI provider with multi-key failover.
 *
 * Credentials shape (from AiProviderConfig->credentials or IntegrationConfig):
 *   account_id    string   Cloudflare account id (shared by every key)
 *   api_key       string   a single API token (back-compat / simplest case)
 *   api_keys      string[] one or more API tokens, tried in order
 *   gateway_slug  ?string  AI Gateway slug — when set, calls route through the gateway
 *
 * Failover: on ANY error for a key (401/403/429/5xx/timeout/network) we mark that
 * key unhealthy for a cooldown window and move to the next key.
 */
class CloudflareProvider implements LlmProviderInterface
{
    use MultiKeyFailover;

    private const DIRECT_BASE = 'https://api.cloudflare.com/client/v4/accounts';

    private const GATEWAY_BASE = 'https://gateway.ai.cloudflare.com/v1';

    private string $accountId;

    private ?string $gatewaySlug;

    /** @var string[] */
    private array $apiKeys;

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        array $credentials,
        private readonly string $chatModel = '@cf/meta/llama-3.1-8b-instruct',
        private readonly string $embedModel = '@cf/baai/bge-base-en-v1.5',
    ) {
        $this->accountId = trim((string) ($credentials['account_id'] ?? ''));
        $this->gatewaySlug = trim((string) ($credentials['gateway_slug'] ?? '')) ?: null;
        $this->apiKeys = self::extractKeys($credentials);

        if ($this->accountId === '') {
            throw new \RuntimeException('Cloudflare account_id is not configured.');
        }
        if (empty($this->apiKeys)) {
            throw new \RuntimeException('No Cloudflare API keys are configured.');
        }
    }

    /**
     * Back-compat alias — older callers use normaliseKeys().
     *
     * @param  array<string, mixed>  $credentials
     * @return string[]
     */
    public static function normaliseKeys(array $credentials): array
    {
        return self::extractKeys($credentials);
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $opts
     */
    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $model = $opts['model'] ?? $this->chatModel;

        $payload = [
            'messages' => array_map(fn ($m) => [
                'role' => $m['role'] === 'assistant' ? 'assistant' : ($m['role'] === 'system' ? 'system' : 'user'),
                'content' => $m['content'],
            ], $messages),
            'max_tokens' => $opts['max_tokens'] ?? 1024,
        ];

        $start = microtime(true);
        $json = $this->run($model, $payload);
        $latency = (int) ((microtime(true) - $start) * 1000);

        $result = $json['result'] ?? [];
        $content = $result['response'] ?? '';
        $usage = $result['usage'] ?? [];

        return new LlmResponse(
            content: is_string($content) ? $content : (string) json_encode($content),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            model: $model,
            latencyMs: $latency,
        );
    }

    /**
     * @param  string[]  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $json = $this->run($this->embedModel, ['text' => array_values($texts)]);
        $data = $json['result']['data'] ?? [];

        return array_map(fn ($vec) => is_array($vec) ? array_values($vec) : [], $data);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function run(string $model, array $payload): array
    {
        $endpoint = $this->endpoint($model);

        return $this->tryKeys('cloudflare', $this->apiKeys, function (string $key) use ($endpoint, $payload) {
            $resp = Http::withToken($key)->timeout(60)->post($endpoint, $payload);

            if ($resp->successful() && ($resp->json('success') ?? true) !== false) {
                return ['ok' => true, 'data' => $resp->json() ?? []];
            }

            return [
                'ok' => false,
                'error' => $resp->json('errors.0.message')
                    ?? $resp->json('error.message')
                    ?? ('HTTP '.$resp->status()),
            ];
        });
    }

    private function endpoint(string $model): string
    {
        $path = ltrim($model, '@');

        if ($this->gatewaySlug) {
            return self::GATEWAY_BASE."/{$this->accountId}/{$this->gatewaySlug}/workers-ai/{$path}";
        }

        return self::DIRECT_BASE."/{$this->accountId}/ai/run/{$model}";
    }
}
