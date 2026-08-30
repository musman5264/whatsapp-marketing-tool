<?php

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ecommerce\Models\EcommerceProduct;
use App\Modules\Ecommerce\Models\EcommerceStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /** Inventory at or below this is flagged as low stock. */
    public const LOW_STOCK_THRESHOLD = 5;

    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);

        $query = EcommerceProduct::where('ecommerce_products.workspace_id', $workspaceId)
            ->when($request->input('store_id'), fn ($q, $id) => $q->where('store_id', $id))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('sku', 'like', "%{$s}%")))
            ->when($request->boolean('low_stock'), fn ($q) => $q
                ->whereNotNull('inventory_quantity')
                ->where('inventory_quantity', '<=', self::LOW_STOCK_THRESHOLD));

        $products = (clone $query)
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (EcommerceProduct $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'price' => $p->price,
                'inventory_quantity' => $p->inventory_quantity,
                'status' => $p->status,
                'image_url' => $p->image_url,
                'platform' => $p->platform,
            ]);

        return Inertia::render('Ecommerce/Products/Index', [
            'products' => $products,
            'filters' => $request->only('store_id', 'search', 'low_stock'),
            'stores' => $this->workspaceStores($workspaceId),
            'stats' => [
                'total' => (clone $query)->count(),
                'low_stock' => EcommerceProduct::where('workspace_id', $workspaceId)
                    ->whereNotNull('inventory_quantity')
                    ->where('inventory_quantity', '<=', self::LOW_STOCK_THRESHOLD)
                    ->count(),
                'out_of_stock' => EcommerceProduct::where('workspace_id', $workspaceId)
                    ->whereNotNull('inventory_quantity')
                    ->where('inventory_quantity', '<=', 0)
                    ->count(),
            ],
            'lowStockThreshold' => self::LOW_STOCK_THRESHOLD,
        ]);
    }

    /**
     * Lightweight product search for the Inbox "share product" picker (JSON).
     */
    public function search(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $q = trim((string) $request->input('q', ''));

        $products = EcommerceProduct::with('store:id,external_meta')
            ->where('workspace_id', $workspaceId)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('sku', 'like', "%{$q}%")))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'store_id', 'name', 'sku', 'price', 'inventory_quantity', 'status', 'image_url', 'platform'])
            ->map(fn (EcommerceProduct $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'price' => $p->price,
                'currency' => $p->store?->external_meta['currency'] ?? null,
                'inventory_quantity' => $p->inventory_quantity,
                'status' => $p->status,
                'image_url' => $p->image_url,
                'platform' => $p->platform,
            ]);

        return response()->json($products);
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function workspaceStores(int $workspaceId): array
    {
        return EcommerceStore::where('workspace_id', $workspaceId)
            ->get(['id', 'name'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
            ->all();
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
