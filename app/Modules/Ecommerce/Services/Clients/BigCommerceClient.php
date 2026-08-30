<?php

namespace App\Modules\Ecommerce\Services\Clients;

use App\Modules\Ecommerce\Services\Credentials\StoreCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * BigCommerce client. Unlike Shopify/Woo, BigCommerce webhooks are lightweight
 * references ({scope, data:{id}}) — the full order/cart is fetched on receipt via
 * hydrateWebhook(). Orders use the v2 API; customers/carts/hooks use v3.
 *
 * The store's "domain" column holds the BigCommerce store hash.
 */
class BigCommerceClient implements EcommerceClientInterface
{
    /** Webhook scopes this integration subscribes to. */
    public const WEBHOOK_SCOPES = ['store/order/created', 'store/order/statusUpdated', 'store/cart/abandoned', 'store/customer/created', 'store/product/updated', 'store/product/inventory/updated'];

    public function __construct(
        private readonly string $storeHash,
        private readonly StoreCredentials $credentials,
        private readonly ?string $webhookSecret = null,
    ) {}

    private function http(string $version = 'v3'): PendingRequest
    {
        return Http::withHeaders([
            'X-Auth-Token' => $this->credentials->get('access_token', ''),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(15)->baseUrl("https://api.bigcommerce.com/stores/{$this->storeHash}/{$version}");
    }

    public function testConnection(): array
    {
        try {
            $resp = $this->http('v2')->get('/store');
            if ($resp->successful() && isset($resp->json()['name'])) {
                $store = $resp->json();

                return [
                    'ok' => true,
                    'message' => 'Connected to '.($store['name'] ?? $this->storeHash),
                    'meta' => [
                        'store_id' => $store['id'] ?? null,
                        'currency' => $store['currency'] ?? null,
                    ],
                ];
            }

            return ['ok' => false, 'message' => $resp->json()['title'] ?? 'BigCommerce rejected the credentials.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function registerWebhooks(string $callbackUrl): array
    {
        $created = 0;
        try {
            foreach (self::WEBHOOK_SCOPES as $scope) {
                $resp = $this->http('v3')->post('/hooks', [
                    'scope' => $scope,
                    'destination' => $callbackUrl,
                    'is_active' => true,
                    'headers' => $this->webhookSecret ? ['X-Webhook-Token' => $this->webhookSecret] : (object) [],
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
            $resp = $this->http('v3')->get('/hooks');
            $deleted = 0;
            foreach ($resp->json()['data'] ?? [] as $hook) {
                if (($hook['destination'] ?? '') === $callbackUrl && isset($hook['id'])) {
                    $this->http('v3')->delete("/hooks/{$hook['id']}");
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
        $resp = $this->http('v3')->get('/customers', ['limit' => 250, 'page' => $page]);

        if (! $resp->successful()) {
            throw new \RuntimeException("BigCommerce customers fetch failed (HTTP {$resp->status()}).");
        }

        $body = $resp->json();
        $totalPages = (int) ($body['meta']['pagination']['total_pages'] ?? 1);

        return [
            'customers' => $body['data'] ?? [],
            'next' => $page < $totalPages ? (string) ($page + 1) : null,
        ];
    }

    public function fetchOrder(string $externalId): ?array
    {
        $resp = $this->http('v2')->get("/orders/{$externalId}");
        if (! $resp->successful() || empty($resp->json())) {
            return null;
        }
        $order = $resp->json();
        $order['_products'] = $this->fetchOrderProducts($externalId);

        return $order;
    }

    public function fetchOrders(?string $cursor = null): array
    {
        $page = $cursor ? (int) $cursor : 1;
        $limit = 250;
        $resp = $this->http('v2')->get('/orders', ['limit' => $limit, 'page' => $page]);

        if (! $resp->successful()) {
            throw new \RuntimeException("BigCommerce orders fetch failed (HTTP {$resp->status()}).");
        }

        // v2 returns 204 (no body) once the page range is exceeded — that's the end.
        $orders = $resp->json() ?: [];

        return [
            'orders' => $orders,
            'next' => count($orders) === $limit ? (string) ($page + 1) : null,
        ];
    }

    public function fetchRecentOrdersForContact(?string $email, ?string $phone, int $limit = 5): array
    {
        if (! $email) {
            return [];
        }
        $resp = $this->http('v2')->get('/orders', ['email' => $email, 'limit' => $limit]);

        return ($resp->successful() && ! empty($resp->json())) ? $resp->json() : [];
    }

    public function fetchProducts(?string $cursor = null): array
    {
        $page = $cursor ? (int) $cursor : 1;
        $resp = $this->http('v3')->get('/catalog/products', ['limit' => 250, 'page' => $page, 'include' => 'primary_image']);

        if (! $resp->successful()) {
            throw new \RuntimeException("BigCommerce products fetch failed (HTTP {$resp->status()}).");
        }

        $body = $resp->json();
        $totalPages = (int) ($body['meta']['pagination']['total_pages'] ?? 1);

        return [
            'products' => $body['data'] ?? [],
            'next' => $page < $totalPages ? (string) ($page + 1) : null,
        ];
    }

    public function fulfillOrder(string $externalId, ?string $trackingNumber = null, ?string $trackingUrl = null): array
    {
        try {
            // status_id 2 = "Shipped" in BigCommerce's default status set.
            $resp = $this->http('v2')->put("/orders/{$externalId}", ['status_id' => 2]);

            return $resp->successful()
                ? ['ok' => true, 'message' => 'Order marked shipped.']
                : ['ok' => false, 'message' => $resp->json()['title'] ?? 'BigCommerce rejected the update.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Hydrate a lightweight BigCommerce webhook into a canonical event + full payload.
     *
     * @param  array<string, mixed>  $data  The webhook's `data` object ({type, id}).
     * @return array{event: string, payload: array<string, mixed>}|null  Null to ignore.
     */
    public function hydrateWebhook(string $scope, array $data): ?array
    {
        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            return null;
        }

        return match ($scope) {
            'store/order/created' => $this->hydrateOrder($id, 'order.placed'),
            'store/order/statusUpdated' => $this->hydrateOrder($id, null),
            'store/cart/abandoned' => $this->hydrateCart($id),
            'store/customer/created' => $this->hydrateCustomer($id),
            'store/product/updated', 'store/product/inventory/updated' => $this->hydrateProduct($id),
            default => null,
        };
    }

    private function hydrateOrder(string $id, ?string $forcedEvent): ?array
    {
        $order = $this->fetchOrder($id);
        if (! $order) {
            return null;
        }

        // Always persist the order; a non-terminal status maps to 'order.updated'
        // (which matches no automation trigger) so the local row stays in sync
        // instead of being silently dropped.
        $event = $forcedEvent ?? ($this->eventFromStatus((string) ($order['status'] ?? '')) ?? 'order.updated');

        return ['event' => $event, 'payload' => $order];
    }

    private function hydrateCart(string $id): ?array
    {
        $resp = $this->http('v3')->get("/carts/{$id}");
        if (! $resp->successful() || empty($resp->json()['data'] ?? null)) {
            return null;
        }
        $cart = $resp->json()['data'];
        $cart['_recovery_url'] = $this->createCartRecoveryUrl($id);

        return ['event' => 'cart.abandoned', 'payload' => $cart];
    }

    private function hydrateCustomer(string $id): ?array
    {
        $resp = $this->http('v3')->get('/customers', ['id:in' => $id]);
        $customer = $resp->successful() ? ($resp->json()['data'][0] ?? null) : null;
        if (! $customer) {
            return null;
        }

        return ['event' => 'customer.created', 'payload' => $customer];
    }

    private function hydrateProduct(string $id): ?array
    {
        $resp = $this->http('v3')->get("/catalog/products/{$id}", ['include' => 'primary_image']);
        $product = $resp->successful() ? ($resp->json()['data'] ?? null) : null;
        if (! $product) {
            return null;
        }

        return ['event' => 'product.updated', 'payload' => $product];
    }

    /**
     * Map a BigCommerce order status string to a canonical lifecycle event,
     * or null for non-terminal statuses (avoids duplicate triggers on updates).
     */
    private function eventFromStatus(string $status): ?string
    {
        return match (true) {
            in_array($status, ['Shipped', 'Completed', 'Partially Shipped'], true) => 'order.fulfilled',
            in_array($status, ['Cancelled', 'Refunded', 'Declined'], true) => 'order.cancelled',
            default => null,
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function fetchOrderProducts(string $orderId): array
    {
        $resp = $this->http('v2')->get("/orders/{$orderId}/products");

        return ($resp->successful() && ! empty($resp->json())) ? $resp->json() : [];
    }

    private function createCartRecoveryUrl(string $cartId): ?string
    {
        try {
            $resp = $this->http('v3')->post("/carts/{$cartId}/redirect_urls");

            return $resp->successful() ? ($resp->json()['data']['checkout_url'] ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
