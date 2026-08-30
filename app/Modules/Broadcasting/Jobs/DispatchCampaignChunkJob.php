<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCampaignChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $campaignId,
        public readonly array $contactIds,
    ) {}

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign || $campaign->status === 'failed') {
            return;
        }

        foreach ($this->contactIds as $i => $contactId) {
            SendCampaignMessageJob::dispatch($campaign->id, $contactId)
                ->onQueue('broadcast')
                ->delay(now()->addMilliseconds($i * 100)); // 10 msgs/second rate limit
        }
    }
}
