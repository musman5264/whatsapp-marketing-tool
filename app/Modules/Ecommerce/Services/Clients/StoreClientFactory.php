<?php

namespace App\Modules\Ecommerce\Services\Clients;

use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Credentials\StoreCredentials;
use InvalidArgumentException;

class StoreClientFactory
{
    public static function for(EcommerceStore $store): EcommerceClientInterface
    {
        $credentials = new StoreCredentials($store->credentials ?? []);

        return match ($store->platform) {
            'shopify' => new ShopifyClient($store->domain, $credentials),
            'woocommerce' => new WooClient($store->domain, $credentials, $store->webhook_secret),
            'bigcommerce' => new BigCommerceClient($store->domain, $credentials, $store->webhook_secret),
            default => throw new InvalidArgumentException("Unsupported platform: {$store->platform}"),
        };
    }
}
