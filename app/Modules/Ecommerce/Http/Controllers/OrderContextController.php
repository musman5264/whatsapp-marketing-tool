<?php

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Shared\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderContextController extends Controller
{
    /**
     * Recent ecommerce orders for a contact — rendered in the Inbox sidebar.
     */
    public function index(Request $request, Contact $contact): JsonResponse
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
        abort_unless($contact->workspace_id === $workspaceId, 403);

        $orders = EcommerceOrder::where('workspace_id', $workspaceId)
            ->where('contact_id', $contact->id)
            ->latest('placed_at')
            ->take(5)
            ->get(['number', 'status', 'financial_status', 'fulfillment_status', 'currency', 'total', 'tracking_url', 'placed_at']);

        return response()->json($orders);
    }
}
