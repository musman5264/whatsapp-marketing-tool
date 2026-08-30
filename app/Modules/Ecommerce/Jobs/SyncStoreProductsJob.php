<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Modules\Ecommerce\Models\EcommerceProduct;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;
use App\Modules\Ecommerce\Services\PayloadNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Imports/refreshes store products + inventory levels, one page per invocation,
 * chaining the next page via re-dispatch. On the final page, prunes products the
 * store no longer returns and sets products_synced_at.
 */
class SyncStoreProductsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Generous: a page of variable products fans out into batched variation lookups. */
    public int $timeout = 300;

    public array $backoff = [30, 120, 300];

    /**
     * @param  string|null  $runStartedAt  Set when chaining; marks rows touched by this run.
     * @param  int  $seen  Products upserted by earlier pages of this run.
     */
    public function __construct(
        public readonly int $storeId,
        public readonly ?string $cursor = null,
        public readonly ?string $runStartedAt = null,
        public readonly int $seen = 0,
    ) {}

    public function failed(\Throwable $e): void
    {
        Log::error('ecommerce.sync.products.failed', [
            'store' => $this->storeId,
            'cursor' => $this->cursor,
            'error' => $e->getMessage(),
        ]);
    }

    public function handle(PayloadNormalizer $normalizer): void
    {
        $store = EcommerceStore::find($this->storeId);
        if (! $store) {
            return;
        }

        $runStartedAt = $this->runStartedAt ?? now()->toDateTimeString();
        $page = StoreClientFactory::for($store)->fetchProducts($this->cursor);
        $seen = $this->seen;

        foreach ($page['products'] as $raw) {
            $product = $normalizer->mapProduct($store->platform, $raw);
            if (($product['external_id'] ?? '') === '') {
                continue;
            }

            EcommerceProduct::updateOrCreate(
                ['store_id' => $store->id, 'external_id' => $product['external_id']],
                array_merge($product, [
                    'workspace_id' => $store->workspace_id,
                    'last_seen_at' => now(),
                ]),
            );
            $seen++;
        }

        if ($page['next'] !== null && $page['next'] !== $this->cursor) {
            self::dispatch($store->id, $page['next'], $runStartedAt, $seen);

            return;
        }

        $this->prune($store, $runStartedAt, $seen);
        $store->update(['products_synced_at' => now()]);

        Log::info('ecommerce.sync.products.completed', [
            'store' => $store->id,
            'platform' => $store->platform,
            'products' => $seen,
        ]);
    }

    /**
     * Drop rows the store stopped returning — products deleted or unpublished since
     * the previous run. Skipped when the run imported nothing, so an empty-but-200
     * response (revoked key, WAF interference) can't wipe a healthy catalog.
     */
    private function prune(EcommerceStore $store, string $runStartedAt, int $seen): void
    {
        if ($seen === 0) {
            return;
        }

        $removed = EcommerceProduct::where('store_id', $store->id)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $runStartedAt))
            ->delete();

        if ($removed > 0) {
            Log::info('ecommerce.sync.products.pruned', ['store' => $store->id, 'removed' => $removed]);
        }
    }
}
