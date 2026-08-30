<?php

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;
use App\Modules\Ecommerce\Services\ContactEnricher;
use App\Modules\Ecommerce\Services\PayloadNormalizer;
use App\Modules\Shared\Services\ContactService;
use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    /** Refresh re-pull uses the platform's "created" topic to re-map fields. */
    private const REFRESH_TOPIC = [
        'shopify' => 'orders/create',
        'woocommerce' => 'order.created',
        'bigcommerce' => 'order.placed',
    ];

    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);

        $base = EcommerceOrder::where('ecommerce_orders.workspace_id', $workspaceId)
            ->when($request->input('store_id'), fn ($q, $id) => $q->where('store_id', $id))
            ->when($request->input('fulfillment'), fn ($q, $s) => $q->where('fulfillment_status', $s))
            ->when($request->input('financial'), fn ($q, $s) => $q->where('financial_status', $s))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$s}%")
                ->orWhereHas('contact', fn ($q) => $q
                    ->where('email', 'like', "%{$s}%")
                    ->orWhere('phone_e164', 'like', "%{$s}%"))));

        $orders = (clone $base)
            ->with('contact:id,uuid,first_name,last_name,email,phone_e164')
            ->latest('placed_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (EcommerceOrder $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'platform' => $o->platform,
                'status' => $o->status,
                'financial_status' => $o->financial_status,
                'fulfillment_status' => $o->fulfillment_status,
                'currency' => $o->currency,
                'total' => $o->total,
                'placed_at' => $o->placed_at,
                'contact' => $o->contact ? [
                    'uuid' => $o->contact->uuid,
                    'name' => Demo::name(trim(($o->contact->first_name ?? '').' '.($o->contact->last_name ?? '')) ?: $o->contact->email),
                    'email' => Demo::email($o->contact->email),
                ] : null,
            ]);

        return Inertia::render('Ecommerce/Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only('store_id', 'fulfillment', 'financial', 'search'),
            'stores' => EcommerceStore::where('workspace_id', $workspaceId)->get(['id', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
            'stats' => [
                'total' => (clone $base)->count(),
                'revenue' => round((float) (clone $base)->sum('total'), 2),
                'fulfilled' => (clone $base)->where('fulfillment_status', 'fulfilled')->count(),
                'unfulfilled' => (clone $base)->where(fn ($q) => $q->whereNull('fulfillment_status')->orWhere('fulfillment_status', '!=', 'fulfilled'))->count(),
            ],
        ]);
    }

    public function show(Request $request, EcommerceOrder $order): Response
    {
        $this->authorizeOrder($request, $order);
        $order->load('contact:id,uuid,first_name,last_name,email,phone_e164', 'store:id,name,platform');

        return Inertia::render('Ecommerce/Orders/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'platform' => $order->platform,
                'status' => $order->status,
                'financial_status' => $order->financial_status,
                'fulfillment_status' => $order->fulfillment_status,
                'currency' => $order->currency,
                'total' => $order->total,
                'line_items' => $order->line_items ?? [],
                'tracking_url' => $order->tracking_url,
                'tracking_number' => $order->tracking_number,
                'placed_at' => $order->placed_at,
                'external_order_id' => $order->external_order_id,
                'store' => $order->store ? ['name' => $order->store->name] : null,
                'contact' => $order->contact ? [
                    'uuid' => $order->contact->uuid,
                    'name' => Demo::name(trim(($order->contact->first_name ?? '').' '.($order->contact->last_name ?? '')) ?: $order->contact->email),
                    'email' => Demo::email($order->contact->email),
                    'phone' => Demo::phone($order->contact->phone_e164),
                ] : null,
            ],
        ]);
    }

    public function refresh(Request $request, EcommerceOrder $order, PayloadNormalizer $normalizer, ContactService $contacts, ContactEnricher $enricher): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        $store = EcommerceStore::find($order->store_id);
        if (! $store) {
            return back()->with('error', 'Store not found.');
        }

        try {
            $raw = StoreClientFactory::for($store)->fetchOrder($order->external_order_id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Refresh failed: '.$e->getMessage());
        }

        if (! $raw) {
            return back()->with('error', 'Order no longer found at the store.');
        }

        $event = $normalizer->normalize($store->platform, self::REFRESH_TOPIC[$store->platform], $raw, (string) $store->name);
        if ($event === null || $event['order'] === null) {
            return back()->with('error', 'Could not parse the order from the store.');
        }

        $contact = null;
        if (! empty($event['contact']['email']) || ! empty($event['contact']['phone_e164'])) {
            $contact = $contacts->upsert($store->workspace_id, array_filter([
                'phone_e164' => $event['contact']['phone_e164'] ?? null,
                'email' => $event['contact']['email'] ?? null,
                'source' => $store->platform,
            ], fn ($v) => $v !== null && $v !== ''));
        }

        // Only overwrite fields the store actually returned, so a refresh doesn't
        // null-out locally-set tracking/fulfillment.
        $data = array_filter($event['order'], fn ($v) => $v !== null);
        if ($contact) {
            $data['contact_id'] = $contact->id;
        }
        $order->update($data);
        if ($contact) {
            $enricher->enrich($contact, $store);
        }

        return back()->with('success', 'Order refreshed from '.$store->name.'.');
    }

    public function fulfill(Request $request, EcommerceOrder $order): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        $validated = $request->validate([
            'tracking_number' => ['nullable', 'string', 'max:128'],
            'tracking_url' => ['nullable', 'url', 'max:512'],
        ]);

        $store = EcommerceStore::find($order->store_id);
        if (! $store) {
            return back()->with('error', 'Store not found.');
        }

        try {
            $result = StoreClientFactory::for($store)->fulfillOrder(
                $order->external_order_id,
                $validated['tracking_number'] ?? null,
                $validated['tracking_url'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Fulfillment failed: '.$e->getMessage());
        }

        // Only reflect locally if the store accepted the change, so the dashboard
        // never shows "fulfilled" for an order the platform actually rejected.
        if (! $result['ok']) {
            return back()->with('error', 'The store rejected the fulfillment: '.$result['message']);
        }

        $order->update([
            'fulfillment_status' => 'fulfilled',
            'tracking_number' => $validated['tracking_number'] ?? $order->tracking_number,
            'tracking_url' => $validated['tracking_url'] ?? $order->tracking_url,
        ]);

        return back()->with('success', 'Order marked as fulfilled at '.$store->name.'.');
    }

    private function authorizeOrder(Request $request, EcommerceOrder $order): void
    {
        abort_unless($order->workspace_id === $this->workspaceId($request), 403);
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
