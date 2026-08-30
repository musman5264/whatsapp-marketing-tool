<?php

namespace App\Modules\Ecommerce\Services\Clients;

interface EcommerceClientInterface
{
    /**
     * @return array{ok: bool, message: string, meta?: array<string, mixed>}
     */
    public function testConnection(): array;

    /**
     * Register the store's webhooks pointing at $callbackUrl.
     *
     * @return array{ok: bool, message: string}
     */
    public function registerWebhooks(string $callbackUrl): array;

    /**
     * Remove the webhooks previously registered for $callbackUrl (best-effort, on disconnect).
     *
     * @return array{ok: bool, message: string}
     */
    public function deregisterWebhooks(string $callbackUrl): array;

    /**
     * Fetch a page of customers.
     *
     * @return array{customers: array<int, array<string, mixed>>, next: string|null}
     */
    public function fetchCustomers(?string $cursor = null): array;

    /**
     * @return array<string, mixed>|null  Raw platform order payload, or null if not found.
     */
    public function fetchOrder(string $externalId): ?array;

    /**
     * Fetch a page of recent orders (used for the initial backfill).
     *
     * @return array{orders: array<int, array<string, mixed>>, next: string|null}
     */
    public function fetchOrders(?string $cursor = null): array;

    /**
     * Fetch a page of products (used for product + inventory sync). Returns raw
     * platform payloads; mapping to the canonical product shape is done by
     * PayloadNormalizer::mapProduct().
     *
     * @return array{products: array<int, array<string, mixed>>, next: string|null}
     */
    public function fetchProducts(?string $cursor = null): array;

    /**
     * Recent orders for a customer identified by email or phone.
     *
     * @return array<int, array<string, mixed>>  Raw platform order payloads.
     */
    public function fetchRecentOrdersForContact(?string $email, ?string $phone, int $limit = 5): array;

    /**
     * Mark an order as fulfilled/shipped on the platform, optionally with tracking.
     *
     * @return array{ok: bool, message: string}
     */
    public function fulfillOrder(string $externalId, ?string $trackingNumber = null, ?string $trackingUrl = null): array;
}
