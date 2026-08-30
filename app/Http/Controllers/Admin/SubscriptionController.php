<?php

namespace App\Http\Controllers\Admin;

use App\Events\SubscriptionStarted;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscriptionController extends Controller
{
    public function __construct(private AuditLogService $auditLog) {}

    public function index(Request $request): Response
    {
        $query = Subscription::with(['user:id,name,email', 'plan:id,name,slug']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('gateway')) {
            $query->where('gateway', $request->gateway);
        }

        $subscriptions = $query->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Subscription $s) => [
                'id' => $s->id,
                'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name, 'email' => $s->user->email] : null,
                'plan' => $s->plan ? ['id' => $s->plan->id, 'name' => $s->plan->name, 'slug' => $s->plan->slug] : null,
                'status' => $s->status,
                'gateway' => $s->gateway,
                'gateway_subscription_id' => $s->gateway_subscription_id,
                'starts_at' => $s->starts_at?->toIso8601String(),
                'ends_at' => $s->ends_at?->toIso8601String(),
                'renews_at' => $s->renews_at?->toIso8601String(),
                'created_at' => $s->created_at->toIso8601String(),
            ]);

        return Inertia::render('Admin/Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'filters' => $request->only(['status', 'gateway']),
            'plans' => Plan::where('enabled', true)
                ->orderBy('sort_order')
                ->get(['id', 'name'])
                ->map(fn (Plan $p) => ['id' => $p->id, 'name' => $p->name]),
        ]);
    }

    /**
     * Search users by name or email to power the manual-subscription user picker.
     */
    public function userSearch(Request $request): JsonResponse
    {
        $query = trim($request->get('q', ''));

        if (strlen($query) < 2) {
            return response()->json(['users' => []]);
        }

        $users = User::where(function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
                ->orWhere('email', 'like', "%{$query}%");
        })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]);

        return response()->json(['users' => $users]);
    }

    /**
     * Manually create a subscription (gateway "manual"), e.g. for comped or
     * offline-paid customers. Ends any subscription currently granting access.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'billing_cycle' => ['required', Rule::in(['month', 'year'])],
            'status' => ['required', Rule::in(['active', 'trialing'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'trial_ends_at' => ['nullable', 'required_if:status,trialing', 'date'],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $plan = Plan::findOrFail($validated['plan_id']);

        Subscription::where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->update(['status' => 'canceled', 'ends_at' => now()]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'billing_cycle' => $validated['billing_cycle'],
            'status' => $validated['status'],
            'starts_at' => $validated['starts_at'] ?? now(),
            'ends_at' => $validated['ends_at'] ?? null,
            'gateway' => 'manual',
            'gateway_subscription_id' => null,
            'trial_ends_at' => $validated['status'] === 'trialing' ? $validated['trial_ends_at'] : null,
        ]);

        $this->auditLog->logAdmin('subscription.manual_created', Subscription::class, (int) $subscription->id, [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'billing_cycle' => $subscription->billing_cycle,
            'status' => $subscription->status,
        ]);

        SubscriptionStarted::dispatch($user, $subscription, $plan);

        return redirect()->back()->with('success', __('Subscription created.'));
    }

    /**
     * Export subscriptions as a CSV download.
     */
    public function export(Request $request): StreamedResponse
    {
        return response()->streamDownload(function () use ($request) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'User', 'Email', 'Plan', 'Status', 'Gateway', 'Starts At', 'Ends At', 'Created At']);

            Subscription::with(['user:id,name,email', 'plan:id,name'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->when($request->filled('gateway'), fn ($q) => $q->where('gateway', $request->gateway))
                ->orderByDesc('created_at')
                ->chunk(200, function ($subs) use ($handle) {
                    foreach ($subs as $s) {
                        fputcsv($handle, [
                            $s->id,
                            $s->user?->name ?? '',
                            $s->user?->email ?? '',
                            $s->plan?->name ?? '',
                            $s->status,
                            $s->gateway,
                            $s->starts_at?->toDateString() ?? '',
                            $s->ends_at?->toDateString() ?? '',
                            $s->created_at->toDateString(),
                        ]);
                    }
                });

            fclose($handle);
        }, 'subscriptions_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
