<?php

namespace App\Modules\Ecommerce\Services\Clients;

use App\Modules\Ecommerce\Services\Credentials\StoreCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WooClient implements EcommerceClientInterface
{
    /** WooCommerce REST API webhook topics. */
    public const WEBHOOK_TOPICS = ['order.created', 'order.updated', 'product.updated'];

    /** Woo caps per_page at 100. */
    private const PER_PAGE = 100;

    /** Concurrent variation lookups per batch — bounded so a large page stays inside the job timeout. */
    private const VARIATION_CONCURRENCY = 10;

    public function __construct(
        private readonly string $baseUrl,
        private readonly StoreCredentials $credentials,
        private readonly ?string $webhookSecret = null,
    ) {}

    private function apiBase(): string
    {
        return rtrim($this->baseUrl, '/').'/wp-json/wc/v3';
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->credentials->consumerKey(), $this->credentials->consumerSecret())
            ->acceptJson()
            ->timeout(15)
            ->baseUrl($this->apiBase());
    }

    /**
     * Resolve the next page cursor for a paged collection response.
     *
     * Woo advertises the page count in X-WP-TotalPages, but security plugins
     * (Wordfence, iThemes) and some reverse proxies strip X-WP-* response headers.
     * Trusting the header alone then yields totalPages=1 and silently truncates the
     * import to its first 100 rows with no error. Fall back to the page size: a full
     * page means there is probably another one.
     *
     * @param  array<int, mixed>  $items
     */
    private function nextPage(Response $resp, int $page, array $items): ?string
    {
        $totalPages = (int) ($resp->header('X-WP-TotalPages') ?: 0);

        $hasNext = $totalPages > 0
            ? $page < $totalPages
            : count($items) >= self::PER_PAGE;

        return $hasNext ? (string) ($page + 1) : null;
    }

    public function testConnection(): array
    {
        try {
            // products?per_page=1 is the cheapest authenticated read available on every Woo store.
            $resp = $this->http()->get('/products', ['per_page' => 1]);
            if ($resp->successful()) {
                return [
                    'ok' => true,
                    'message' => 'Connected to '.$this->baseUrl,
                    'meta' => array_filter(['currency' => $this->fetchCurrency()], fn ($v) => $v !== null),
                ];
            }

            return ['ok' => false, 'message' => $resp->json()['message'] ?? 'WooCommerce rejected the API keys.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Store currency, used to label product prices in the UI. Best-effort: a
     * restricted key or a locked-down store can reject /settings, which must not
     * fail an otherwise working connection.
     */
    private function fetchCurrency(): ?string
    {
        try {
            $resp = $this->http()->get('/settings/general/woocommerce_currency');

            return $resp->successful() ? ($resp->json()['value'] ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function registerWebhooks(string $callbackUrl): array
    {
        $created = 0;
        try {
            foreach (self::WEBHOOK_TOPICS as $topic) {
                $resp = $this->http()->post('/webhooks', [
                    'name' => config('app.name').' '.$topic,
                    'topic' => $topic,
                    'delivery_url' => $callbackUrl,
                    'secret' => $this->webhookSecret,
                ]);
                if ($resp->status() === 201) {
                    $created++;
                }
            }

            return ['ok' => true, 'message' => "Registered {$created} new webhook(s)."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function deregisterWebhooks(string $callbackUrl): array
    {
        try {
            $resp = $this->http()->get('/webhooks', ['per_page' => 100]);
            $deleted = 0;
            foreach ($resp->json() ?? [] as $hook) {
                if (($hook['delivery_url'] ?? '') === $callbackUrl && isset($hook['id'])) {
                    $this->http()->delete("/webhooks/{$hook['id']}", ['force' => true]);
                    $deleted++;
                }
            }

            return ['ok' => true, 'message' => "Removed {$deleted} webhook(s)."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function fetchCustomers(?string $cursor = null): array
    {
        $page = $cursor ? (int) $cursor : 1;
        $resp = $this->http()->get('/customers', ['per_page' => self::PER_PAGE, 'page' => $page]);

        if (! $resp->successful()) {
            throw new \RuntimeException("WooCommerce customers fetch failed (HTTP {$resp->status()}).");
        }

        $customers = $resp->json() ?? [];

        return ['customers' => $customers, 'next' => $this->nextPage($resp, $page, $customers)];
    }

    public function fetchOrder(string $externalId): ?array
    {
        $resp = $this->http()->get("/orders/{$externalId}");

        return $resp->successful() ? $resp->json() : null;
    }

    public function fetchOrders(?string $cursor = null): array
    {
        $page = $cursor ? (int) $cursor : 1;
        $resp = $this->http()->get('/orders', ['per_page' => self::PER_PAGE, 'page' => $page]);

        if (! $resp->successful()) {
            throw new \RuntimeException("WooCommerce orders fetch failed (HTTP {$resp->status()}).");
        }

        $orders = $resp->json() ?? [];

        return ['orders' => $orders, 'next' => $this->nextPage($resp, $page, $orders)];
    }

    public function fetchRecentOrdersForContact(?string $email, ?string $phone, int $limit = 5): array
    {
        if (! $email) {
            return [];
        }
        $resp = $this->http()->get('/orders', ['search' => $email, 'per_page' => $limit]);

        return $resp->successful() ? ($resp->json() ?? []) : [];
    }

    public function fulfillOrder(string $externalId, ?string $trackingNumber = null, ?string $trackingUrl = null): array
    {
        try {
            $payload = ['status' => 'completed'];
            if ($trackingNumber) {
                // Surfaces in order meta; shipment-tracking plugins read these keys.
                $payload['meta_data'] = [
                    ['key' => '_tracking_number', 'value' => $trackingNumber],
                    ['key' => '_tracking_url', 'value' => $trackingUrl ?? ''],
                ];
            }
            $resp = $this->http()->put("/orders/{$externalId}", $payload);

            return $resp->successful()
                ? ['ok' => true, 'message' => 'Order marked completed.']
                : ['ok' => false, 'message' => $resp->json()['message'] ?? 'WooCommerce rejected the update.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function fetchProducts(?string $cursor = null): array
    {
        $page = $cursor ? (int) $cursor : 1;
        $resp = $this->http()->get('/products', [
            'per_page' => self::PER_PAGE,
            'page' => $page,
            // Woo's REST default is status=any, which imports drafts, pending and
            // private products that aren't live on the storefront. The synced
            // catalog is what we show and share with customers — publish only.
            'status' => 'publish',
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException("WooCommerce products fetch failed (HTTP {$resp->status()}).");
        }

        $products = $resp->json() ?? [];

        return [
            'products' => $this->attachVariations($products),
            'next' => $this->nextPage($resp, $page, $products),
        ];
    }

    /**
     * Attach each variable product's variations as `_variations`.
     *
     * A variable product's parent record carries no usable stock (Woo tracks it per
     * variation, so stock_quantity is null) and reports only the lowest variation
     * price. Without the variations, every variable product syncs with blank
     * inventory. Mirrors the `_products` hydration BigCommerceClient already does.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function attachVariations(array $products): array
    {
        $ids = [];
        foreach ($products as $p) {
            if (($p['type'] ?? '') === 'variable' && ! empty($p['id'])) {
                $ids[] = (int) $p['id'];
            }
        }

        if ($ids === []) {
            return $products;
        }

        $byId = [];
        foreach (array_chunk($ids, self::VARIATION_CONCURRENCY) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (int $id) => $pool->as((string) $id)
                    ->withBasicAuth($this->credentials->consumerKey(), $this->credentials->consumerSecret())
                    ->acceptJson()
                    ->timeout(15)
                    ->get($this->apiBase()."/products/{$id}/variations", ['per_page' => self::PER_PAGE]),
                $chunk,
            ));

            foreach ($chunk as $id) {
                // A pooled entry is a ConnectionException, not a Response, when the
                // request failed. Skip it — the product still syncs, just without
                // aggregated stock, which is the pre-hydration behavior.
                $resp = $responses[(string) $id] ?? null;
                if ($resp instanceof Response && $resp->successful()) {
                    $byId[$id] = $resp->json() ?? [];
                }
            }
        }

        return array_map(function (array $p) use ($byId) {
            $id = (int) ($p['id'] ?? 0);
            if (isset($byId[$id])) {
                $p['_variations'] = $byId[$id];
            }

            return $p;
        }, $products);
    }

    /**
     * Hydrate a single raw product payload (webhook path), so a product.updated
     * delivery for a variable product reports the same aggregated stock a full
     * sync would, instead of blanking it.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    public function hydrateProduct(array $product): array
    {
        return $this->attachVariations([$product])[0] ?? $product;
    }
}
