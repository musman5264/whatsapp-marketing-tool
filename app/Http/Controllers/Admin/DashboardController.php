<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Allowed date-range windows (in days). */
    private const RANGES = [7, 30, 90];

    public function __invoke(Request $request, AnalyticsService $analytics): Response
    {
        $range = (int) $request->integer('range', 30);
        if (! in_array($range, self::RANGES, true)) {
            $range = 30;
        }

        $now = Carbon::now();
        $from = $now->copy()->subDays($range - 1)->startOfDay();
        $to = $now->copy()->endOfDay();
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($range - 1)->startOfDay();
        $startOfMonth = $now->copy()->startOfMonth();

        // ── Headline metrics ─────────────────────────────────────────────────────
        $mrr = $this->computeMrr();
        $subscriptionsActive = Subscription::whereIn('status', ['active', 'trialing'])->count();
        $trialing = Subscription::where('status', 'trialing')->count();

        $clientsCount = Client::count();
        $newClients = Client::whereBetween('created_at', [$from, $to])->count();
        $newClientsPrev = Client::whereBetween('created_at', [$prevFrom, $prevTo])->count();

        $usersCount = User::count();
        $newUsers = User::whereBetween('created_at', [$from, $to])->count();

        $revenuePeriod = (int) PaymentTransaction::where('status', 'succeeded')
            ->whereBetween('created_at', [$from, $to])->sum('amount_cents');
        $revenuePrev = (int) PaymentTransaction::where('status', 'succeeded')
            ->whereBetween('created_at', [$prevFrom, $prevTo])->sum('amount_cents');
        $paymentsThisMonth = (int) PaymentTransaction::where('status', 'succeeded')
            ->where('created_at', '>=', $startOfMonth)->sum('amount_cents');

        $messagesPeriod = Message::whereBetween('created_at', [$from, $to])->count();
        $messagesPrev = Message::whereBetween('created_at', [$prevFrom, $prevTo])->count();

        $arpu = $subscriptionsActive > 0 ? round($mrr / $subscriptionsActive, 2) : 0.0;

        return Inertia::render('Admin/Dashboard', [
            'range' => $range,
            'stats' => [
                'mrr' => round($mrr, 2),
                'arpu' => $arpu,
                'subscriptions_active' => $subscriptionsActive,
                'subscriptions_trialing' => $trialing,
                'clients_count' => $clientsCount,
                'new_clients' => $newClients,
                'new_clients_delta' => $this->pctDelta($newClients, $newClientsPrev),
                'users_count' => $usersCount,
                'new_users' => $newUsers,
                'revenue_period_cents' => $revenuePeriod,
                'revenue_delta' => $this->pctDelta($revenuePeriod, $revenuePrev),
                'payments_this_month_cents' => $paymentsThisMonth,
                'messages_period' => $messagesPeriod,
                'messages_delta' => $this->pctDelta($messagesPeriod, $messagesPrev),
                'contacts_total' => Contact::count(),
                'conversations_total' => Conversation::count(),
            ],
            'charts' => [
                'mrr_trend' => $analytics->mrrTrend(12),
                'revenue_by_day' => $analytics->revenueByDay($from, $to),
                'new_clients_by_day' => $analytics->newClientsByDay($from, $to),
                'new_clients' => $analytics->newClientsPerWeek(12),
                'plan_distribution' => $analytics->planDistribution(),
                'subscription_status' => $analytics->subscriptionStatusBreakdown(),
                'messages_by_day' => $analytics->platformMessageVolumeByChannel($from, $to),
                'channel_mix' => $analytics->platformChannelMix($from, $to),
                'top_ai_workspaces' => $analytics->topWorkspacesByAiCost(10),
            ],
            'tables' => [
                'recent_clients' => $analytics->recentClients(6),
                'recent_payments' => $analytics->recentPayments(6),
            ],
            'warnings' => array_filter([
                (config('mail.default') === 'log' && app()->isProduction())
                    ? 'MAIL_MAILER is set to "log" – emails will NOT be delivered to users in production.'
                    : null,
            ]),
        ]);
    }

    /**
     * Current platform MRR from active/trialing subscriptions (yearly normalised to monthly).
     */
    private function computeMrr(): float
    {
        return Subscription::whereIn('status', ['active', 'trialing'])
            ->whereHas('plan')
            ->with('plan')
            ->get()
            ->sum(function ($sub) {
                $plan = $sub->plan;
                $cents = $plan->monthly_price_cents ?? $plan->price_cents ?? 0;
                if ($sub->renews_at && $sub->renews_at->diffInMonths($sub->starts_at) >= 12) {
                    $cents = (int) (($plan->yearly_price_cents ?? $plan->price_cents ?? 0) / 12);
                }

                return $cents / 100;
            });
    }

    /**
     * Percentage change between two periods. Null when there is no comparable baseline.
     */
    private function pctDelta(float|int $current, float|int $previous): ?float
    {
        $current = (float) $current;
        $previous = (float) $previous;

        if ($previous <= 0.0) {
            return $current > 0.0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
