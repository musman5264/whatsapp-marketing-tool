<?php

namespace App\Modules\AI\Console;

use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\Llm\CloudflareProvider;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\OpenRouterProvider;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 *   php artisan ai:status                  show every configured provider
 *   php artisan ai:status --workspace=1    also run a live 1-token chat test
 *   php artisan ai:status --gemini-models  list the Gemini models the saved key can actually use
 */
class AiStatusCommand extends Command
{
    protected $signature = 'ai:status {--workspace= : run a live chat test for this workspace id}
                                       {--gemini-models : list live Gemini models for the configured key}';

    protected $description = 'Show AI/LLM provider configuration and optionally test it';

    public function handle(): int
    {
        if ($this->option('gemini-models')) {
            return $this->listGeminiModels();
        }

        $this->line('=== Workspace AI provider configs (ai_provider_configs) ===');
        $configs = AiProviderConfig::query()->orderBy('workspace_id')->get();
        if ($configs->isEmpty()) {
            $this->line('  (none)');
        }
        foreach ($configs as $c) {
            $creds = $c->credentials ?? [];
            $hasKey = match ($c->provider) {
                'cloudflare' => ! empty(CloudflareProvider::extractKeys($creds)) && ! empty($creds['account_id']) ? 'yes' : 'NO',
                'openrouter' => ! empty(OpenRouterProvider::extractKeys($creds)) ? 'yes' : 'NO',
                default => empty($creds['api_key'] ?? '') ? 'NO' : 'yes',
            };

            $this->line(sprintf(
                '  ws%-3d  %-11s  enabled=%s  has_key=%s  chat=%s  embed=%s',
                $c->workspace_id,
                $c->provider,
                $c->enabled ? 'yes' : 'no',
                $hasKey,
                $c->default_model_chat ?: '-',
                $c->default_model_embed ?: '-',
            ));
        }

        $this->newLine();
        $this->line('=== System-level LLM defaults (Admin → Integrations) ===');
        foreach (['openai', 'anthropic', 'gemini', 'cloudflare', 'openrouter'] as $p) {
            $row = IntegrationConfig::forProvider('llm_'.$p.'_default');
            $creds = $row->credentials ?? [];
            $hasKey = match ($p) {
                'cloudflare' => ! empty(CloudflareProvider::extractKeys($creds)) && ! empty($creds['account_id']),
                'openrouter' => ! empty(OpenRouterProvider::extractKeys($creds)),
                default => ! empty($creds['api_key']),
            };
            $state = $row && $row->enabled && $hasKey
                ? 'configured + enabled'
                : ($row ? ($row->enabled ? 'enabled, NO key' : 'disabled') : 'not set');
            $this->line(sprintf('  %-11s  %s', $p, $state));
        }

        $ws = $this->option('workspace');
        if ($ws) {
            $this->newLine();
            $this->line("=== Live chat test — workspace {$ws} ===");
            try {
                $provider = LlmManager::forWorkspace((int) $ws);
                $this->line('resolved provider: '.get_class($provider));
                $resp = $provider->chat(
                    [['role' => 'user', 'content' => 'Reply with the single word: ok']],
                    ['max_tokens' => 5],
                );
                $this->info('response: "'.trim($resp->content).'"  ('.$resp->model.', '.$resp->latencyMs.'ms)');
            } catch (\Throwable $e) {
                $this->error(get_class($e).': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function listGeminiModels(): int
    {
        $key = null;
        $row = IntegrationConfig::forProvider('llm_gemini_default');
        if ($row && ! empty($row->credentials['api_key'])) {
            $key = $row->credentials['api_key'];
        }
        if (! $key) {
            $ws = $this->option('workspace');
            if ($ws) {
                $wsModel = \App\Models\Workspace::find((int) $ws);
                $creds = $wsModel ? CredentialResolver::for($wsModel)->llm('gemini') : null;
                $key = $creds?->apiKey();
            }
        }
        if (! $key) {
            $this->error('No Gemini API key found (checked system integration + --workspace).');

            return self::FAILURE;
        }

        $resp = Http::timeout(20)->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $key]);
        if (! $resp->successful()) {
            $this->error('models list failed: HTTP '.$resp->status().' '.$resp->body());

            return self::FAILURE;
        }

        $this->line('Models that support generateContent for this key:');
        foreach ($resp->json('models', []) as $m) {
            $methods = $m['supportedGenerationMethods'] ?? [];
            if (! in_array('generateContent', $methods, true)) {
                continue;
            }
            $name = str_replace('models/', '', $m['name'] ?? '?');
            $this->line(sprintf('  %-40s %s', $name, $m['displayName'] ?? ''));
        }

        return self::SUCCESS;
    }
}
