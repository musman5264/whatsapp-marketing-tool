<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\ContactService;
use App\Modules\Shared\Services\SegmentResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LaunchCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $campaignId) {}

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign || $campaign->status !== 'queued') {
            return;
        }

        $campaign->update(['status' => 'sending']);

        $contactIds = $this->resolveAudience($campaign);

        if (empty($contactIds)) {
            $campaign->update([
                'status' => 'failed',
                'totals_json' => array_merge($campaign->totals_json ?? [], [
                    'total' => 0,
                    'failed_reason' => 'No matching contacts for audience.',
                ]),
            ]);

            Log::channel('json')->warning('campaign.launch.empty_audience', [
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->id,
                'audience_type' => $campaign->audience_type,
                'audience_ref' => $campaign->audience_ref,
            ]);

            return;
        }

        // Idempotent recipient insert. Unique (campaign_id, contact_id) index enforces this.
        $now = now();
        $totalChunks = 0;
        $totalNewContacts = 0;

        collect($contactIds)->chunk(1000)->each(function ($chunk, $i) use ($campaign, $now, &$totalChunks, &$totalNewContacts) {
            $rows = $chunk->map(fn ($contactId) => [
                'campaign_id' => $campaign->id,
                'contact_id' => $contactId,
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all();

            // insertOrIgnore is required so re-launching a paused campaign
            // doesn't violate the unique (campaign_id, contact_id) index.
            CampaignRecipient::insertOrIgnore($rows);

            // Re-query rows that are still queued for this campaign in this chunk only.
            $contactIdsInChunk = $chunk->values()->all();
            $queuedContactIds = CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('status', 'queued')
                ->whereIn('contact_id', $contactIdsInChunk)
                ->pluck('contact_id')
                ->all();

            if (empty($queuedContactIds)) {
                return;
            }

            $totalNewContacts += count($queuedContactIds);
            $totalChunks++;

            DispatchCampaignChunkJob::dispatch($campaign->id, $queuedContactIds)
                ->onQueue('broadcast')
                ->delay(now()->addSeconds($i * 5));
        });

        if ($totalNewContacts === 0) {
            // All recipients were already processed previously (resume of completed campaign).
            $campaign->updateTotals();

            return;
        }

        // Schedule a finalizer 60s after the last chunk's expected dispatch start.
        // It will self-reschedule if anything is still queued/sending.
        $finalDelay = max(60, $totalChunks * 5 + 60);
        FinalizeCampaignJob::dispatch($campaign->id)
            ->onQueue('broadcast')
            ->delay(now()->addSeconds($finalDelay));
    }

    /**
     * Resolve audience to a list of contact IDs that are eligible for this campaign's channel.
     *
     * @return array<int, int>
     */
    private function resolveAudience(Campaign $campaign): array
    {
        $contactIds = match ($campaign->audience_type) {
            'segment' => $this->resolveSegment($campaign),
            'tag' => $this->resolveTag($campaign),
            'contact_list' => $this->resolveAllContacts($campaign),
            'csv' => $this->resolveCsv($campaign),
            default => [],
        };

        if (empty($contactIds)) {
            return [];
        }

        // Apply per-channel opt-in filter so we never blast contacts who opted out.
        $optInColumn = match ($campaign->channel) {
            'whatsapp' => 'opt_in_whatsapp',
            'sms' => 'opt_in_sms',
            'email' => 'opt_in_email',
            default => null,
        };

        $query = Contact::query()
            ->where('workspace_id', $campaign->workspace_id)
            ->whereIn('id', $contactIds);

        if ($optInColumn) {
            $query->where($optInColumn, true);
        }

        // Email channel additionally requires a non-empty email; SMS/WhatsApp require a phone.
        if ($campaign->channel === 'email') {
            $query->whereNotNull('email')->where('email', '!=', '');
        } else {
            $query->whereNotNull('phone_e164')->where('phone_e164', '!=', '');
        }

        return $query->pluck('id')->all();
    }

    /** @return array<int, int> */
    private function resolveSegment(Campaign $campaign): array
    {
        $segment = Segment::where('workspace_id', $campaign->workspace_id)
            ->find($campaign->audience_ref);

        if (! $segment) {
            return [];
        }

        if ($segment->type === 'static') {
            return $segment->contacts()->pluck('contacts.id')->all();
        }

        return app(SegmentResolver::class)
            ->query($segment)
            ->pluck('id')
            ->all();
    }

    /** @return array<int, int> */
    private function resolveTag(Campaign $campaign): array
    {
        if (! $campaign->audience_ref) {
            return [];
        }

        return Contact::where('workspace_id', $campaign->workspace_id)
            ->whereHas('tags', fn ($q) => $q->where('contact_tags.id', $campaign->audience_ref))
            ->pluck('id')
            ->all();
    }

    /** @return array<int, int> */
    private function resolveAllContacts(Campaign $campaign): array
    {
        // Use a lazy cursor instead of plucking every ID at once — workspaces
        // with millions of contacts would otherwise exhaust PHP memory.
        $ids = [];
        Contact::where('workspace_id', $campaign->workspace_id)
            ->select('id')
            ->orderBy('id')
            ->lazy(2000)
            ->each(function (Contact $c) use (&$ids) {
                $ids[] = $c->id;
            });

        return $ids;
    }

    /**
     * Read the CSV at `audience_ref` from the default storage disk and upsert contacts.
     * Expects a header row with at least `phone_e164` or `email`.
     *
     * @return array<int, int>
     */
    private function resolveCsv(Campaign $campaign): array
    {
        $path = $campaign->audience_ref;

        // Reject paths that could escape the expected storage area.
        // realpath-style normalisation isn't available before the file is read,
        // so we block directory-traversal sequences and absolute paths explicitly.
        if (
            $path &&
            (str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, '\\'))
        ) {
            Log::channel('json')->warning('campaign.csv.invalid_path', [
                'campaign_id' => $campaign->id,
                'path'        => $path,
            ]);

            return [];
        }

        if (! $path || ! Storage::exists($path)) {
            Log::channel('json')->warning('campaign.csv.missing', [
                'campaign_id' => $campaign->id,
                'path' => $path,
            ]);

            return [];
        }

        $rows = [];
        $handle = fopen(Storage::path($path), 'r');
        if (! $handle) {
            return [];
        }

        $header = null;
        try {
            while (($line = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = array_map(fn ($h) => trim(strtolower((string) $h)), $line);

                    continue;
                }
                $rows[] = array_combine(
                    $header,
                    array_pad($line, count($header), null),
                );
            }
        } finally {
            fclose($handle);
        }

        if (empty($rows)) {
            return [];
        }

        $service = app(ContactService::class);
        $contactIds = [];
        foreach ($rows as $row) {
            try {
                $contact = $service->upsert($campaign->workspace_id, [
                    'phone_e164' => $row['phone_e164'] ?? $row['phone'] ?? null,
                    'email' => $row['email'] ?? null,
                    'first_name' => $row['first_name'] ?? null,
                    'last_name' => $row['last_name'] ?? null,
                    'country' => $row['country'] ?? null,
                    'language' => $row['language'] ?? null,
                    'opt_in_whatsapp' => $this->coerceBool($row['opt_in_whatsapp'] ?? $row['opt_in_wa'] ?? true),
                    'opt_in_sms' => $this->coerceBool($row['opt_in_sms'] ?? true),
                    'opt_in_email' => $this->coerceBool($row['opt_in_email'] ?? true),
                    'source' => 'campaign_csv',
                ]);
                $contactIds[] = $contact->id;
            } catch (\Throwable $e) {
                Log::channel('json')->info('campaign.csv.row_failed', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return array_values(array_unique($contactIds));
    }

    private function coerceBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'y', 'on'], true);
    }
}
