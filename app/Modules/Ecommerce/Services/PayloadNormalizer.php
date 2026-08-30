<?php

namespace App\Modules\Ecommerce\Services;

/**
 * Normalizes raw Shopify / WooCommerce webhook payloads into one canonical
 * commerce-event shape consumed by ProcessEcommerceWebhookJob.
 *
 * Canonical shape:
 *   [
 *     'event_type' => 'order.placed'|'order.fulfilled'|'order.cancelled'|'cart.abandoned'|'customer.created'|null,
 *     'contact'    => ['phone_e164'=>?, 'email'=>?, 'first_name'=>?, 'last_name'=>?],
 *     'order'      => array|null,   // ready for EcommerceOrder upsert (sans workspace/store/contact ids)
 *     'cart'       => array|null,   // ready for EcommerceCart upsert
 *     'context'    => [flat string keys for automation {{context.x}} tokens],
 *   ]
 */
class PayloadNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null  Null when the topic should be ignored.
     */
    public function normalize(string $platform, string $topic, array $payload, string $storeName = ''): ?array
    {
        return match ($platform) {
            'shopify' => $this->shopify($topic, $payload, $storeName),
            'woocommerce' => $this->woocommerce($topic, $payload, $storeName),
            'bigcommerce' => $this->bigcommerce($topic, $payload, $storeName),
            default => null,
        };
    }

    /**
     * BigCommerce payloads are already hydrated (full order/cart/customer) and the
     * $topic is the resolved canonical event, decided in BigCommerceClient.
     */
    private function bigcommerce(string $event, array $p, string $storeName): ?array
    {
        return match ($event) {
            'order.placed', 'order.fulfilled', 'order.cancelled', 'order.updated' => $this->bigcommerceOrder($event, $p, $storeName),
            'cart.abandoned' => $this->bigcommerceCart($p, $storeName),
            'customer.created' => $this->bigcommerceCustomer($p, $storeName),
            'product.updated' => $this->productEvent('bigcommerce', $p),
            default => null,
        };
    }

    private function bigcommerceOrder(string $event, array $p, string $storeName): array
    {
        $bill = $p['billing_address'] ?? [];
        $status = (string) ($p['status'] ?? '');

        $order = [
            'external_order_id' => (string) ($p['id'] ?? ''),
            'platform' => 'bigcommerce',
            'number' => '#'.($p['id'] ?? ''),
            'status' => $status,
            'financial_status' => $p['payment_status'] ?? null,
            'fulfillment_status' => in_array($status, ['Shipped', 'Completed', 'Partially Shipped'], true) ? 'fulfilled' : null,
            'currency' => $p['currency_code'] ?? null,
            'total' => (float) ($p['total_inc_tax'] ?? 0),
            'line_items' => array_map(fn ($i) => [
                'title' => $i['name'] ?? '',
                'quantity' => (int) ($i['quantity'] ?? 1),
                'price' => (string) ($i['price_inc_tax'] ?? $i['base_price'] ?? '0'),
            ], $p['_products'] ?? []),
            'tracking_url' => null,
            'tracking_number' => null,
            'placed_at' => $this->date($p['date_created'] ?? null),
            'raw' => $p,
        ];

        $contact = [
            'phone_e164' => $this->phone($bill['phone'] ?? null),
            'email' => $bill['email'] ?? null,
            'first_name' => $bill['first_name'] ?? null,
            'last_name' => $bill['last_name'] ?? null,
        ];

        return [
            'event_type' => $event,
            'contact' => $contact,
            'order' => $order,
            'cart' => null,
            'context' => $this->orderContext($order, $storeName),
        ];
    }

    private function bigcommerceCart(array $p, string $storeName): array
    {
        $items = $p['line_items']['physical_items'] ?? [];
        $cart = [
            'external_id' => (string) ($p['id'] ?? ''),
            'total' => (float) ($p['cart_amount'] ?? $p['base_amount'] ?? 0),
            'currency' => $p['currency']['code'] ?? null,
            'line_items' => array_map(fn ($i) => [
                'title' => $i['name'] ?? '',
                'quantity' => (int) ($i['quantity'] ?? 1),
                'price' => (string) ($i['list_price'] ?? $i['sale_price'] ?? '0'),
            ], $items),
            'recovery_url' => $p['_recovery_url'] ?? null,
            'abandoned_at' => $this->date($p['updated_time'] ?? null),
            // BigCommerce fires this only once the cart is already abandoned.
            'recovery_delay_minutes' => 1,
        ];

        $contact = [
            'phone_e164' => null,
            'email' => $p['email'] ?? null,
            'first_name' => null,
            'last_name' => null,
        ];

        return [
            'event_type' => 'cart.abandoned',
            'contact' => $contact,
            'order' => null,
            'cart' => $cart,
            'context' => [
                'cart_total' => (string) $cart['total'],
                'order_currency' => (string) $cart['currency'],
                'recovery_url' => (string) $cart['recovery_url'],
                'store_name' => $storeName,
            ],
        ];
    }

    private function bigcommerceCustomer(array $p, string $storeName): array
    {
        return [
            'event_type' => 'customer.created',
            'contact' => [
                'external_id' => (string) ($p['id'] ?? ''),
                'phone_e164' => $this->phone($p['phone'] ?? null),
                'email' => $p['email'] ?? null,
                'first_name' => $p['first_name'] ?? null,
                'last_name' => $p['last_name'] ?? null,
            ],
            'order' => null,
            'cart' => null,
            'context' => ['store_name' => $storeName],
        ];
    }

    private function shopify(string $topic, array $p, string $storeName): ?array
    {
        return match ($topic) {
            'orders/create' => $this->shopifyOrder('order.placed', $p, $storeName),
            'orders/fulfilled' => $this->shopifyOrder('order.fulfilled', $p, $storeName),
            'orders/cancelled' => $this->shopifyOrder('order.cancelled', $p, $storeName),
            'checkouts/create' => $this->shopifyCheckout($p, $storeName),
            'products/update' => $this->productEvent('shopify', $p),
            default => null,
        };
    }

    /**
     * Canonical product.updated event (inventory sync). Carries a 'product' payload
     * and no contact/order/cart — does not drive automations.
     */
    private function productEvent(string $platform, array $p): array
    {
        return [
            'event_type' => 'product.updated',
            'contact' => ['phone_e164' => null, 'email' => null, 'first_name' => null, 'last_name' => null],
            'order' => null,
            'cart' => null,
            'product' => $this->mapProduct($platform, $p),
            'context' => [],
        ];
    }

    /**
     * Map a raw platform product into the canonical EcommerceProduct shape.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public function mapProduct(string $platform, array $p): array
    {
        return match ($platform) {
            'shopify' => $this->shopifyProduct($p),
            'woocommerce' => $this->wooProduct($p),
            'bigcommerce' => $this->bigcommerceProduct($p),
            default => [],
        };
    }

    private function shopifyProduct(array $p): array
    {
        $variants = $p['variants'] ?? [];
        $first = $variants[0] ?? [];
        $inventory = null;
        foreach ($variants as $v) {
            if (isset($v['inventory_quantity'])) {
                $inventory = ($inventory ?? 0) + (int) $v['inventory_quantity'];
            }
        }

        return [
            'external_id' => (string) ($p['id'] ?? ''),
            'platform' => 'shopify',
            'name' => $p['title'] ?? '',
            'sku' => $first['sku'] ?? null,
            'price' => (float) ($first['price'] ?? 0),
            'inventory_quantity' => $inventory,
            'status' => $p['status'] ?? null,
            'image_url' => $p['image']['src'] ?? ($p['images'][0]['src'] ?? null),
            'raw' => $p,
        ];
    }

    private function wooProduct(array $p): array
    {
        // WooClient hydrates variable products with their variations; keep them out
        // of `raw` so a 100-variation product doesn't bloat the stored payload.
        $variations = $p['_variations'] ?? [];
        $raw = $p;
        unset($raw['_variations']);

        return [
            'external_id' => (string) ($p['id'] ?? ''),
            'platform' => 'woocommerce',
            'name' => $p['name'] ?? '',
            'sku' => ($p['sku'] ?? '') ?: null,
            'price' => (float) ($p['price'] ?? 0),
            'inventory_quantity' => $this->wooInventory($p, $variations),
            'status' => $p['status'] ?? null,
            'image_url' => $p['images'][0]['src'] ?? null,
            'raw' => $raw,
        ];
    }

    /**
     * Woo reports stock on whichever level manages it: the parent when manage_stock
     * is on, otherwise each variation — so a variable product's parent has
     * stock_quantity null even when its variations are stocked.
     *
     * Null means "not tracked" and must stay null: 0 would read as out of stock and
     * pull the product into the low/out-of-stock counters.
     *
     * @param  array<string, mixed>  $p
     * @param  array<int, array<string, mixed>>  $variations
     */
    private function wooInventory(array $p, array $variations): ?int
    {
        if (isset($p['stock_quantity'])) {
            return (int) $p['stock_quantity'];
        }

        $total = null;
        foreach ($variations as $v) {
            if (isset($v['stock_quantity'])) {
                $total = ($total ?? 0) + (int) $v['stock_quantity'];
            }
        }

        return $total;
    }

    private function bigcommerceProduct(array $p): array
    {
        return [
            'external_id' => (string) ($p['id'] ?? ''),
            'platform' => 'bigcommerce',
            'name' => $p['name'] ?? '',
            'sku' => $p['sku'] ?: null,
            'price' => (float) ($p['price'] ?? 0),
            'inventory_quantity' => isset($p['inventory_level']) ? (int) $p['inventory_level'] : null,
            'status' => ($p['is_visible'] ?? true) ? 'visible' : 'hidden',
            'image_url' => $p['primary_image']['url_standard'] ?? null,
            'raw' => $p,
        ];
    }

    private function shopifyOrder(string $event, array $p, string $storeName): array
    {
        $customer = $p['customer'] ?? [];
        $ship = $p['shipping_address'] ?? [];
        $bill = $p['billing_address'] ?? [];
        $fulfillment = $p['fulfillments'][0] ?? [];

        $order = [
            'external_order_id' => (string) ($p['id'] ?? ''),
            'platform' => 'shopify',
            'number' => $p['name'] ?? ('#'.($p['order_number'] ?? '')),
            'status' => ($p['cancelled_at'] ?? null) ? 'cancelled' : 'open',
            'financial_status' => $p['financial_status'] ?? null,
            'fulfillment_status' => $p['fulfillment_status'] ?? null,
            'currency' => $p['currency'] ?? null,
            'total' => (float) ($p['total_price'] ?? 0),
            'line_items' => $this->shopifyLineItems($p['line_items'] ?? []),
            'tracking_url' => $fulfillment['tracking_url'] ?? ($fulfillment['tracking_urls'][0] ?? null),
            'tracking_number' => $fulfillment['tracking_number'] ?? null,
            'placed_at' => $this->date($p['created_at'] ?? null),
            'raw' => $p,
        ];

        $contact = [
            'phone_e164' => $this->phone($p['phone'] ?? $customer['phone'] ?? $ship['phone'] ?? $bill['phone'] ?? null),
            'email' => $p['email'] ?? $customer['email'] ?? null,
            'first_name' => $customer['first_name'] ?? $ship['first_name'] ?? null,
            'last_name' => $customer['last_name'] ?? $ship['last_name'] ?? null,
        ];

        return [
            'event_type' => $event,
            'contact' => $contact,
            'order' => $order,
            'cart' => null,
            'context' => $this->orderContext($order, $storeName),
        ];
    }

    private function shopifyCheckout(array $p, string $storeName): array
    {
        $customer = $p['customer'] ?? [];
        $cart = [
            'external_id' => (string) ($p['id'] ?? $p['token'] ?? ''),
            'total' => (float) ($p['total_price'] ?? 0),
            'currency' => $p['currency'] ?? null,
            'line_items' => $this->shopifyLineItems($p['line_items'] ?? []),
            'recovery_url' => $p['abandoned_checkout_url'] ?? null,
            'abandoned_at' => $this->date($p['created_at'] ?? null),
        ];

        $contact = [
            'phone_e164' => $this->phone($p['phone'] ?? $customer['phone'] ?? null),
            'email' => $p['email'] ?? $customer['email'] ?? null,
            'first_name' => $customer['first_name'] ?? null,
            'last_name' => $customer['last_name'] ?? null,
        ];

        return [
            'event_type' => 'cart.abandoned', // deferred — scheduled by the job, not fired immediately
            'contact' => $contact,
            'order' => null,
            'cart' => $cart,
            'context' => [
                'cart_total' => (string) $cart['total'],
                'order_currency' => (string) $cart['currency'],
                'recovery_url' => (string) $cart['recovery_url'],
                'store_name' => $storeName,
            ],
        ];
    }

    private function woocommerce(string $topic, array $p, string $storeName): ?array
    {
        // Woo sends order.created / order.updated; map updates onto fulfilled/cancelled by status.
        if ($topic === 'product.updated') {
            return $this->productEvent('woocommerce', $p);
        }

        $status = $p['status'] ?? '';
        $event = match (true) {
            $topic === 'order.created' => 'order.placed',
            $topic === 'order.updated' && in_array($status, ['completed'], true) => 'order.fulfilled',
            $topic === 'order.updated' && in_array($status, ['cancelled', 'refunded'], true) => 'order.cancelled',
            default => null, // ignore other updates to avoid duplicate triggers
        };

        if ($event === null) {
            return null;
        }

        $billing = $p['billing'] ?? [];
        $shipping = $p['shipping_lines'][0] ?? [];

        $order = [
            'external_order_id' => (string) ($p['id'] ?? ''),
            'platform' => 'woocommerce',
            'number' => $p['number'] ?? (string) ($p['id'] ?? ''),
            'status' => $status,
            'financial_status' => $p['status'] ?? null,
            'fulfillment_status' => $status === 'completed' ? 'fulfilled' : null,
            'currency' => $p['currency'] ?? null,
            'total' => (float) ($p['total'] ?? 0),
            'line_items' => $this->wooLineItems($p['line_items'] ?? []),
            'tracking_url' => null,
            'tracking_number' => null,
            'placed_at' => $this->date($p['date_created'] ?? $p['date_created_gmt'] ?? null),
            'raw' => $p,
        ];

        $contact = [
            'phone_e164' => $this->phone($billing['phone'] ?? null),
            'email' => $billing['email'] ?? null,
            'first_name' => $billing['first_name'] ?? null,
            'last_name' => $billing['last_name'] ?? null,
        ];

        return [
            'event_type' => $event,
            'contact' => $contact,
            'order' => $order,
            'cart' => null,
            'context' => $this->orderContext($order, $storeName),
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{title: string, quantity: int, price: string}>
     */
    private function shopifyLineItems(array $items): array
    {
        return array_map(fn ($i) => [
            'title' => $i['title'] ?? ($i['name'] ?? ''),
            'quantity' => (int) ($i['quantity'] ?? 1),
            'price' => (string) ($i['price'] ?? '0'),
        ], $items);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{title: string, quantity: int, price: string}>
     */
    private function wooLineItems(array $items): array
    {
        return array_map(fn ($i) => [
            'title' => $i['name'] ?? '',
            'quantity' => (int) ($i['quantity'] ?? 1),
            'price' => (string) ($i['price'] ?? '0'),
        ], $items);
    }

    /**
     * Flat token map for automation message rendering.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, string>
     */
    private function orderContext(array $order, string $storeName): array
    {
        return [
            'order_number' => (string) ($order['number'] ?? ''),
            'order_total' => (string) ($order['total'] ?? ''),
            'order_currency' => (string) ($order['currency'] ?? ''),
            'order_status' => (string) ($order['status'] ?? ''),
            'tracking_url' => (string) ($order['tracking_url'] ?? ''),
            'tracking_number' => (string) ($order['tracking_number'] ?? ''),
            'store_name' => $storeName,
        ];
    }

    /**
     * Parse a platform date string into a storable datetime, or null.
     * Critical: Carbon::parse('') returns now(), which would silently corrupt
     * placed_at / abandoned_at (and the abandoned-cart conversion check). Empty or
     * unparseable input must become null instead.
     */
    private function date(?string $raw): ?string
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($raw)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Best-effort E.164 cleanup: keep a leading +, strip everything else non-digit.
     * Returns null for empty/unusable values so dedup falls back to email.
     */
    private function phone(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        $raw = trim($raw);
        $plus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }

        return ($plus ? '+' : '').$digits;
    }
}
