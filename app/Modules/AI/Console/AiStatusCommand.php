<?php

namespace App\Modules\AI\Console;

use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\Llm\CloudflareProvider;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\OpenRouterProvider;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Console\Command;

/**
 *   php artisan ai:status                  show every configured provider
 *   php artisan ai:status --workspace=1    also run a live 1-token chat test
 */
class AiStatusCommand extends Command
{
    protected $signature = 'ai:status {--workspace= : run a live chat test for this workspace id}';

    protected $description = 'Show AI/LLM provider configuration and optionally test it';

    public function handle(): int
    {
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
}
