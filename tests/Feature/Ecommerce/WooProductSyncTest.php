<?php

namespace Tests\Feature\Ecommerce;

use App\Modules\Ecommerce\Jobs\SyncStoreProductsJob;
use App\Modules\Ecommerce\Models\EcommerceProduct;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;
use App\Modules\Ecommerce\Services\PayloadNormalizer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WooProductSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://shop.example.com/wp-json/wc/v3';

    private function store(): EcommerceStore
    {
        return EcommerceStore::create([
            'workspace_id' => Workspace::factory()->create()->id,
            'platform' => 'woocommerce',
            'name' => 'Woo Shop',
            'domain' => 'https://shop.example.com',
            'status' => 'connected',
            'credentials' => ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test'],
            'webhook_secret' => 'secret',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function simpleProduct(int $id, array $attrs = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => "Product {$id}",
            'type' => 'simple',
            'sku' => "SKU-{$id}",
            'price' => '10.00',
            'stock_quantity' => 4,
            'status' => 'publish',
            'images' => [['src' => 'https://cdn.example.com/p.png']],
        ], $attrs);
    }

    private function sync(EcommerceStore $store): void
    {
        (new SyncStoreProductsJob($store->id))->handle(app(PayloadNormalizer::class));
    }

    public function test_it_sends_the_publish_status_filter_so_drafts_are_not_imported(): void
    {
        Http::fake([self::BASE.'/products*' => Http::response([$this->simpleProduct(1)], 200)]);

        $this->sync($this->store());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/products')
            && $request->data()['status'] === 'publish');
    }

    public function test_it_pages_past_the_first_100_when_the_total_pages_header_is_stripped(): void
    {
        // A security plugin / proxy removing X-WP-* used to pin totalPages to 1,
        // silently truncating the import to a single page.
        $pageOne = array_map(fn (int $i) => $this->simpleProduct($i), range(1, 100));

        Http::fakeSequence()
            ->push($pageOne, 200)
            ->push([$this->simpleProduct(101)], 200);

        Queue::fake();
        $store = $this->store();
        $this->sync($store);

        Queue::assertPushed(SyncStoreProductsJob::class, fn ($job) => $job->cursor === '2' && $job->seen === 100);
    }

    public function test_it_stops_paging_on_a_short_page(): void
    {
        Http::fake([self::BASE.'/products*' => Http::response([$this->simpleProduct(1)], 200)]);

        Queue::fake();
        $store = $this->store();
        $this->sync($store);

        Queue::assertNotPushed(SyncStoreProductsJob::class);
        $this->assertNotNull($store->fresh()->products_synced_at);
    }

    public function test_it_honours_the_total_pages_header_when_present(): void
    {
        Http::fake([
            self::BASE.'/products*' => Http::response(
                array_map(fn (int $i) => $this->simpleProduct($i), range(1, 100)),
                200,
                ['X-WP-TotalPages' => '1'],
            ),
        ]);

        Queue::fake();
        $this->sync($this->store());

        // Full page, but the store says there is only one — no wasted second request.
        Queue::assertNotPushed(SyncStoreProductsJob::class);
    }

    public function test_it_aggregates_variation_stock_for_a_variable_product(): void
    {
        Http::fake([
            self::BASE.'/products/55/variations*' => Http::response([
                ['id' => 901, 'stock_quantity' => 3],
                ['id' => 902, 'stock_quantity' => 5],
                ['id' => 903, 'stock_quantity' => null],
            ], 200),
            self::BASE.'/products*' => Http::response([
                $this->simpleProduct(55, [
                    'type' => 'variable',
                    'sku' => '',
                    'price' => '10.00',   // Woo reports the lowest variation price
                    'stock_quantity' => null, // parent never carries variable stock
                ]),
            ], 200),
        ]);

        $store = $this->store();
        $this->sync($store);

        $product = EcommerceProduct::where('external_id', '55')->firstOrFail();
        $this->assertSame(8, $product->inventory_quantity);
        $this->assertNull($product->sku);
    }

    public function test_a_parent_that_manages_its_own_stock_wins_over_variations(): void
    {
        Http::fake([
            self::BASE.'/products/56/variations*' => Http::response([['id' => 904, 'stock_quantity' => 99]], 200),
            self::BASE.'/products*' => Http::response([
                $this->simpleProduct(56, ['type' => 'variable', 'stock_quantity' => 12]),
            ], 200),
        ]);

        $this->sync($this->store());

        $this->assertSame(12, EcommerceProduct::where('external_id', '56')->firstOrFail()->inventory_quantity);
    }

    public function test_untracked_stock_stays_null_rather_than_zero(): void
    {
        // Stock management off: null means "unknown", and 0 would wrongly report
        // the product as out of stock in the dashboard counters.
        Http::fake([
            self::BASE.'/products*' => Http::response([
                $this->simpleProduct(57, ['stock_quantity' => null]),
            ], 200),
        ]);

        $this->sync($this->store());

        $this->assertNull(EcommerceProduct::where('external_id', '57')->firstOrFail()->inventory_quantity);
    }

    public function test_a_failed_variation_lookup_still_syncs_the_product(): void
    {
        Http::fake([
            self::BASE.'/products/58/variations*' => Http::response([], 500),
            self::BASE.'/products*' => Http::response([
                $this->simpleProduct(58, ['type' => 'variable', 'stock_quantity' => null]),
            ], 200),
        ]);

        $this->sync($this->store());

        $product = EcommerceProduct::where('external_id', '58')->firstOrFail();
        $this->assertNull($product->inventory_quantity);
        $this->assertSame('Product 58', $product->name);
    }

    public function test_it_prunes_products_the_store_no_longer_returns(): void
    {
        $store = $this->store();
        EcommerceProduct::create([
            'workspace_id' => $store->workspace_id,
            'store_id' => $store->id,
            'external_id' => '999',
            'platform' => 'woocommerce',
            'name' => 'Unpublished leftover',
            'price' => 1,
            'last_seen_at' => now()->subDay(),
        ]);

        Http::fake([self::BASE.'/products*' => Http::response([$this->simpleProduct(1)], 200)]);

        $this->sync($store);

        $this->assertNull(EcommerceProduct::where('external_id', '999')->first());
        $this->assertNotNull(EcommerceProduct::where('external_id', '1')->first());
    }

    public function test_an_empty_response_does_not_wipe_the_catalog(): void
    {
        $store = $this->store();
        EcommerceProduct::create([
            'workspace_id' => $store->workspace_id,
            'store_id' => $store->id,
            'external_id' => '999',
            'platform' => 'woocommerce',
            'name' => 'Still real',
            'price' => 1,
            'last_seen_at' => now()->subDay(),
        ]);

        Http::fake([self::BASE.'/products*' => Http::response([], 200)]);

        $this->sync($store);

        $this->assertNotNull(EcommerceProduct::where('external_id', '999')->first());
    }

    public function test_a_failed_page_throws_instead_of_stamping_the_store_synced(): void
    {
        Http::fake([self::BASE.'/products*' => Http::response(['message' => 'nope'], 401)]);

        $store = $this->store();

        $this->expectException(\RuntimeException::class);
        try {
            $this->sync($store);
        } finally {
            $this->assertNull($store->fresh()->products_synced_at);
        }
    }

    public function test_test_connection_reports_the_store_currency(): void
    {
        Http::fake([
            self::BASE.'/settings/general/woocommerce_currency' => Http::response(['value' => 'EUR'], 200),
            self::BASE.'/products*' => Http::response([$this->simpleProduct(1)], 200),
        ]);

        $result = StoreClientFactory::for($this->store())->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertSame('EUR', $result['meta']['currency']);
    }

    public function test_a_currency_lookup_rejection_does_not_fail_the_connection(): void
    {
        Http::fake([
            self::BASE.'/settings/general/woocommerce_currency' => Http::response([], 401),
            self::BASE.'/products*' => Http::response([$this->simpleProduct(1)], 200),
        ]);

        $result = StoreClientFactory::for($this->store())->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('currency', $result['meta']);
    }
}
