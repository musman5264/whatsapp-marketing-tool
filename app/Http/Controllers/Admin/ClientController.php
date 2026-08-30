<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    public function __construct(private AuditLogService $auditLog) {}

    public function index(Request $request): Response
    {
        $this->authorizeForUser($request->user('admin'), 'viewAny', Client::class);

        $query = Client::query()->with('activeSubscription.plan');

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $clients = $query->orderBy('name')->paginate(20)->withQueryString()->through(function (Client $c) {
            // Effective plan: admin-assigned ClientSubscription, else the plan from a
            // user's own active Subscription — matches what the client's dashboard shows.
            $plan = $c->effectivePlan();

            return [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'address' => $c->address,
                'status' => $c->status,
                'base_currency' => $c->base_currency,
                'currency_symbol' => $c->currency_symbol,
                'currency_position' => $c->currency_position,
                'subscription' => $plan ? ['name' => $plan->name] : null,
            ];
        });

        $plans = Plan::where('enabled', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'slug', 'currency_code', 'monthly_price_cents', 'yearly_price_cents']);

        return Inertia::render('Admin/Clients/Index', [
            'clients' => $clients,
            'plans' => $plans->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'currency_code' => $p->currency_code,
                'monthly_price_cents' => $p->monthly_price_cents,
                'yearly_price_cents' => $p->yearly_price_cents,
            ]),
            'filters' => $request->only(['search']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'create', Client::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'base_currency' => ['nullable', 'string', 'max:10'],
            'currency_symbol' => ['nullable', 'string', 'max:16'],
            'currency_position' => ['nullable', 'string', 'in:before,after'],
        ]);

        $validated['status'] = $validated['status'] ?? 'active';
        // Currency fields left null inherit the platform default currency.

        $client = Client::create($validated);

        $this->auditLog->logAdmin('client.created', Client::class, (int) $client->id, ['name' => $client->name]);

        return redirect()->route('admin.clients.index')->with('success', __('Client created.'));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'update', $client);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'base_currency' => ['nullable', 'string', 'max:10'],
            'currency_symbol' => ['nullable', 'string', 'max:16'],
            'currency_position' => ['nullable', 'string', 'in:before,after'],
        ]);

        $client->update($validated);

        $this->auditLog->logAdmin('client.updated', Client::class, (int) $client->id, ['name' => $client->name]);

        return redirect()->back()->with('success', __('Client updated.'));
    }

    public function destroy(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'delete', $client);

        $name = $client->name;
        $client->delete();

        $this->auditLog->logAdmin('client.deleted', null, null, ['name' => $name]);

        return redirect()->route('admin.clients.index')->with('success', __('Client deleted.'));
    }

    public function users(Request $request, Client $client): JsonResponse
    {
        $this->authorizeForUser($request->user('admin'), 'view', $client);

        $users = $client->users()->orderBy('created_at')->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'client_role' => $u->client_role ?? 'staff',
            'status' => $u->status ?? 'active',
            'created_at' => $u->created_at->toIso8601String(),
        ]);

        return response()->json(['users' => $users, 'client' => ['id' => $client->id, 'name' => $client->name]]);
    }

    public function storeUser(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        $this->authorizeForUser($request->user('admin'), 'update', $client);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'client_role' => ['required', 'string', 'in:administrator,staff'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $user = $client->users()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
            'client_role' => $validated['client_role'],
            'status' => $validated['status'],
        ]);

        $this->auditLog->logAdmin('client.user_created', User::class, (int) $user->id, ['client_id' => $client->id, 'email' => $user->email]);

        if ($request->wantsJson()) {
            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'client_role' => $user->client_role,
                    'status' => $user->status,
                    'created_at' => $user->created_at->toIso8601String(),
                ],
            ], 201);
        }

        return redirect()->back()->with('success', __('User added.'));
    }

    public function updateUser(Request $request, Client $client, User $user): RedirectResponse|JsonResponse
    {
        $this->authorizeForUser($request->user('admin'), 'update', $client);
        if ($user->client_id !== $client->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'client_role' => ['required', 'string', 'in:administrator,staff'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->client_role = $validated['client_role'];
        $user->status = $validated['status'];
        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }
        $user->save();

        $this->auditLog->logAdmin('client.user_updated', User::class, (int) $user->id, ['client_id' => $client->id]);

        if ($request->wantsJson()) {
            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'client_role' => $user->client_role,
                    'status' => $user->status,
                    'created_at' => $user->created_at->toIso8601String(),
                ],
            ]);
        }

        return redirect()->back()->with('success', __('User updated.'));
    }

    public function destroyUser(Request $request, Client $client, User $user): RedirectResponse|JsonResponse
    {
        $this->authorizeForUser($request->user('admin'), 'update', $client);
        if ($user->client_id !== $client->id) {
            abort(404);
        }

        $adminCount = $client->users()->where('client_role', User::CLIENT_ROLE_ADMINISTRATOR)->count();
        if ($user->client_role === User::CLIENT_ROLE_ADMINISTRATOR && $adminCount <= 1) {
            if ($request->wantsJson()) {
                return response()->json(['message' => __('Cannot delete the last client administrator.')], 422);
            }

            return redirect()->back()->with('error', __('Cannot delete the last client administrator.'));
        }

        $user->delete();
        $this->auditLog->logAdmin('client.user_deleted', User::class, null, ['client_id' => $client->id, 'email' => $user->email]);

        if ($request->wantsJson()) {
            return response()->json([], 204);
        }

        return redirect()->back()->with('success', __('User removed.'));
    }

    public function assignPlan(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        $this->authorizeForUser($request->user('admin'), 'assignPlan', $client);

        $validated = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'billing_cycle' => ['required', 'string', 'in:monthly,yearly'],
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);
        $client->clientSubscriptions()->where('status', ClientSubscription::STATUS_ACTIVE)->update(['status' => ClientSubscription::STATUS_CANCELLED, 'ends_at' => now()]);

        $sub = $client->clientSubscriptions()->create([
            'plan_id' => $plan->id,
            'billing_cycle' => $validated['billing_cycle'],
            'starts_at' => now(),
            'status' => ClientSubscription::STATUS_ACTIVE,
            'assigned_by_admin_id' => $request->user('admin')->id,
        ]);

        $this->auditLog->logAdmin('client.plan_assigned', Client::class, (int) $client->id, [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'billing_cycle' => $validated['billing_cycle'],
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'subscription' => [
                    'id' => $sub->id,
                    'plan' => ['name' => $plan->name],
                    'billing_cycle' => $sub->billing_cycle,
                ],
            ]);
        }

        return redirect()->back()->with('success', __('Plan assigned.'));
    }

    public function impersonate(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'impersonate', $client);

        if ($request->session()->get('impersonating')) {
            return redirect()->route('admin.clients.index')->with('error', __('Already impersonating.'));
        }

        $targetUser = $client->users()
            ->where('status', User::STATUS_ACTIVE)
            ->orderByRaw("CASE WHEN client_role = 'administrator' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->first();

        if (! $targetUser) {
            return redirect()->back()->with('error', __('Client has no active users. Add a user first.'));
        }

        $admin = $request->user('admin');

        $request->session()->put('impersonator_admin_id', $admin->id);
        $request->session()->put('impersonating', true);
        $request->session()->put('impersonated_client_id', $client->id);

        Auth::guard('web')->login($targetUser, $request->boolean('remember', false));

        $this->auditLog->logAdmin('impersonation.started', User::class, (int) $targetUser->id, [
            'client_id' => $client->id,
            'client_name' => $client->name,
        ]);

        return redirect()->route('client.dashboard');
    }

    /**
     * Export all clients as a CSV download.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorizeForUser($request->user('admin'), 'viewAny', Client::class);

        $q = $request->get('search');

        return response()->streamDownload(function () use ($q) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Email', 'Phone', 'Status', 'Plan', 'Created At']);

            Client::with('activeSubscription.plan')
                ->when($q, fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"))
                ->orderBy('name')
                ->chunk(200, function ($clients) use ($handle) {
                    foreach ($clients as $c) {
                        fputcsv($handle, [
                            $c->id,
                            $c->name,
                            $c->email,
                            $c->phone,
                            $c->status,
                            $c->activeSubscription?->plan?->name ?? '',
                            $c->created_at->toDateString(),
                        ]);
                    }
                });

            fclose($handle);
        }, 'clients_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
