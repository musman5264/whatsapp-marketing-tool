<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a lead moves between pipeline stages, by drag or by dropdown.
 *
 * contactId is null until the lead has been pushed to contacts. Automations act
 * on a contact, so a null here means there is nobody to run the automation
 * against — the listener skips rather than starting a run that cannot send.
 */
class LeadStageChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $leadId,
        public readonly ?int $contactId,
        public readonly ?string $fromStage,
        public readonly string $toStage,
        public readonly bool $isWon = false,
        public readonly bool $isLost = false,
    ) {}

    /** @return array<string, string> flat map consumed by {{context.x}} message tokens */
    public function context(): array
    {
        return [
            'lead_id' => (string) $this->leadId,
            'lead_stage_from' => (string) ($this->fromStage ?? ''),
            'lead_stage_to' => $this->toStage,
        ];
    }
}
