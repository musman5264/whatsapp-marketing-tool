<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ClientSubscription;
use App\Models\Subscription;
use App\Modules\Automation\Models\Automation;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\AnalyticsService;
use App\Services\OnboardingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Allowed date-range windows (in days). */
    private const RANGES = [7, 30, 90];

    public function __invoke(Request $request, OnboardingService $onboarding, AnalyticsService $analytics): Response
    {
        $user = $request->user();
        $effective = $user->effectiveSubscription();

        $range = (int) $request->integer('range', 30);
        if (! in_array($range, self::RANGES, true)) {
            $range = 30;
        }

        $currentPlan = null;
        $renewsAt = null;
        $managedByAdmin = false;

        if ($effective) {
            $plan = $effective->plan;
            $currentPlan = [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'status' => $effective->isActive() ? 'active' : ($effective->status ?? 'inactive'),
            ];
            if ($effective instanceof Subscription) {
                $renewsAt = $effective->renews_at?->toIso8601String();
            }
            if ($effective instanceof ClientSubscription) {
                $renewsAt = $effective->ends_at?->toIso8601String();
                $managedByAdmin = true;
            }
        }

        $teamMembersCount = $user->client ? $user->client->users()->count() : 1;
        $teamMembersLimit = $effective?->plan?->limits['users'] ?? null;

        $workspacesCount = $user->accessibleWorkspaces()->count();

        $onboardingProgress = $onboarding->getProgress($user);

        // ── Date window (current + previous for deltas) ──────────────────────────
        $wsId = $user->workspace_id;
        $from = Carbon::now()->subDays($range - 1)->startOfDay();
        $to = Carbon::now()->endOfDay();
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($range - 1)->startOfDay();

        $charts = [];
        $stats = null;
        $tables = [];
        $aiKpis = null;

        if ($wsId) {
            // ── Headline KPIs (with previous-period deltas) ──────────────────────
            $messagesOut = $this->messageCount($wsId, $from, $to, 'out');
            $messagesOutPrev = $this->messageCount($wsId, $prevFrom, $prevTo, 'out');
            $messagesIn = $this->messageCount($wsId, $from, $to, 'in');
            $messagesInPrev = $this->messageCount($wsId, $prevFrom, $prevTo, 'in');

            $contactsNew = Contact::where('workspace_id', $wsId)->whereBetween('created_at', [$from, $to])->count();
            $contactsNewPrev = Contact::where('workspace_id', $wsId)->whereBetween('created_at', [$prevFrom, $prevTo])->count();

            $convNew = Conversation::where('workspace_id', $wsId)->whereBetween('created_at', [$from, $to])->count();
            $convNewPrev = Conversation::where('workspace_id', $wsId)->whereBetween('created_at', [$prevFrom, $prevTo])->count();

            $aiKpis = $analytics->aiKpis($wsId, $from, $to);

            $stats = [
                'messages_out' => $messagesOut,
                'messages_out_delta' => $this->pctDelta($messagesOut, $messagesOutPrev),
                'messages_in' => $messagesIn,
                'messages_in_delta' => $this->pctDelta($messagesIn, $messagesInPrev),
                'contacts_total' => Contact::where('workspace_id', $wsId)->count(),
                'contacts_new' => $contactsNew,
                'contacts_new_delta' => $this->pctDelta($contactsNew, $contactsNewPrev),
                'conversations_open' => Conversation::where('workspace_id', $wsId)->whereIn('status', ['open', 'pending'])->count(),
                'conversations_new' => $convNew,
                'conversations_new_delta' => $this->pctDelta($convNew, $convNewPrev),
                'campaigns_total' => Campaign::where('workspace_id', $wsId)->count(),
                'automations_active' => Automation::where('workspace_id', $wsId)->where('status', 'active')->count(),
                'ai_runs' => $aiKpis['total_runs'] ?? 0,
                'ai_cost_cents' => $aiKpis['total_cost_cents'] ?? 0,
            ];

            $charts = [
                'messages' => $analytics->messageVolumeByChannel($wsId, $from, $to),
                'ai_tokens' => $analytics->aiUsageByDay($wsId, $from, $to),
                'conversations' => $analytics->conversationsResolvedOverTime($wsId, $from, $to),
                'contacts_growth' => $analytics->contactsGrowthByDay($wsId, $from, $to),
                'channel_mix' => $analytics->conversationChannelMix($wsId, $from, $to),
                'automation_runs' => $analytics->automationRunsByStatus($wsId, $from, $to),
                'social_posts' => $analytics->socialPostsByStatus($wsId, $from, $to),
            ];

            $tables = [
                'agent_leaderboard' => $analytics->agentLeaderboard($wsId, $from, $to),
                'recent_conversations' => $analytics->recentConversations($wsId, 6),
                'recent_campaigns' => $analytics->recentCampaigns($wsId, 6),
            ];
        }

        return Inertia::render('client/Dashboard', [
            'range' => $range,
            'hasWorkspace' => (bool) $wsId,
            'currentPlan' => $currentPlan,
            'renewsAt' => $renewsAt,
            'managedByAdmin' => $managedByAdmin,
            'usage' => [
                'team_members_count' => $teamMembersCount,
                'team_members_limit' => $teamMembersLimit,
            ],
            'isClientAdministrator' => $user->isClientAdministrator(),
            'workspacesCount' => $workspacesCount,
            'onboardingNextStep' => $onboardingProgress['next_step'],
            'onboardingPercent' => $onboardingProgress['percent'],
            'stats' => $stats,
            'charts' => $charts,
            'tables' => $tables,
            'first_run' => $user->created_at->gt(now()->subMinutes(5)),
        ]);
    }

    /**
     * Count messages for a workspace in a window, optionally filtered by direction.
     */
    private function messageCount(int $wsId, Carbon $from, Carbon $to, ?string $direction = null): int
    {
        $query = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.workspace_id', $wsId)
            ->whereBetween('messages.created_at', [$from, $to]);

        if ($direction !== null) {
            $query->where('messages.direction', $direction);
        }

        return $query->count();
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
