<?php

namespace App\Modules\Leads\Contracts;

use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\Scoring\LeadScore;

/**
 * Qualification strategy. Bound to RuleBasedLeadScorer in LeadsServiceProvider.
 *
 * This exists so an LLM-backed scorer can be swapped in (or layered on top of the
 * rule score) without the controllers, jobs or board knowing which one ran —
 * they only ever consume the LeadScore value object.
 */
interface LeadScorer
{
    public function score(Lead $lead): LeadScore;
}
