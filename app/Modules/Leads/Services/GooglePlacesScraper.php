<?php

namespace App\Modules\Leads\Services;

use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadScrapeJob;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scrapes Google Places API (Text Search / Nearby Search) and populates the leads table.
 * Uses the system-level Google Places API key from CredentialResolver (workspace → system).
 */
class GooglePlacesScraper
{
    private const PLACES_URL = 'https://maps.googleapis.com/maps/api/place/textsearch/json';

    private const DETAILS_URL = 'https://maps.googleapis.com/maps/api/place/details/json';

    /** Text Search never returns more than 3 pages / 60 results. */
    private const MAX_PAGES = 3;

    /**
     * next_page_token is not valid the instant it is issued. Google gives no
     * signal other than INVALID_REQUEST, so poll with backoff instead of one
     * fixed sleep.
     */
    private const TOKEN_RETRY_DELAYS = [2, 3, 5];

    /** Statuses that mean "no data", not "broken". */
    private const EMPTY_STATUSES = ['ZERO_RESULTS', 'NOT_FOUND'];

    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly PipelineManager $pipeline,
        private readonly ActivityRecorder $activity,
    ) {}

    public function run(LeadScrapeJob $job): void
    {
        $job->update(['status' => 'running', 'started_at' => now()]);

        // Declared outside the try so a mid-run failure can still report the
        // leads already written — a partial scrape must not look like an empty one.
        $created = 0;
        $seen = 0;

        // Hoisted so the catch can strip it out of the error text below.
        $apiKey = null;

        try {
            $apiKey = $this->credentials->googlePlaces()?->apiKey();
            if (! $apiKey) {
                throw new \RuntimeException('Google Places API key not configured.');
            }

            $query = "{$job->keyword} in {$job->location}";
            $pageToken = null;
            $page = 0;

            // Resolved once per run: stagesFor() both queries and back-fills, so
            // asking per place would repeat that work up to 60 times.
            $stage = $this->pipeline->firstStage((int) $job->workspace_id);

            do {
                $params = ['query' => $query, 'key' => $apiKey];
                if ($pageToken) {
                    $params['pagetoken'] = $pageToken;
                }

                $res = $this->places($params, $pageToken !== null);
                $places = $res['results'] ?? [];

                foreach ($places as $place) {
                    $seen++;
                    $created += $this->upsertPlace($job->workspace_id, $place, $apiKey, $stage);
                }

                $pageToken = $res['next_page_token'] ?? null;
                $page++;
            } while ($pageToken && $page < self::MAX_PAGES);

            $job->update([
                'status' => 'done',
                'leads_found' => $seen,
                'error' => null,
                'completed_at' => now(),
            ]);

            // Bill only leads new to this workspace — re-scraping the same query
            // must not burn credits a second time.
            if ($created > 0) {
                UsageMeter::track($job->workspace_id, 'lead_credits', $created);
            }
        } catch (\Throwable $e) {
            $message = $this->redactKey($e->getMessage(), $apiKey);

            Log::error('GooglePlacesScraper error: '.$message, [
                'scrape_job_id' => $job->id,
                'workspace_id' => $job->workspace_id,
            ]);
            $job->update([
                'status' => 'failed',
                'leads_found' => $seen,
                'error' => $message,
                'completed_at' => now(),
            ]);

            // Leads written before the failure are real and still owed.
            if ($created > 0) {
                UsageMeter::track($job->workspace_id, 'lead_credits', $created);
            }
        }
    }

    /**
     * Text Search request. Google answers HTTP 200 for its own errors, so the
     * body's `status` is the only real signal — without this an expired key or
     * exhausted quota looks exactly like a search with no matches.
     *
     * @param  bool  $isPagedRequest  paged requests tolerate INVALID_REQUEST while the token settles
     */
    private function places(array $params, bool $isPagedRequest): array
    {
        $attempts = $isPagedRequest ? self::TOKEN_RETRY_DELAYS : [null];

        foreach ($attempts as $i => $delay) {
            if ($delay !== null) {
                sleep($delay);
            }

            $res = $this->http()->get(self::PLACES_URL, $params)->throw()->json();
            $status = $res['status'] ?? 'UNKNOWN';

            if ($status === 'OK') {
                return $res;
            }

            if (in_array($status, self::EMPTY_STATUSES, true)) {
                return [];
            }

            // Token may still be settling; retry until the delays run out.
            if ($status === 'INVALID_REQUEST' && $isPagedRequest && $i < count($attempts) - 1) {
                continue;
            }

            throw new \RuntimeException($this->describe($status, $res));
        }

        return [];
    }

    private function describe(string $status, array $res): string
    {
        $detail = $res['error_message'] ?? null;

        $hint = match ($status) {
            'REQUEST_DENIED' => 'Google rejected the API key (check the key, its referrer/IP restrictions, and that Places API + billing are enabled).',
            'OVER_QUERY_LIMIT' => 'Google Places quota exceeded or billing not enabled for this key.',
            'INVALID_REQUEST' => 'Google rejected the request parameters.',
            default => "Google Places returned status {$status}.",
        };

        return $detail ? "{$hint} Google said: {$detail}" : $hint;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->connectTimeout(5)->retry(2, 500, throw: false);
    }

    /**
     * The key travels as a query parameter, and Guzzle puts the whole failed URL
     * in its message ("cURL error 6: ... for https://…?query=x&key=SECRET").
     * That message is stored on the scrape job and shipped to the tenant's
     * browser with the job list, so one network blip would hand every tenant the
     * install's shared Google key.
     */
    private function redactKey(string $message, ?string $apiKey): string
    {
        if ($apiKey) {
            $message = str_replace($apiKey, '[redacted]', $message);
        }

        // Belt and braces: catches a key from another source (a stale config, a
        // nested exception) that never passed through $apiKey.
        return (string) preg_replace('/([?&]key=)[^&\s"\']+/i', '$1[redacted]', $message);
    }

    /** @return int 1 if the lead is new to this workspace, else 0 */
    private function upsertPlace(int $workspaceId, array $place, string $apiKey, ?LeadPipelineStage $stage): int
    {
        $placeId = $place['place_id'] ?? null;
        if (! $placeId) {
            return 0;
        }

        $details = $this->details($placeId, $apiKey);
        $geometry = $place['geometry']['location'] ?? [];

        // Keyed on workspace_id too: the same business can legitimately be a lead
        // in more than one workspace, and keying on place_id alone reassigns the
        // existing row to whichever tenant scraped it last.
        $lead = Lead::updateOrCreate(
            ['workspace_id' => $workspaceId, 'google_place_id' => $placeId],
            [
                'name' => $place['name'] ?? null,
                'phone' => $details['formatted_phone_number'] ?? null,
                'website' => $details['website'] ?? null,
                'address' => $place['formatted_address'] ?? null,
                'category' => implode(', ', array_slice($place['types'] ?? [], 0, 3)),
                'rating' => $place['rating'] ?? null,
                'review_count' => $place['user_ratings_total'] ?? 0,
                'lat' => $geometry['lat'] ?? null,
                'lng' => $geometry['lng'] ?? null,
            ]
        );

        // Only a brand-new lead is filed into the first stage. Re-scraping a query
        // must not drag a lead the tenant has already worked back to the start.
        if ($lead->wasRecentlyCreated) {
            if ($stage) {
                $lead->update([
                    'stage_id' => $stage->id,
                    'stage_changed_at' => now(),
                    'board_position' => (int) Lead::where('workspace_id', $workspaceId)
                        ->where('stage_id', $stage->id)->max('board_position') + 1,
                ]);
            }

            // Opens the lead's history with how it got here.
            $this->activity->created($lead, 'google_places');
        }

        // Rescore either way — a re-scrape can bring a new rating or phone number.
        $this->pipeline->rescore($lead);

        return $lead->wasRecentlyCreated ? 1 : 0;
    }

    /**
     * Details supplies the phone number. One failure must not sink the run —
     * a lead without a phone still beats no lead.
     */
    private function details(string $placeId, string $apiKey): array
    {
        try {
            $res = $this->http()->get(self::DETAILS_URL, [
                'place_id' => $placeId,
                'fields' => 'formatted_phone_number,website,url',
                'key' => $apiKey,
            ])->throw()->json();

            if (($res['status'] ?? null) !== 'OK') {
                Log::warning('GooglePlacesScraper details lookup failed', [
                    'place_id' => $placeId,
                    'status' => $res['status'] ?? 'UNKNOWN',
                ]);

                return [];
            }

            return $res['result'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('GooglePlacesScraper details lookup errored: '.$this->redactKey($e->getMessage(), $apiKey), [
                'place_id' => $placeId,
            ]);

            return [];
        }
    }
}
