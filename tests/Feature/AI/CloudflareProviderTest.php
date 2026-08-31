<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\Llm\CloudflareProvider;
use App\Modules\AI\Services\Llm\LlmManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CloudflareProviderTest extends TestCase
{
    use RefreshDatabase;

    private function creds(array $override = []): array
    {
        return array_merge([
            'account_id' => 'acc123',
            'api_keys' => "key_one\nkey_two\nkey_three",
        ], $override);
    }

    private function okBody(string $text = 'ok'): array
    {
        return [
            'success' => true,
            'result' => ['response' => $text, 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1]],
        ];
    }

    #[Test]
    public function it_normalises_keys_from_a_pasted_list(): void
    {
        $keys = CloudflareProvider::extractKeys([
            'api_keys' => "  a \n b,c\n\nb ",
            'api_key' => 'd',
        ]);

        $this->assertSame(['a', 'b', 'c', 'd'], $keys);
        // back-compat alias
        $this->assertSame($keys, CloudflareProvider::normaliseKeys([
            'api_keys' => "  a \n b,c\n\nb ",
            'api_key' => 'd',
        ]));
    }

    #[Test]
    public function chat_uses_the_direct_workers_ai_endpoint(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response($this->okBody('hello'), 200),
        ]);

        $provider = new CloudflareProvider($this->creds(), '@cf/meta/llama-3.1-8b-instruct');
        $resp = $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('hello', $resp->content);
        Http::assertSent(fn ($r) => str_contains(
            $r->url(),
            'accounts/acc123/ai/run/@cf/meta/llama-3.1-8b-instruct'
        ));
    }

    #[Test]
    public function chat_routes_through_ai_gateway_when_a_slug_is_set(): void
    {
        Http::fake(['gateway.ai.cloudflare.com/*' => Http::response($this->okBody(), 200)]);

        $provider = new CloudflareProvider($this->creds(['gateway_slug' => 'my-gw']), '@cf/meta/llama-3.1-8b-instruct');
        $provider->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($r) => str_contains(
            $r->url(),
            'gateway.ai.cloudflare.com/v1/acc123/my-gw/workers-ai/cf/meta/llama-3.1-8b-instruct'
        ));
    }

    #[Test]
    public function it_fails_over_to_the_next_key_on_a_401(): void
    {
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->header('Authorization')[0] ?? '';
            // first token 401s, second succeeds
            return count($seen) === 1
                ? Http::response(['success' => false, 'errors' => [['message' => 'bad token']]], 401)
                : Http::response($this->okBody('recovered'), 200);
        });

        $provider = new CloudflareProvider($this->creds());
        $resp = $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('recovered', $resp->content);
        $this->assertCount(2, $seen);
        $this->assertSame('Bearer key_one', $seen[0]);
        $this->assertSame('Bearer key_two', $seen[1]);
    }

    #[Test]
    public function a_failed_key_is_benched_and_skipped_on_the_next_call(): void
    {
        Cache::flush();
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            $token = $request->header('Authorization')[0] ?? '';

            return $token === 'Bearer key_one'
                ? Http::response(['success' => false, 'errors' => [['message' => 'rate limited']]], 429)
                : Http::response($this->okBody(), 200);
        });

        $provider = new CloudflareProvider($this->creds());

        $provider->chat([['role' => 'user', 'content' => 'a']]); // key_one 429 -> key_two ok  (2 calls)
        $calls = 0;
        $provider->chat([['role' => 'user', 'content' => 'b']]); // key_one skipped -> key_two ok (1 call)

        $this->assertSame(1, $calls, 'benched key should not be retried during cooldown');
    }

    #[Test]
    public function it_throws_when_every_key_fails(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'nope']]], 500)]);

        $provider = new CloudflareProvider($this->creds());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('All cloudflare API keys failed');
        $provider->chat([['role' => 'user', 'content' => 'hi']]);
    }

    #[Test]
    public function llm_manager_builds_a_cloudflare_provider_from_workspace_config(): void
    {
        Cache::flush();
        Http::fake(['api.cloudflare.com/*' => Http::response($this->okBody('via manager'), 200)]);

        $ctx = $this->createWorkspaceContext();
        AiProviderConfig::create([
            'workspace_id' => $ctx['workspace']->id,
            'provider' => 'cloudflare',
            'credentials' => $this->creds(),
            'default_model_chat' => '@cf/meta/llama-3.1-70b-instruct',
            'enabled' => true,
        ]);

        $provider = LlmManager::forWorkspace($ctx['workspace']->id);
        $this->assertInstanceOf(CloudflareProvider::class, $provider);

        $resp = $provider->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('via manager', $resp->content);
        Http::assertSent(fn ($r) => str_contains($r->url(), '@cf/meta/llama-3.1-70b-instruct'));
    }
}
