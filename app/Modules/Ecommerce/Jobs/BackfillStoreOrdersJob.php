<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;
use App\Modules\Ecommerce\Services\ContactCapacity;
use App\Modules\Ecommerce\Services\ContactEnricher;
use App\Modules\Ecommerce\Services\PayloadNormalizer;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Services\ContactService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Imports historical orders so that order context (Inbox/AI) and enrichment
 * metrics are populated immediately after connecting. Does NOT fire automations.
 */
class BackfillStoreOrdersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $storeId,
        public readonly ?string $cursor = null,
    ) {}

    public function handle(PayloadNormalizer $normalizer, ContactService $contacts, ContactEnricher $enricher, ContactCapacity $capacity): void
    {
        $store = EcommerceStore::find($this->storeId);
        if (! $store) {
            return;
        }

        // Orders are always imported; only new-contact creation is gated by the cap.
        $canCreateContacts = ($capacity->remaining($store->workspace_id) ?? PHP_INT_MAX) > 0;

        $topic = $store->platform === 'shopify' ? 'orders/create' : 'order.created';
        $page = StoreClientFactory::for($store)->fetchOrders($this->cursor);

        $touchedContacts = [];

        foreach ($page['orders'] as $raw) {
            $event = $normalizer->normalize($store->platform, $topic, $raw, (string) $store->name);
            if ($event === null || $event['order'] === null) {
                continue;
            }

            $contact = $this->resolveContact($contacts, $store, $event['contact'], $canCreateContacts);

            EcommerceOrder::updateOrCreate(
                ['store_id' => $store->id, 'external_order_id' => $event['order']['external_order_id']],
                array_merge($event['order'], [
                    'workspace_id' => $store->workspace_id,
                    'contact_id' => $contact?->id,
                ]),
            );

            if ($contact) {
                $touchedContacts[$contact->id] = $contact;
            }
        }

        foreach ($touchedContacts as $contact) {
            $enricher->enrich($contact, $store);
        }

        if ($page['next'] !== null && $page['next'] !== $this->cursor) {
            self::dispatch($store->id, $page['next']);

            return;
        }

        $store->update(['orders_synced_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $contactData
     */
    private function resolveContact(ContactService $contacts, EcommerceStore $store, array $contactData, bool $canCreateContacts = true): ?Contact
    {
        if (empty($contactData['email']) && empty($contactData['phone_e164'])) {
            return null;
        }

        // At the contact cap: still import the order, just without a (new) contact link.
        if (! $canCreateContacts) {
            return null;
        }

        return $contacts->upsert($store->workspace_id, array_filter([
            'phone_e164' => $contactData['phone_e164'] ?? null,
            'email' => $contactData['email'] ?? null,
            'first_name' => $contactData['first_name'] ?? null,
            'last_name' => $contactData['last_name'] ?? null,
            'source' => $store->platform,
        ], fn ($v) => $v !== null && $v !== ''), dispatchCreatedEvent: false);
    }
}
