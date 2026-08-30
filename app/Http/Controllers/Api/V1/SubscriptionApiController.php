<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientSubscription;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionApiController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $effective = $user->effectiveSubscription();

        if (! $effective) {
            return response()->json(['data' => null]);
        }

        $plan = $effective->plan;

        return response()->json([
            'data' => [
                'plan' => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                ],
                'billing_cycle' => $effective instanceof Subscription ? $effective->billing_cycle : null,
                'status' => $effective->status ?? ($effective->isActive() ? 'active' : 'inactive'),
                'renews_at' => $effective instanceof Subscription ? $effective->renews_at?->toIso8601String() : null,
                'ends_at' => $effective->ends_at?->toIso8601String(),
                'gateway' => $effective instanceof Subscription ? $effective->gateway : null,
                'managed_by_admin' => $effective instanceof ClientSubscription,
            ],
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        $user = $request->user();
        $plan = $user->effectiveSubscription()?->plan;

        return response()->json([
            'data' => [
                'plan_name' => $plan?->name,
                'limits' => $plan ? [
                    'users' => $plan->limitValue('users'),
                    'storage_gb' => $plan->limitValue('storage_gb'),
                ] : null,
            ],
        ]);
    }
}
