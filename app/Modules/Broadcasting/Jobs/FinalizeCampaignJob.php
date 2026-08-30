<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Events\CampaignCompleted;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Finalises a campaign once all recipient sends have settled.
 *
 * Counts recipients still in the `queued` bucket; if any remain, it
 * re-schedules itself a minute later. Otherwise, marks the campaign
 * as `completed`, refreshes totals, and fires CampaignCompleted.
 */
class FinalizeCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    /** Used to bound max self-rescheduling (24h). */
    public function __construct(
        public readonly int $campaignId,
        public readonly int $attempt = 1,
    ) {}

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign) {
            return;
        }

        // If user paused the campaign, don't auto-complete; wait for resume.
        if ($campaign->status === 'paused') {
            return;
        }

        // Already finalised
        if (in_array($campaign->status, ['completed', 'failed', 'draft'], true)) {
            $campaign->updateTotals();

            return;
        }

        $stillQueued = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->count();

        if ($stillQueued > 0 && $this->attempt < 1440) {
            // Re-schedule in 60s; keeps polling for at most ~24h.
            self::dispatch($campaign->id, $this->attempt + 1)
                ->onQueue('broadcast')
                ->delay(now()->addSeconds(60));

            return;
        }

        $totals = CampaignRecipient::where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $sent = (int) ($totals['sent'] ?? 0)
            + (int) ($totals['delivered'] ?? 0)
            + (int) ($totals['read'] ?? 0);
        $failed = (int) ($totals['failed'] ?? 0);
        $total = $sent + $failed + (int) ($totals['queued'] ?? 0);

        $newStatus = ($total === 0 || ($failed > 0 && $sent === 0)) ? 'failed' : 'completed';

        // Atomic guard: only one concurrent worker may finalize the campaign.
        // If another worker already set the status, affected=0 and we skip the event.
        $affected = Campaign::where('id', $campaign->id)
            ->whereNotIn('status', ['completed', 'failed', 'draft'])
            ->update(['status' => $newStatus]);

        if ($affected === 0) {
            return;
        }

        $campaign->updateTotals();

        CampaignCompleted::dispatch($campaign->fresh());
    }
}
