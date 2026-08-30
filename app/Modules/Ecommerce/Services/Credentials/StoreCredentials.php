<?php

namespace App\Modules\Ecommerce\Services\Credentials;

/**
 * Thin typed accessor over a store's decrypted credentials array.
 */
class StoreCredentials
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private readonly array $data) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    // Shopify
    public function accessToken(): string
    {
        return (string) ($this->data['access_token'] ?? '');
    }

    // WooCommerce
    public function consumerKey(): string
    {
        return (string) ($this->data['consumer_key'] ?? '');
    }

    public function consumerSecret(): string
    {
        return (string) ($this->data['consumer_secret'] ?? '');
    }
}
