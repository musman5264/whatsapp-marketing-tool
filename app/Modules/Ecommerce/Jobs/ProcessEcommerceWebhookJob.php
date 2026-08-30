<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Events\CommerceEventReceived;
use App\Modules\Ecommerce\Models\EcommerceCart;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Ecommerce\Models\EcommerceProduct;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\BigCommerceClient;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;
use App\Modules\Ecommerce\Services\Clients\WooClient;
use App\Modules\Ecommerce\Services\ContactEnricher;
use App\Modules\Ecommerce\Services\PayloadNormalizer;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Services\ContactService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessEcommerceWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** Minutes to wait before treating an unconverted checkout as abandoned. */
    public const ABANDONED_AFTER_MINUTES = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $storeId,
        public readonly string $topic,
        public readonly array $payload,
    ) {}

    public function failed(\Throwable $e): void
    {
        Log::error('ecommerce.webhook.process_failed', [
            'store' => $this->storeId,
            'topic' => $this->topic,
            'error' => $e->getMessage(),
        ]);
    }

    public function handle(PayloadNormalizer $normalizer, ContactService $contacts, ContactEnricher $enricher): void
    {
        $store = EcommerceStore::find($this->storeId);
        if (! $store) {
            return;
        }

        $topic = $this->topic;
        $payload = $this->payload;

        // BigCommerce webhooks are lightweight references — fetch the full
        // resource and resolve the canonical event before normalizing.
        if ($store->platform === 'bigcommerce') {
            $client = StoreClientFactory::for($store);
            $hydrated = $client instanceof BigCommerceClient
                ? $client->hydrateWebhook($topic, $this->payload['data'] ?? [])
                : null;
            if ($hydrated === null) {
                return;
            }
            $topic = $hydrated['event'];
            $payload = $hydrated['payload'];
        }

        // A Woo product.updated delivery for a variable product carries no stock on
        // the parent record. Hydrate its variations so the upsert lands the same
        // aggregate a full sync would, instead of blanking a known level.
        if ($store->platform === 'woocommerce' && $topic === 'product.updated' && ($payload['type'] ?? '') === 'variable') {
            $client = StoreClientFactory::for($store);
            if ($client instanceof WooClient) {
                $payload = $client->hydrateProduct($payload);
            }
        }

        $event = $normalizer->normalize($store->platform, $topic, $payload, (string) $store->name);
        if ($event === null || ($event['event_type'] ?? null) === null) {
            return;
        }

        // Product/inventory updates have no contact and drive no automations.
        if (($event['product'] ?? null) !== null) {
            $this->upsertProduct($store, $event['product']);

            return;
        }

        // Resolve the contact (needs at least an email or phone to be useful).
        $contact = $this->resolveContact($contacts, $store, $event['contact']);

        if ($event['cart'] !== null) {
            $this->handleCart($store, $contact, $event['cart']);

            return;
        }

        if ($event['order'] !== null) {
            $this->handleOrder($store, $contact, $event, $enricher);

            return;
        }

        // customer.created (no order/cart payload)
        if ($contact && $event['event_type'] === 'customer.created') {
            $enricher->markAsCustomer($contact, $store, $event['contact']['external_id'] ?? null);
            CommerceEventReceived::dispatch($store->workspace_id, $contact->id, 'customer.created', $event['context']);
        }
    }

    /**
     * @param  array<string, mixed>  $contactData
     */
    private function resolveContact(ContactService $contacts, EcommerceStore $store, array $contactData): ?Contact
    {
        if (empty($contactData['email']) && empty($contactData['phone_e164'])) {
            return null;
        }

        return $contacts->upsert($store->workspace_id, array_filter([
            'phone_e164' => $contactData['phone_e164'] ?? null,
            'email' => $contactData['email'] ?? null,
            'first_name' => $contactData['first_name'] ?? null,
            'last_name' => $contactData['last_name'] ?? null,
            'source' => $store->platform,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleOrder(EcommerceStore $store, ?Contact $contact, array $event, ContactEnricher $enricher): void
    {
        // Drop null fields so an update (e.g. a status change) doesn't wipe existing
        // tracking/fulfillment the source didn't provide; only set contact_id when resolved.
        $orderData = array_filter($event['order'], fn ($v) => $v !== null);
        $orderData['workspace_id'] = $store->workspace_id;
        if ($contact) {
            $orderData['contact_id'] = $contact->id;
        }

        $order = EcommerceOrder::updateOrCreate(
            ['store_id' => $store->id, 'external_order_id' => $event['order']['external_order_id']],
            $orderData,
        );

        // An order arriving for a checkout means the cart was recovered — stop its trigger.
        EcommerceCart::where('store_id', $store->id)
            ->where('contact_id', $contact?->id)
            ->whereNull('recovered_at')
            ->update(['recovered_at' => now()]);

        if (! $contact) {
            return;
        }

        $enricher->enrich($contact, $store);

        CommerceEventReceived::dispatch(
            $store->workspace_id,
            $contact->id,
            $event['event_type'],
            $event['context'],
        );
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function upsertProduct(EcommerceStore $store, array $productData): void
    {
        // Drop null fields so a webhook can't wipe a value the sync established and
        // this payload simply doesn't carry (e.g. variation stock when the variations
        // hydrate failed) — same rule handleOrder applies to tracking/fulfillment.
        $data = array_filter($productData, fn ($v) => $v !== null);
        $data['workspace_id'] = $store->workspace_id;
        $data['last_seen_at'] = now();

        EcommerceProduct::updateOrCreate(
            ['store_id' => $store->id, 'external_id' => $productData['external_id']],
            $data,
        );
    }

    /**
     * @param  array<string, mixed>  $cartData
     */
    private function handleCart(EcommerceStore $store, ?Contact $contact, array $cartData): void
    {
        // Shopify/Woo carts fire at checkout creation (wait to see if abandoned);
        // BigCommerce fires when already abandoned (check almost immediately).
        $delayMinutes = $cartData['recovery_delay_minutes'] ?? self::ABANDONED_AFTER_MINUTES;
        unset($cartData['recovery_delay_minutes']);

        $cart = EcommerceCart::updateOrCreate(
            ['store_id' => $store->id, 'external_id' => $cartData['external_id']],
            array_merge($cartData, [
                'workspace_id' => $store->workspace_id,
                'contact_id' => $contact?->id,
            ]),
        );

        // Only schedule recovery once, and only when we have a contact to message.
        if ($contact && ! $cart->recovery_triggered_at && ! $cart->recovered_at) {
            CheckAbandonedCartJob::dispatch($cart->id)->delay(now()->addMinutes($delayMinutes));
        }
    }
}
