<?php

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ecommerce\Jobs\SyncStoreCustomersJob;
use App\Modules\Ecommerce\Jobs\SyncStoreProductsJob;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;
use App\Modules\Ecommerce\Services\StoreConnectionTester;
use App\Modules\Ecommerce\Services\StoreConnector;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public const FIELDS = [
        'shopify' => [
            ['key' => 'access_token', 'label' => 'Admin API Access Token', 'type' => 'password', 'required' => true],
            ['key' => 'api_secret_key', 'label' => 'API Secret Key (optional — verifies webhook signatures)', 'type' => 'password', 'required' => false],
        ],
        'woocommerce' => [
            ['key' => 'consumer_key', 'label' => 'Consumer Key', 'type' => 'text', 'required' => true],
            ['key' => 'consumer_secret', 'label' => 'Consumer Secret', 'type' => 'password', 'required' => true],
        ],
        'bigcommerce' => [
            ['key' => 'access_token', 'label' => 'API Access Token', 'type' => 'password', 'required' => true],
        ],
    ];

    public const LABELS = [
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
        'bigcommerce' => 'BigCommerce',
    ];

    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);

        $stores = EcommerceStore::where('workspace_id', $workspaceId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (EcommerceStore $s) => [
                'id' => $s->uuid,
                'platform' => $s->platform,
                'name' => $s->name,
                'domain' => $s->domain,
                'status' => $s->status,
                'last_test_status' => $s->last_test_status,
                'last_test_message' => $s->last_test_message,
                'last_tested_at' => $s->last_tested_at,
                'customers_synced_at' => $s->customers_synced_at,
                'orders_synced_at' => $s->orders_synced_at,
                'products_synced_at' => $s->products_synced_at,
                'webhook_url' => $this->webhookUrl($s),
            ]);

        return Inertia::render('Ecommerce/Stores/Index', [
            'stores' => $stores,
            'platforms' => collect(EcommerceStore::PLATFORMS)->map(fn ($p) => [
                'platform' => $p,
                'label' => self::LABELS[$p],
                'fields' => self::FIELDS[$p],
            ])->values(),
            // Whether one-click OAuth is available per platform. Woo needs no app
            // credentials; Shopify/BigCommerce require the admin to configure them.
            'oauth' => [
                'woocommerce' => true,
                'shopify' => $this->oauthConfigured('oauth_shopify'),
                'bigcommerce' => $this->oauthConfigured('oauth_bigcommerce'),
            ],
        ]);
    }

    private function oauthConfigured(string $provider): bool
    {
        $config = IntegrationConfig::forProvider($provider);

        return $config !== null && $config->enabled && $config->isConfigured();
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);

        $validated = $request->validate([
            'platform' => ['required', 'in:shopify,woocommerce,bigcommerce'],
            'name' => ['nullable', 'string', 'max:128'],
            'domain' => ['required', 'string', 'max:255'],
            'credentials' => ['required', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:512'],
        ]);

        $result = app(StoreConnector::class)->connect(
            $workspaceId,
            $validated['platform'],
            $validated['domain'],
            $validated['credentials'],
            $validated['name'] ?? (self::LABELS[$validated['platform']].' Store'),
        );

        return $result['ok']
            ? back()->with('success', self::LABELS[$validated['platform']].' connected. '.$result['message'])
            : back()->with('error', 'Could not connect: '.$result['message']);
    }

    public function test(Request $request, EcommerceStore $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        $result = app(StoreConnectionTester::class)->test($store);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function sync(Request $request, EcommerceStore $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        SyncStoreCustomersJob::dispatch($store->id);
        SyncStoreProductsJob::dispatch($store->id);

        return back()->with('success', 'Customer & product sync started.');
    }

    public function destroy(Request $request, EcommerceStore $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);

        // Best-effort: remove the webhooks at the platform so it stops calling us.
        try {
            StoreClientFactory::for($store)->deregisterWebhooks(EcommerceStore::webhookUrlFor($store));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ecommerce.webhook.deregister_failed', [
                'store' => $store->id, 'message' => $e->getMessage(),
            ]);
        }

        $store->delete();

        return back()->with('success', 'Store disconnected.');
    }

    private function authorizeStore(Request $request, EcommerceStore $store): void
    {
        abort_unless($store->workspace_id === $this->workspaceId($request), 403);
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    private function webhookUrl(EcommerceStore $store): string
    {
        return EcommerceStore::webhookUrlFor($store);
    }
}
