<?php

namespace App\Modules\Leads\Services;

use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use Illuminate\Support\Facades\Auth;

/**
 * Single way in for lead follow-up history. Everything that writes a timeline
 * entry also refreshes the lead's last_activity_at, so the two can never drift.
 */
class ActivityRecorder
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  int|false|null  $userId  false = fall back to the logged-in user;
     *                                  null = deliberately a system entry
     */
    public function record(Lead $lead, string $type, ?string $body = null, array $meta = [], int|false|null $userId = false): LeadActivity
    {
        // A queued job has no authenticated user, so Auth::id() is null there and
        // the entry is correctly attributed to the system.
        $actor = $userId === false ? Auth::id() : $userId;
        $now = now();

        $activity = LeadActivity::create([
            'workspace_id' => $lead->workspace_id,
            'lead_id' => $lead->id,
            'user_id' => $actor,
            'type' => $type,
            'body' => $body,
            'meta' => $meta ?: null,
            'occurred_at' => $now,
        ]);

        // Denormalised onto the lead so the board can show staleness without
        // loading every lead's timeline.
        $lead->forceFill(['last_activity_at' => $now])->saveQuietly();

        return $activity;
    }

    public function stageChanged(Lead $lead, ?string $from, string $to): LeadActivity
    {
        return $this->record($lead, 'stage_changed', null, ['from' => $from, 'to' => $to]);
    }

    public function qualified(Lead $lead, int $score): LeadActivity
    {
        // Scoring runs in the scraper queue as well as on demand, so never assume
        // a user is behind it.
        return $this->record($lead, 'qualified', null, ['score' => $score], null);
    }

    public function created(Lead $lead, ?string $source = null): LeadActivity
    {
        return $this->record($lead, 'created', null, array_filter(['source' => $source]), null);
    }

    public function pushedToContacts(Lead $lead, int $contactId): LeadActivity
    {
        return $this->record($lead, 'pushed_to_contacts', null, ['contact_id' => $contactId]);
    }
}
