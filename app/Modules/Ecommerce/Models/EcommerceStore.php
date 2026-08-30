<?php

namespace App\Modules\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $platform
 * @property string|null $name
 * @property string $domain
 * @property array<string, mixed>|null $credentials
 * @property string $status
 * @property array<string, mixed>|null $external_meta
 * @property string|null $webhook_secret
 */
class EcommerceStore extends Model
{
    protected $table = 'ecommerce_stores';

    public const PLATFORMS = ['shopify', 'woocommerce', 'bigcommerce'];

    protected $fillable = [
        'uuid', 'workspace_id', 'platform', 'name', 'domain', 'credentials', 'status',
        'external_meta', 'webhook_secret', 'last_tested_at', 'last_test_status',
        'last_test_message', 'customers_synced_at', 'orders_synced_at', 'products_synced_at',
    ];

    protected $hidden = ['credentials', 'webhook_secret'];

    /** Bind routes (incl. public webhook routes) by uuid, never the sequential id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'external_meta' => 'array',
            'last_tested_at' => 'datetime',
            'customers_synced_at' => 'datetime',
            'orders_synced_at' => 'datetime',
            'products_synced_at' => 'datetime',
        ];
    }

    /**
     * The full inbound webhook URL for a store, including the per-store secret
     * token used as the primary verification gate for both platforms.
     */
    public static function webhookUrlFor(self $store): string
    {
        $name = match ($store->platform) {
            'shopify' => 'webhooks.ecommerce.shopify',
            'bigcommerce' => 'webhooks.ecommerce.bigcommerce',
            default => 'webhooks.ecommerce.woocommerce',
        };

        return route($name, ['store' => $store->getRouteKey()]).'?token='.$store->webhook_secret;
    }

    protected static function booted(): void
    {
        static::creating(function (self $store) {
            if (empty($store->uuid)) {
                $store->uuid = (string) Str::uuid();
            }
        });

        // No DB-level FK cascade, so clean up children when a store is removed,
        // otherwise orders/carts/products are orphaned and pollute dashboards.
        static::deleting(function (self $store) {
            $store->orders()->delete();
            $store->carts()->delete();
            EcommerceProduct::where('store_id', $store->id)->delete();
        });
    }

    public function orders(): HasMany
    {
        return $this->hasMany(EcommerceOrder::class, 'store_id');
    }

    public function carts(): HasMany
    {
        return $this->hasMany(EcommerceCart::class, 'store_id');
    }
}
