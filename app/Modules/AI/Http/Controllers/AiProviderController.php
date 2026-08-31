<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\Llm\CloudflareProvider;
use App\Modules\AI\Services\Llm\ModelCatalog;
use App\Modules\AI\Services\Llm\OpenRouterProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiProviderController extends Controller
{
    private const PROVIDERS = ['openai', 'anthropic', 'gemini', 'cloudflare', 'openrouter'];

    /** Providers that hold one-or-more keys for failover rather than a single api_key. */
    private const MULTI_KEY = ['cloudflare', 'openrouter'];

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $configs = AiProviderConfig::where('workspace_id', $workspaceId)->get()->keyBy('provider');

        $list = [];
        foreach (self::PROVIDERS as $p) {
            /** @var AiProviderConfig|null $c */
            $c = $configs->get($p);
            $creds = ($c && is_array($c->credentials)) ? $c->credentials : [];
            $multiKey = in_array($p, self::MULTI_KEY, true);

            $chatModels = $p === 'openrouter'
                ? OpenRouterProvider::freeModels(is_string($creds['api_key'] ?? null) ? $creds['api_key'] : null)
                : ModelCatalog::chatModels($p);

            $list[] = [
                'provider' => $p,
                'multi_key' => $multiKey,
                'enabled' => $c ? (bool) $c->enabled : false,
                'configured' => ! empty($creds),
                'default_model_chat' => $c ? (string) $c->default_model_chat : '',
                'default_model_embed' => $c ? (string) $c->default_model_embed : '',
                'chat_models' => $chatModels,
                'embed_models' => ModelCatalog::embedModels($p),
                'key_count' => $multiKey ? count(self::extractKeys($p, $creds)) : null,
                // Cloudflare-only non-secret extras so the form can round-trip them.
                'account_id' => $p === 'cloudflare' ? (string) ($creds['account_id'] ?? '') : null,
                'gateway_slug' => $p === 'cloudflare' ? (string) ($creds['gateway_slug'] ?? '') : null,
            ];
        }

        return Inertia::render('AI/Providers/Index', ['providers' => $list]);
    }

    /** Live free-model list for OpenRouter (used to refresh the dropdown). */
    public function openRouterModels(Request $request): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $config = AiProviderConfig::where('workspace_id', $workspaceId)->where('provider', 'openrouter')->first();
        $creds = ($config && is_array($config->credentials)) ? $config->credentials : [];
        $keys = OpenRouterProvider::extractKeys($creds);

        return response()->json(['models' => OpenRouterProvider::freeModels($keys[0] ?? null)]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512'],
            'api_keys' => ['nullable', 'string', 'max:8192'],
            'account_id' => ['nullable', 'string', 'max:128'],
            'gateway_slug' => ['nullable', 'string', 'max:128'],
            'default_model_chat' => ['nullable', 'string', 'max:128'],
            'default_model_embed' => ['nullable', 'string', 'max:128'],
            'enabled' => ['boolean'],
        ]);

        $config = AiProviderConfig::firstOrNew(['workspace_id' => $workspaceId, 'provider' => $provider]);
        $creds = $config->credentials ?? [];

        $masked = fn ($v) => is_string($v) && preg_match('/^•+/', $v);

        if (! empty($validated['api_key']) && ! $masked($validated['api_key'])) {
            $creds['api_key'] = $validated['api_key'];
        }

        if (in_array($provider, self::MULTI_KEY, true)) {
            if (! empty($validated['api_keys']) && ! $masked($validated['api_keys'])) {
                $creds['api_keys'] = $validated['api_keys'];
            }
        }

        if ($provider === 'cloudflare') {
            if (isset($validated['account_id'])) {
                $creds['account_id'] = $validated['account_id'];
            }
            $creds['gateway_slug'] = $validated['gateway_slug'] ?? '';
        }

        $config->fill([
            'credentials' => $creds,
            'default_model_chat' => $validated['default_model_chat'] ?? $config->default_model_chat,
            'default_model_embed' => $validated['default_model_embed'] ?? $config->default_model_embed,
            'enabled' => (bool) $validated['enabled'],
        ])->save();

        return back()->with('success', ucfirst($provider).' configuration saved.');
    }

    /**
     * @param  array<string, mixed>  $creds
     * @return string[]
     */
    private static function extractKeys(string $provider, array $creds): array
    {
        return $provider === 'cloudflare'
            ? CloudflareProvider::extractKeys($creds)
            : OpenRouterProvider::extractKeys($creds);
    }
}
