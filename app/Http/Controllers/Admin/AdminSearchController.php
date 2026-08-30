<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSearchController extends Controller
{
    public function __construct(private readonly CurrencyService $currency) {}

    public function search(Request $request): JsonResponse
    {
        $query = trim($request->get('q', ''));

        if (strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $lower = strtolower($query);
        $results = [];

        // ── Navigation pages ────────────────────────────────────────────────
        $navItems = [
            ['label' => 'Dashboard',            'href' => route('admin.dashboard'),            'icon' => 'LayoutDashboard'],
            ['label' => 'Clients',               'href' => route('admin.clients.index'),        'icon' => 'Building2'],
            ['label' => 'Users',                 'href' => route('admin.users.index'),          'icon' => 'Users'],
            ['label' => 'Subscriptions',         'href' => route('admin.subscriptions.index'), 'icon' => 'CreditCard'],
            ['label' => 'Plans',                 'href' => route('admin.plans.index'),          'icon' => 'Package'],
            ['label' => 'Admin Settings',        'href' => route('admin.settings.index'),       'icon' => 'Settings'],
            ['label' => 'CMS Pages',             'href' => route('admin.cms.index'),            'icon' => 'FileText'],
        ];

        foreach ($navItems as $item) {
            if (str_contains(strtolower($item['label']), $lower)) {
                $results[] = array_merge($item, ['type' => 'page']);
            }
        }

        // ── Clients ─────────────────────────────────────────────────────────
        Client::where('name', 'like', "%{$query}%")
            ->orWhere('email', 'like', "%{$query}%")
            ->limit(5)
            ->get()
            ->each(function (Client $client) use (&$results) {
                $results[] = [
                    'type' => 'client',
                    'label' => $client->name,
                    'sub' => $client->email,
                    'href' => route('admin.clients.show', $client),
                    'icon' => 'Building2',
                ];
            });

        // ── Users ────────────────────────────────────────────────────────────
        User::where('name', 'like', "%{$query}%")
            ->orWhere('email', 'like', "%{$query}%")
            ->limit(5)
            ->get()
            ->each(function (User $user) use (&$results) {
                $results[] = [
                    'type' => 'user',
                    'label' => $user->name,
                    'sub' => $user->email,
                    'href' => route('admin.users.show', $user),
                    'icon' => 'User',
                ];
            });

        // ── Plans ─────────────────────────────────────────────────────────
        Plan::where('name', 'like', "%{$query}%")
            ->limit(5)
            ->get()
            ->each(function (Plan $plan) use (&$results) {
                $code = $plan->currency_code ?: (Currency::defaultCode() ?? 'USD');
                $results[] = [
                    'type' => 'plan',
                    'label' => $plan->name,
                    'sub' => $this->currency->format((int) $plan->monthly_price_cents, $code).'/mo',
                    'href' => route('admin.plans.edit', $plan),
                    'icon' => 'Package',
                ];
            });

        // ── Subscriptions ────────────────────────────────────────────────
        Subscription::whereHas('client', function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")->orWhere('email', 'like', "%{$query}%");
        })
            ->with('client', 'plan')
            ->limit(5)
            ->get()
            ->each(function (Subscription $sub) use (&$results) {
                $results[] = [
                    'type' => 'subscription',
                    'label' => $sub->client?->name ?? 'Subscription',
                    'sub' => $sub->plan?->name.' · '.$sub->status,
                    'href' => route('admin.subscriptions.show', $sub),
                    'icon' => 'CreditCard',
                ];
            });

        return response()->json(['results' => array_slice($results, 0, 15)]);
    }
}
