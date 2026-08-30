<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class LaunchScheduledCampaignsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        Campaign::where('status', 'queued')
            ->whereNotNull('schedule_at')
            ->where('schedule_at', '<=', now())
            ->get()
            ->each(fn (Campaign $c) => LaunchCampaignJob::dispatch($c->id)->onQueue('broadcast'));
    }
}
