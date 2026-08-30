<?php

namespace App\Modules\Leads\Jobs;

use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Rescores every lead in a workspace.
 *
 * Queued rather than run in the request: a workspace can hold tens of thousands
 * of leads, and each one is an update plus possible qualification event. Doing
 * that inline would hold a web worker open past the PHP time limit and hand the
 * tenant a 504 halfway through a partially-rescored board.
 */
class RescoreWorkspaceLeadsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $workspaceId) {}

    /** One rescore per workspace in flight; a double-click must not double the work. */
    public function uniqueId(): string
    {
        return (string) $this->workspaceId;
    }

    public function handle(PipelineManager $pipeline): void
    {
        $count = $pipeline->rescoreWorkspace($this->workspaceId);

        Log::info('Rescored workspace leads', ['workspace_id' => $this->workspaceId, 'count' => $count]);
    }
}
