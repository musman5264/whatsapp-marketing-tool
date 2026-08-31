<?php

namespace App\Modules\AI\Services\Llm;

use App\Models\Workspace;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\Integrations\Services\CredentialResolver;

class LlmManager
{
    /** Every provider we can build. Also the failover order for chat. */
    public const PROVIDERS = ['openai', 'anthropic', 'gemini', 'cloudflare', 'openrouter'];

    /** Providers that support embeddings natively. */
    private const EMBED_CAPABLE = ['openai', 'gemini', 'cloudflare'];

    /** Resolve a provider for chat completions (all providers supported). */
    public static function forWorkspace(int $workspaceId): LlmProviderInterface
    {
        $config = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->orderByRaw("FIELD(provider, 'openai', 'anthropic', 'gemini', 'cloudflare', 'openrouter')")
            ->first();

        if ($config) {
            $provider = $config->provider;
            $creds = $config->credentials ?? [];
            if (self::hasCredentials($provider, $creds)) {
                return self::build($provider, $creds, [
                    'chat' => $config->default_model_chat,
                    'embed' => $config->default_model_embed,
                ]);
            }
        }

        $workspace = app(Workspace::class)->find($workspaceId);
        foreach (self::PROVIDERS as $provider) {
            $creds = CredentialResolver::for($workspace)->llm($provider);
            if ($creds) {
                return static::build($provider, $creds->toArray());
            }
        }

        throw new \RuntimeException('No AI provider configured for workspace '.$workspaceId);
    }

    /**
     * Resolve a provider for embeddings only.
     * Anthropic does not support embeddings — it is skipped automatically.
     * Falls back across OpenAI → Gemini → Cloudflare in workspace config, then system defaults.
     */
    public static function forWorkspaceEmbed(int $workspaceId): LlmProviderInterface
    {
        // Workspace-level: prefer embed-capable providers, then fall back to any enabled one
        $configs = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->orderByRaw("FIELD(provider, 'openai', 'gemini', 'cloudflare', 'anthropic')")
            ->get();

        foreach ($configs as $config) {
            $provider = $config->provider;
            if (! in_array($provider, self::EMBED_CAPABLE, true)) {
                continue;
            }
            $creds = $config->credentials ?? [];
            if (! self::hasCredentials($provider, $creds)) {
                continue;
            }
            return self::build($provider, $creds, [
                'chat' => $config->default_model_chat,
                'embed' => $config->default_model_embed,
            ]);
        }

        // System-level fallback (embed-capable only)
        $workspace = app(Workspace::class)->find($workspaceId);
        foreach (self::EMBED_CAPABLE as $provider) {
            $creds = CredentialResolver::for($workspace)->llm($provider);
            if ($creds) {
                return static::build($provider, $creds->toArray());
            }
        }

        throw new \RuntimeException(
            'No embedding-capable AI provider (OpenAI, Gemini or Cloudflare) configured for workspace '.$workspaceId.
            '. Anthropic does not support embeddings.'
        );
    }

    public static function build(string $provider, array $creds, array $models = []): LlmProviderInterface
    {
        $chat = $models['chat'] ?? null;
        $embed = $models['embed'] ?? null;

        return match ($provider) {
            'openai' => new OpenAiProvider(
                $creds['api_key'] ?? '',
                $chat ?: ModelCatalog::defaultChatModel('openai'),
                $embed ?: ModelCatalog::defaultEmbedModel('openai'),
                $creds['organization_id'] ?? null,
            ),
            'anthropic' => new AnthropicProvider(
                $creds['api_key'] ?? '',
                $chat ?: ModelCatalog::defaultChatModel('anthropic'),
            ),
            'gemini' => new GeminiProvider(
                $creds['api_key'] ?? '',
                $chat ?: ModelCatalog::defaultChatModel('gemini'),
                $embed ?: ModelCatalog::defaultEmbedModel('gemini'),
            ),
            'cloudflare' => new CloudflareProvider(
                $creds,
                $chat ?: ModelCatalog::defaultChatModel('cloudflare'),
                $embed ?: ModelCatalog::defaultEmbedModel('cloudflare'),
            ),
            'openrouter' => new OpenRouterProvider(
                $creds,
                $chat ?: ModelCatalog::defaultChatModel('openrouter'),
            ),
            default => throw new \RuntimeException("Unknown LLM provider: {$provider}"),
        };
    }

    /**
     * A row is usable if it has a normal api_key, OR (for Cloudflare) an
     * account_id plus at least one key in api_key / api_keys.
     *
     * @param  array<string, mixed>  $creds
     */
    private static function hasCredentials(string $provider, array $creds): bool
    {
        if ($provider === 'cloudflare') {
            return ! empty($creds['account_id'])
                && ! empty(CloudflareProvider::extractKeys($creds));
        }

        if ($provider === 'openrouter') {
            return ! empty(OpenRouterProvider::extractKeys($creds));
        }

        return ! empty($creds['api_key'] ?? '');
    }
}
