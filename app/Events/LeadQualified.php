<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a lead's score crosses up into the hot band — not on every rescore,
 * so an automation on this trigger fires once per qualification rather than on
 * every board refresh.
 *
 * contactId is null until the lead is pushed to contacts; see LeadStageChanged.
 */
class LeadQualified
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $leadId,
        public readonly ?int $contactId,
        public readonly int $score,
        public readonly string $band,
    ) {}

    /** @return array<string, string> flat map consumed by {{context.x}} message tokens */
    public function context(): array
    {
        return [
            'lead_id' => (string) $this->leadId,
            'lead_score' => (string) $this->score,
            'lead_band' => $this->band,
        ];
    }
}
