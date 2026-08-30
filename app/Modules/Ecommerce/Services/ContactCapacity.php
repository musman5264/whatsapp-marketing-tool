<?php

namespace App\Modules\Ecommerce\Services;

use App\Models\Workspace;
use App\Modules\Shared\Models\Contact;

/**
 * Resolves how many more contacts a workspace may create, based on the plan's
 * optional `max_contacts` limit. Used to stop a bulk store sync from ballooning
 * the contacts table past plan limits.
 *
 * Returns null = unlimited (no `max_contacts` set), preserving existing behavior
 * for plans that don't define the limit.
 */
class ContactCapacity
{
    public function remaining(int $workspaceId): ?int
    {
        $limit = Workspace::with('client')->find($workspaceId)
            ?->client?->activePlan()?->limitValue('max_contacts');

        if ($limit === null) {
            return null;
        }

        $current = Contact::where('workspace_id', $workspaceId)->count();

        return max(0, (int) $limit - $current);
    }
}
