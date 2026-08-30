<?php

namespace App\Modules\Ecommerce\Services;

use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;

/**
 * Recomputes commerce metrics into a contact's custom_fields and applies
 * lifecycle tags ("Shopify Customer", "Repeat Buyer").
 */
class ContactEnricher
{
    public function enrich(Contact $contact, EcommerceStore $store): void
    {
        // Aggregate per currency so we never sum across mixed currencies into one
        // meaningless scalar. lifetime_value stays as the dominant-currency total
        // for back-compat with {{contact.custom.lifetime_value}} tokens.
        $rows = EcommerceOrder::query()
            ->where('workspace_id', $store->workspace_id)
            ->where('contact_id', $contact->id)
            ->selectRaw('currency, COUNT(*) as cnt, COALESCE(SUM(total), 0) as total')
            ->groupBy('currency')
            ->get();

        $orderCount = (int) $rows->sum('cnt');
        $byCurrency = [];
        foreach ($rows as $r) {
            $byCurrency[$r->currency ?: 'UNKNOWN'] = (float) $r->total;
        }
        $primary = $rows->sortByDesc('total')->first();

        $lastOrderAt = EcommerceOrder::where('workspace_id', $store->workspace_id)
            ->where('contact_id', $contact->id)
            ->max('placed_at');

        $contact->custom_fields = array_merge($contact->custom_fields ?? [], [
            'ecommerce_platform' => $store->platform,
            'order_count' => $orderCount,
            'lifetime_value' => (float) ($primary->total ?? 0),
            'lifetime_currency' => $primary->currency ?? null,
            'lifetime_value_by_currency' => $byCurrency,
            'last_order_at' => $lastOrderAt ? (string) $lastOrderAt : null,
        ]);
        $contact->save();

        $this->tag($contact, $store->workspace_id, $this->platformLabel($store->platform).' Customer');
        if ($orderCount > 1) {
            $this->tag($contact, $store->workspace_id, 'Repeat Buyer');
        }
    }

    /**
     * Lightweight tagging used during bulk customer sync (no order metrics yet).
     */
    public function markAsCustomer(Contact $contact, EcommerceStore $store, ?string $externalId = null): void
    {
        if ($externalId) {
            $key = match ($store->platform) {
                'shopify' => 'shopify_customer_id',
                'bigcommerce' => 'bigcommerce_customer_id',
                default => 'woo_customer_id',
            };
            $contact->custom_fields = array_merge($contact->custom_fields ?? [], [
                $key => $externalId,
                'ecommerce_platform' => $store->platform,
            ]);
            $contact->save();
        }

        $this->tag($contact, $store->workspace_id, $this->platformLabel($store->platform).' Customer');
    }

    private function tag(Contact $contact, int $workspaceId, string $name): void
    {
        $tag = ContactTag::firstOrCreate(
            ['workspace_id' => $workspaceId, 'name' => $name],
            ['color' => '#16a34a'],
        );
        $contact->tags()->syncWithoutDetaching([$tag->id]);
    }

    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'shopify' => 'Shopify',
            'bigcommerce' => 'BigCommerce',
            default => 'WooCommerce',
        };
    }
}
