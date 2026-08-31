<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\Llm\GeminiProvider;
use App\Modules\AI\Services\Llm\LlmManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeminiModelMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_retired_gemini_model_is_transparently_upgraded_on_chat(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
                'usageMetadata' => ['promptTokenCount' => 2, 'candidatesTokenCount' => 1],
            ], 200),
        ]);

        $provider = new GeminiProvider('key', 'gemini-1.5-flash');
        $resp = $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('gemini-2.5-flash', $resp->model);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/models/gemini-2.5-flash:generateContent'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'gemini-1.5-flash'));
    }

    #[Test]
    public function the_default_gemini_chat_model_is_current(): void
    {
        $provider = LlmManager::build('gemini', ['api_key' => 'k']);
        $this->assertInstanceOf(GeminiProvider::class, $provider);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'x']]]]],
            ], 200),
        ]);
        $resp = $provider->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertStringStartsWith('gemini-2', $resp->model);
    }

    #[Test]
    public function the_data_migrations_repoint_stored_configs(): void
    {
        $ctx = $this->createWorkspaceContext();
        $legacy = AiProviderConfig::create([
            'workspace_id' => $ctx['workspace']->id, 'provider' => 'gemini',
            'credentials' => ['api_key' => 'k'], 'default_model_chat' => 'gemini-1.5-flash', 'enabled' => true,
        ]);
        $v20 = AiProviderConfig::create([
            'workspace_id' => $ctx['workspace']->id + 1, 'provider' => 'gemini',
            'credentials' => ['api_key' => 'k'], 'default_model_chat' => 'gemini-2.0-flash', 'enabled' => true,
        ]);

        (require base_path('app/Modules/AI/database/migrations/2026_08_31_000200_fix_deprecated_gemini_models.php'))->up();
        (require base_path('app/Modules/AI/database/migrations/2026_09_01_000100_repoint_gemini_2_0_models.php'))->up();

        $this->assertSame('gemini-2.5-flash', $legacy->fresh()->default_model_chat);
        $this->assertSame('gemini-2.5-flash', $v20->fresh()->default_model_chat);
    }
}
