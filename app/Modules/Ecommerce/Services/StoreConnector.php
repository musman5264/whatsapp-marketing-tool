<?php

namespace App\Modules\Ecommerce\Services;

use App\Modules\Ecommerce\Jobs\RegisterStoreWebhooksJob;
use App\Modules\Ecommerce\Jobs\SyncStoreCustomersJob;
use App\Modules\Ecommerce\Jobs\SyncStoreProductsJob;
use App\Modules\Ecommerce\Models\EcommerceStore;
use Illuminate\Support\Str;

/**
 * Single place that finalizes a store connection — used by BOTH the manual
 * credential form and the OAuth callbacks, so they share identical validation,
 * connection-testing, webhook-registration, and sync behavior.
 */
class StoreConnector
{
    public function __construct(private readonly StoreConnectionTester $tester) {}

    /**
     * @param  array<string, string>  $credentials  Platform credentials (already obtained, e.g. from OAuth).
     * @return array{ok: bool, message: string, store: ?EcommerceStore}
     */
    public function connect(int $workspaceId, string $platform, string $rawDomain, array $credentials, ?string $name = null): array
    {
        $domain = self::normalizeDomain($platform, $rawDomain);

        if ($error = StoreUrlGuard::validate($platform, $domain)) {
            return ['ok' => false, 'message' => $error, 'store' => null];
        }

        $store = EcommerceStore::firstOrNew([
            'workspace_id' => $workspaceId,
            'platform' => $platform,
            'domain' => $domain,
        ]);

        // Merge credentials, preserving existing secrets when masked/blank values are posted.
        $merged = $store->credentials ?? [];
        foreach ($credentials as $k => $v) {
            if ($v === null || $v === '' || preg_match('/^•+/', (string) $v)) {
                continue;
            }
            $merged[$k] = $v;
        }

        $store->fill([
            'name' => $name ?: ($store->name ?: ucfirst($platform).' Store'),
            'credentials' => $merged,
            'webhook_secret' => $store->webhook_secret ?: Str::random(40),
        ])->save();

        $result = $this->tester->test($store);

        if ($result['ok']) {
            if (! ($store->external_meta['webhooks_registered'] ?? false)) {
                RegisterStoreWebhooksJob::dispatch($store->id);
            }
            SyncStoreCustomersJob::dispatch($store->id);
            SyncStoreProductsJob::dispatch($store->id);
        }

        return ['ok' => $result['ok'], 'message' => $result['message'], 'store' => $store];
    }

    public static function normalizeDomain(string $platform, string $domain): string
    {
        $domain = trim($domain);

        if ($platform === 'bigcommerce') {
            // The "domain" holds the store hash. Accept a pasted API path / context and extract it.
            if (preg_match('#stores/([a-z0-9]+)#i', $domain, $m)) {
                $domain = $m[1];
            }

            return $domain;
        }

        if ($platform === 'shopify') {
            $domain = preg_replace('#^https?://#', '', $domain);

            return rtrim(explode('/', $domain)[0], '/');
        }

        // WooCommerce keeps the full store URL (scheme included) for REST calls.
        if (! preg_match('#^https?://#', $domain)) {
            $domain = 'https://'.$domain;
        }

        return rtrim($domain, '/');
    }
}
