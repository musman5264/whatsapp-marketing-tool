<?php

namespace App\Modules\AI\Services\Llm;

/**
 * Single source of truth for the chat/embed models we expose per provider.
 *
 * Keep the FIRST entry of each `chat` list as the sensible default — LlmManager
 * and the UI both fall back to it. Update these lists as providers ship/retire
 * models; nothing else in the codebase hard-codes model names.
 */
class ModelCatalog
{
    /** @var array<string, array{chat: string[], embed: string[]}> */
    public const MODELS = [
        'openai' => [
            'chat' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1', 'o4-mini', 'gpt-3.5-turbo'],
            'embed' => ['text-embedding-3-small', 'text-embedding-3-large'],
        ],

        'anthropic' => [
            'chat' => [
                'claude-3-5-haiku-20241022',
                'claude-3-5-sonnet-20241022',
                'claude-3-7-sonnet-20250219',
                'claude-sonnet-4-20250514',
                'claude-opus-4-20250514',
                'claude-3-haiku-20240307',
            ],
            'embed' => [],
        ],

        'gemini' => [
            'chat' => [
                'gemini-2.0-flash',
                'gemini-2.0-flash-lite',
                'gemini-2.5-flash',
                'gemini-2.5-flash-lite',
                'gemini-2.5-pro',
                'gemini-1.5-flash-latest',
                'gemini-1.5-pro-latest',
            ],
            'embed' => ['text-embedding-004', 'gemini-embedding-001'],
        ],

        // OpenRouter — OpenAI-compatible gateway. We surface the ":free" tier only;
        // the live list is fetched from GET /api/v1/models and cached (see
        // OpenRouterProvider::freeModels()). This static list is the fallback/default.
        // https://openrouter.ai/models?max_price=0
        'openrouter' => [
            'chat' => [
                'meta-llama/llama-3.3-70b-instruct:free',
                'meta-llama/llama-3.1-8b-instruct:free',
                'google/gemini-2.0-flash-exp:free',
                'google/gemma-2-9b-it:free',
                'mistralai/mistral-7b-instruct:free',
                'mistralai/mistral-nemo:free',
                'qwen/qwen-2.5-72b-instruct:free',
                'deepseek/deepseek-chat:free',
                'deepseek/deepseek-r1:free',
                'nousresearch/hermes-3-llama-3.1-405b:free',
                'microsoft/phi-3-mini-128k-instruct:free',
            ],
            'embed' => [],
        ],

        // Cloudflare Workers AI — @cf/* model ids. Text-generation + embeddings.
        // https://developers.cloudflare.com/workers-ai/models/
        'cloudflare' => [
            'chat' => [
                '@cf/meta/llama-3.1-8b-instruct',
                '@cf/meta/llama-3.1-70b-instruct',
                '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
                '@cf/meta/llama-3-8b-instruct',
                '@cf/mistral/mistral-7b-instruct-v0.2',
                '@cf/qwen/qwen1.5-14b-chat-awq',
                '@cf/google/gemma-7b-it',
                '@cf/microsoft/phi-2',
            ],
            'embed' => [
                '@cf/baai/bge-base-en-v1.5',
                '@cf/baai/bge-small-en-v1.5',
                '@cf/baai/bge-large-en-v1.5',
            ],
        ],
    ];

    /** @return string[] */
    public static function chatModels(string $provider): array
    {
        return self::MODELS[$provider]['chat'] ?? [];
    }

    /** @return string[] */
    public static function embedModels(string $provider): array
    {
        return self::MODELS[$provider]['embed'] ?? [];
    }

    public static function defaultChatModel(string $provider): ?string
    {
        return self::MODELS[$provider]['chat'][0] ?? null;
    }

    public static function defaultEmbedModel(string $provider): ?string
    {
        return self::MODELS[$provider]['embed'][0] ?? null;
    }
}
