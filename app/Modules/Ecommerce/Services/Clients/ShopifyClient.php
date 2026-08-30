<?php

namespace App\Modules\Ecommerce\Services\Clients;

use App\Modules\Ecommerce\Services\Credentials\StoreCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyClient implements EcommerceClientInterface
{
    // Keep within Shopify's ~12-month version support window. The REST product
    // endpoints are removed in the new product model, so products are read via
    // GraphQL (see fetchProducts); orders/customers/fulfillment stay on REST.
    public const API_VERSION = '2025-10';

    /** Webhook topics this integration subscribes to. */
    public const WEBHOOK_TOPICS = ['orders/create', 'orders/fulfilled', 'orders/cancelled', 'checkouts/create', 'products/update'];

    public function __construct(
        private readonly string $domain,
        private readonly StoreCredentials $credentials,
    ) {}

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->credentials->accessToken(),
            'Accept' => 'application/json',
        ])->timeout(15)->baseUrl("https://{$this->domain}/admin/api/".self::API_VERSION);
    }

    public function testConnection(): array
    {
        try {
            $resp = $this->http()->get('/shop.json');
            if ($resp->successful() && isset($resp->json()['shop'])) {
                $shop = $resp->json()['shop'];

                return [
                    'ok' => true,
                    'message' => 'Connected to '.($shop['name'] ?? $this->domain),
                    'meta' => [
                        'shop_id' => $shop['id'] ?? null,
                        'currency' => $shop['currency'] ?? null,
                        'plan' => $shop['plan_name'] ?? null,
                    ],
                ];
            }

            return ['ok' => false, 'message' => $resp->json()['errors'] ?? 'Shopify rejected the access token.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function registerWebhooks(string $callbackUrl): array
    {
        $created = 0;
        try {
            foreach (self::WEBHOOK_TOPICS as $topic) {
                $resp = $this->http()->post('/webhooks.json', [
                    'webhook' => ['topic' => $topic, 'address' => $callbackUrl, 'format' => 'json'],
                ]);
                // 201 created; 422 means it already exists — both are acceptable.
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
            $resp = $this->http()->get('/webhooks.json', ['limit' => 250]);
            $deleted = 0;
            foreach ($resp->json()['webhooks'] ?? [] as $hook) {
                if (($hook['address'] ?? '') === $callbackUrl && isset($hook['id'])) {
                    $this->http()->delete("/webhooks/{$hook['id']}.json");
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
        $resp = $cursor
            ? $this->http()->get($cursor) // page_info URLs are absolute-safe via baseUrl merge avoidance below
            : $this->http()->get('/customers.json', ['limit' => 250]);

        // Throw on failure so the sync job retries this page instead of silently
        // truncating the import and stamping it "synced".
        if (! $resp->successful()) {
            throw new \RuntimeException("Shopify customers fetch failed (HTTP {$resp->status()}).");
        }

        return [
            'customers' => $resp->json()['customers'] ?? [],
            'next' => $this->nextPageInfo($resp->header('Link')),
        ];
    }

    public function fetchOrder(string $externalId): ?array
    {
        $resp = $this->http()->get("/orders/{$externalId}.json");

        return $resp->successful() ? ($resp->json()['order'] ?? null) : null;
    }

    public function fetchOrders(?string $cursor = null): array
    {
        $resp = $cursor
            ? $this->http()->get($cursor)
            : $this->http()->get('/orders.json', ['status' => 'any', 'limit' => 250]);

        if (! $resp->successful()) {
            throw new \RuntimeException("Shopify orders fetch failed (HTTP {$resp->status()}).");
        }

        return [
            'orders' => $resp->json()['orders'] ?? [],
            'next' => $this->nextPageInfo($resp->header('Link')),
        ];
    }

    /**
     * Fetch a page of products via the GraphQL Admin API.
     *
     * Shopify removed the REST product/variant endpoints in the new product model
     * (REST is legacy as of 2024-10), so the REST /products.json sync stopped
     * returning data. We read products through GraphQL and reshape each node into
     * the REST-style payload PayloadNormalizer::mapProduct('shopify', ...) already
     * consumes, so the products/update webhook path (still delivered as REST JSON)
     * keeps a single, unchanged mapping.
     *
     * $cursor is the GraphQL endCursor (opaque), not a Link-header URL.
     */
    public function fetchProducts(?string $cursor = null): array
    {
        // first:100 with one nested variant keeps the query cost under Shopify's
        // 1000-point single-query cap (a wide products+variants fan-out would be rejected).
        $query = <<<'GQL'
        query ($cursor: String) {
          products(first: 100, after: $cursor) {
            edges {
              node {
                id
                title
                handle
                onlineStoreUrl
                status
                totalInventory
                featuredImage { url }
                variants(first: 1) { edges { node { sku price } } }
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GQL;

        $resp = $this->http()->post('/graphql.json', [
            'query' => $query,
            'variables' => ['cursor' => $cursor],
        ]);

        // GraphQL returns HTTP 200 even for query/permission errors, so inspect the
        // body too. Throw so the sync job retries instead of stamping "synced" empty.
        $body = $resp->json();
        if (! $resp->successful() || isset($body['errors'])) {
            $detail = $body['errors'][0]['message'] ?? "HTTP {$resp->status()}";
            throw new \RuntimeException("Shopify products fetch failed ({$detail}).");
        }

        $connection = $body['data']['products'] ?? [];
        $pageInfo = $connection['pageInfo'] ?? [];

        return [
            'products' => array_map(
                fn (array $edge) => $this->reshapeProduct($edge['node'] ?? []),
                $connection['edges'] ?? [],
            ),
            'next' => ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null,
        ];
    }

    /**
     * Reshape a GraphQL product node into the REST-style array that
     * PayloadNormalizer::shopifyProduct() expects: a numeric id (matching the
     * webhook payload's id so bulk-sync and webhook upserts hit the same row),
     * a lowercase status, and a single synthetic variant carrying sku/price plus
     * the store-wide total inventory (which the normalizer sums to one value).
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function reshapeProduct(array $node): array
    {
        $variant = $node['variants']['edges'][0]['node'] ?? [];

        return [
            'id' => Str::afterLast((string) ($node['id'] ?? ''), '/'),
            'title' => $node['title'] ?? '',
            // Carry the storefront link source so a shared product can be clickable
            // (onlineStoreUrl is null when unpublished; handle + domain is the fallback).
            'handle' => $node['handle'] ?? null,
            'online_store_url' => $node['onlineStoreUrl'] ?? null,
            'status' => strtolower((string) ($node['status'] ?? '')),
            'image' => ['src' => $node['featuredImage']['url'] ?? null],
            'variants' => [[
                'sku' => $variant['sku'] ?? null,
                'price' => $variant['price'] ?? null,
                'inventory_quantity' => $node['totalInventory'] ?? null,
            ]],
        ];
    }

    public function fetchRecentOrdersForContact(?string $email, ?string $phone, int $limit = 5): array
    {
        if (! $email) {
            return [];
        }
        $resp = $this->http()->get('/orders.json', [
            'email' => $email,
            'status' => 'any',
            'limit' => $limit,
        ]);

        return $resp->successful() ? ($resp->json()['orders'] ?? []) : [];
    }

    public function fulfillOrder(string $externalId, ?string $trackingNumber = null, ?string $trackingUrl = null): array
    {
        try {
            // 2024-07 uses the Fulfillment Orders API: find open fulfillment orders, then fulfill them.
            $foResp = $this->http()->get("/orders/{$externalId}/fulfillment_orders.json");
            $fulfillmentOrders = collect($foResp->json()['fulfillment_orders'] ?? [])
                ->whereIn('status', ['open', 'in_progress'])
                ->pluck('id')
                ->all();

            if (empty($fulfillmentOrders)) {
                return ['ok' => false, 'message' => 'No open fulfillment orders found.'];
            }

            $resp = $this->http()->post('/fulfillments.json', [
                'fulfillment' => [
                    'line_items_by_fulfillment_order' => array_map(fn ($id) => ['fulfillment_order_id' => $id], $fulfillmentOrders),
                    'tracking_info' => array_filter([
                        'number' => $trackingNumber,
                        'url' => $trackingUrl,
                    ]),
                    'notify_customer' => false,
                ],
            ]);

            return $resp->successful()
                ? ['ok' => true, 'message' => 'Fulfillment created.']
                : ['ok' => false, 'message' => $resp->json()['errors'] ?? 'Shopify rejected the fulfillment.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Extract Shopify's cursor-based pagination URL from the Link header.
     */
    private function nextPageInfo(?string $linkHeader): ?string
    {
        if (! $linkHeader || ! str_contains($linkHeader, 'rel="next"')) {
            return null;
        }
        foreach (explode(',', $linkHeader) as $part) {
            if (str_contains($part, 'rel="next"') && preg_match('/<([^>]+)>/', $part, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
