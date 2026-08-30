<?php

namespace App\Modules\Leads\Jobs;

use App\Modules\Leads\Models\LeadScrapeJob;
use App\Modules\Leads\Services\GooglePlacesScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScrapeLeadsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Up to 3 pages × 20 places, each needing a details lookup, plus pagination backoff. */
    public int $timeout = 600;

    public function __construct(public readonly int $scrapeJobId) {}

    public function handle(GooglePlacesScraper $scraper): void
    {
        $job = LeadScrapeJob::find($this->scrapeJobId);
        if (! $job || $job->status === 'done') {
            return;
        }
        $scraper->run($job);
    }

    /**
     * The scraper catches its own errors, so this only fires when the worker kills
     * the job from the outside (timeout, OOM, release). Without it the row stays
     * 'running' forever and the UI shows a scrape that never ends.
     */
    public function failed(?\Throwable $e): void
    {
        $job = LeadScrapeJob::find($this->scrapeJobId);
        if (! $job || $job->status === 'done') {
            return;
        }

        $job->update([
            'status' => 'failed',
            'error' => $e?->getMessage() ?? 'Scrape job was stopped before it finished (timed out or the worker restarted).',
            'completed_at' => now(),
        ]);
    }
}
