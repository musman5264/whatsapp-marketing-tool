<?php

namespace App\Jobs;

use App\Models\ApiRequestLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Keeps api_request_logs bounded. Runs daily:
 *   1. Delete rows older than retention_days.
 *   2. Null payload columns on rows older than payload_retention_days.
 *   3. Delete oldest rows beyond the max_rows global cap.
 * All deletes and updates are chunked to avoid long locks on MySQL.
 */
class PruneApiRequestLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $payloadCutoff = now()->subDays((int) config('api.logging.payload_retention_days', 14));
        $rowCutoff = now()->subDays((int) config('api.logging.retention_days', 90));
        $maxRows = (int) config('api.logging.max_rows', 5_000_000);

        // 1. Delete rows past retention, chunked. Done first so the payload-null
        //    pass below has strictly fewer rows to touch (a >retention_days row
        //    also matches the <payload_retention_days predicate).
        do {
            $deleted = ApiRequestLog::where('created_at', '<', $rowCutoff)
                ->limit(10_000)->delete();
        } while ($deleted > 0);

        // 2. Strip payloads from old rows (kept as metadata), chunked. Each
        //    iteration nulls up to 10k still-matching rows; a nulled row no
        //    longer matches the whereNotNull group, so the loop drains.
        do {
            $updated = ApiRequestLog::where('created_at', '<', $payloadCutoff)
                ->where(function ($q) {
                    $q->whereNotNull('request_body')
                        ->orWhereNotNull('response_body')
                        ->orWhereNotNull('request_headers')
                        ->orWhereNotNull('query');
                })
                ->toBase()
                ->limit(10_000)
                ->update([
                    'request_body' => null,
                    'response_body' => null,
                    'request_headers' => null,
                    'query' => null,
                ]);
        } while ($updated > 0);

        // 3. Global row cap — delete oldest beyond the cap, chunked.
        $total = ApiRequestLog::count();
        if ($total > $maxRows) {
            $excess = $total - $maxRows;
            while ($excess > 0) {
                $batch = min($excess, 10_000);
                $cutIds = ApiRequestLog::orderBy('id')->limit($batch)->pluck('id');
                if ($cutIds->isEmpty()) {
                    break;
                }
                ApiRequestLog::whereIn('id', $cutIds)->delete();
                $excess -= $cutIds->count();
            }
        }
    }
}
