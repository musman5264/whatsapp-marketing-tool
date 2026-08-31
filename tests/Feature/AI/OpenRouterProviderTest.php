<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\OpenRouterProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenRouterProviderTest extends TestCase
{
    use RefreshDatabase;

    private function ok(string $text = 'ok', string $model = 'meta-llama/llama-3.3-70b-instruct:free'): array
    {
        return [
            'model' => $model,
            'choices' => [['message' => ['content' => $text]]],
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
        ];
    }

    #[Test]
    public function chat_calls_the_openai_compatible_endpoint(): void
    {
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response($this->ok('hi there'), 200)]);

        $p = new OpenRouterProvider(['api_keys' => "k1\nk2"]);
        $resp = $p->chat([['role' => 'user', 'content' => 'hello']], ['model' => 'deepseek/deepseek-r1:free']);

        $this->assertSame('hi there', $resp->content);
        Http::assertSent(function ($r) {
            $body = json_decode($r->body(), true);

            return str_contains($r->url(), 'openrouter.ai/api/v1/chat/completions')
                && $body['model'] === 'deepseek/deepseek-r1:free'
                && $r->hasHeader('Authorization', 'Bearer k1');
        });
    }

    #[Test]
    public function it_fails_over_to_the_next_key_on_429(): void
    {
        Cache::flush();
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->header('Authorization')[0] ?? '';

            return count($seen) === 1
                ? Http::response(['error' => ['message' => 'rate limited']], 429)
                : Http::response($this->ok('recovered'), 200);
        });

        $p = new OpenRouterProvider(['api_keys' => "key_a\nkey_b"]);
        $resp = $p->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('recovered', $resp->content);
        $this->assertSame(['Bearer key_a', 'Bearer key_b'], $seen);
    }

    #[Test]
    public function it_throws_when_all_keys_fail(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['error' => ['message' => 'no credits']], 402)]);

        $p = new OpenRouterProvider(['api_keys' => "a\nb"]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('All openrouter API keys failed');
        $p->chat([['role' => 'user', 'content' => 'hi']]);
    }

    #[Test]
    public function free_models_filters_to_zero_priced_and_caches(): void
    {
        Cache::flush();
        Http::fake(['openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                ['id' => 'x/free-1:free', 'pricing' => ['prompt' => '0', 'completion' => '0']],
                ['id' => 'x/paid-1', 'pricing' => ['prompt' => '0.001', 'completion' => '0.002']],
                ['id' => 'x/free-2:free', 'pricing' => ['prompt' => '0', 'completion' => '0']],
            ],
        ], 200)]);

        $models = OpenRouterProvider::freeModels('k');
        $this->assertSame(['x/free-1:free', 'x/free-2:free'], $models);

        // second call served from cache — no extra HTTP
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []], 500)]);
        $this->assertSame($models, OpenRouterProvider::freeModels('k'));
    }

    #[Test]
    public function free_models_falls_back_to_the_static_catalog_on_error(): void
    {
        Cache::flush();
        Http::fake(['openrouter.ai/api/v1/models' => Http::response('nope', 500)]);

        $models = OpenRouterProvider::freeModels();
        $this->assertContains('meta-llama/llama-3.3-70b-instruct:free', $models);
    }

    #[Test]
    public function llm_manager_builds_openrouter_from_workspace_config(): void
    {
        Cache::flush();
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response($this->ok('via mgr'), 200)]);

        $ctx = $this->createWorkspaceContext();
        AiProviderConfig::create([
            'workspace_id' => $ctx['workspace']->id,
            'provider' => 'openrouter',
            'credentials' => ['api_keys' => "orkey1\norkey2"],
            'default_model_chat' => 'google/gemma-2-9b-it:free',
            'enabled' => true,
        ]);

        $provider = LlmManager::forWorkspace($ctx['workspace']->id);
        $this->assertInstanceOf(OpenRouterProvider::class, $provider);
        $this->assertSame('via mgr', $provider->chat([['role' => 'user', 'content' => 'hi']])->content);
    }

    #[Test]
    public function embed_is_not_supported(): void
    {
        $p = new OpenRouterProvider(['api_key' => 'k']);
        $this->expectException(\RuntimeException::class);
        $p->embed(['text']);
    }
}
