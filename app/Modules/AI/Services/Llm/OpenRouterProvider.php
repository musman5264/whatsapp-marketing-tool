<?php

namespace App\Modules\AI\Services\Llm;

use App\Modules\AI\Services\Llm\Concerns\MultiKeyFailover;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OpenRouter provider with multi-key failover.
 *
 * OpenRouter is an OpenAI-compatible gateway (https://openrouter.ai). We surface
 * its free tier (":free" model ids) but any model id the user types is accepted.
 *
 * Credentials shape:
 *   api_key    string    a single API key (back-compat)
 *   api_keys   string[]  one or more API keys, tried in order on failure
 *
 * Failover: on ANY error for a key (401/402/403/429/5xx/timeout/network) that
 * key is benched for a cooldown window and the next key is tried.
 */
class OpenRouterProvider implements LlmProviderInterface
{
    use MultiKeyFailover;

    private const BASE = 'https://openrouter.ai/api/v1';

    /** @var string[] */
    private array $apiKeys;

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        array $credentials,
        private readonly string $chatModel = 'meta-llama/llama-3.3-70b-instruct:free',
    ) {
        $this->apiKeys = self::extractKeys($credentials);

        if (empty($this->apiKeys)) {
            throw new \RuntimeException('No OpenRouter API keys are configured.');
        }
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $opts
     */
    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $model = $opts['model'] ?? $this->chatModel;

        $payload = [
            'model' => $model,
            'messages' => array_map(fn ($m) => [
                'role' => in_array($m['role'], ['system', 'assistant'], true) ? $m['role'] : 'user',
                'content' => $m['content'],
            ], $messages),
            'max_tokens' => $opts['max_tokens'] ?? 1024,
        ];

        $start = microtime(true);
        $json = $this->run('/chat/completions', $payload);
        $latency = (int) ((microtime(true) - $start) * 1000);

        $usage = $json['usage'] ?? [];

        return new LlmResponse(
            content: $json['choices'][0]['message']['content'] ?? '',
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            model: $json['model'] ?? $model,
            latencyMs: $latency,
        );
    }

    /**
     * @param  string[]  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        throw new \RuntimeException('OpenRouter does not expose an embeddings endpoint. Use OpenAI, Gemini or Cloudflare.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function run(string $path, array $payload): array
    {
        return $this->tryKeys('openrouter', $this->apiKeys, function (string $key) use ($path, $payload) {
            $resp = Http::withToken($key)
                ->withHeaders([
                    // OpenRouter asks for these for attribution; harmless if generic.
                    'HTTP-Referer' => config('app.url', 'https://localhost'),
                    'X-Title' => config('app.name', 'WhatsApp Marketing'),
                ])
                ->timeout(60)
                ->post(self::BASE.$path, $payload);

            if ($resp->successful()) {
                return ['ok' => true, 'data' => $resp->json() ?? []];
            }

            return [
                'ok' => false,
                'error' => $resp->json('error.message') ?? ('HTTP '.$resp->status()),
            ];
        });
    }

    /**
     * Live list of free OpenRouter models (prompt & completion price == 0),
     * cached 6h. Falls back to the static ModelCatalog list on any error.
     *
     * @return string[]
     */
    public static function freeModels(?string $anyApiKey = null): array
    {
        return Cache::remember('openrouter_free_models', now()->addHours(6), function () use ($anyApiKey) {
            try {
                $req = Http::timeout(15);
                if ($anyApiKey) {
                    $req = $req->withToken($anyApiKey);
                }
                $resp = $req->get(self::BASE.'/models');
                if (! $resp->successful()) {
                    return ModelCatalog::chatModels('openrouter');
                }

                $free = [];
                foreach ((array) $resp->json('data', []) as $m) {
                    if (! is_array($m)) {
                        continue;
                    }
                    $pricing = $m['pricing'] ?? [];
                    $id = $m['id'] ?? null;
                    if (! is_string($id) || $id === '') {
                        continue;
                    }
                    if ((float) ($pricing['prompt'] ?? 1) === 0.0 && (float) ($pricing['completion'] ?? 1) === 0.0) {
                        $free[] = $id;
                    }
                }
                sort($free);

                return ! empty($free) ? $free : ModelCatalog::chatModels('openrouter');
            } catch (\Throwable) {
                return ModelCatalog::chatModels('openrouter');
            }
        });
    }
}
