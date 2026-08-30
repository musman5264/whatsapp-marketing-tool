<?php

namespace App\Modules\Leads\Services;

use App\Events\LeadQualified;
use App\Events\LeadStageChanged;
use App\Modules\Leads\Contracts\LeadScorer;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadPipelineStage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PipelineManager
{
    public function __construct(
        private readonly LeadScorer $scorer,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Stages for a workspace, seeding the defaults on first use so the board is
     * never empty and no tenant has to build a pipeline before seeing one.
     *
     * @return Collection<int, LeadPipelineStage>
     */
    public function stagesFor(int $workspaceId): Collection
    {
        $stages = LeadPipelineStage::where('workspace_id', $workspaceId)
            ->orderBy('position')->orderBy('id')->get();

        if ($stages->isEmpty()) {
            foreach (LeadPipelineStage::DEFAULTS as $i => $default) {
                LeadPipelineStage::create($default + ['workspace_id' => $workspaceId, 'position' => $i]);
            }

            $stages = LeadPipelineStage::where('workspace_id', $workspaceId)
                ->orderBy('position')->orderBy('id')->get();
        }

        $this->adoptUnstagedLeads($workspaceId, $stages->first());

        return $stages;
    }

    /**
     * Leads scraped before the pipeline existed have no stage_id. Give them the
     * first stage for real, rather than letting the board merely draw them in
     * column one — otherwise the column count disagrees with the database, and
     * reordering the stages would silently relocate every one of them.
     *
     * board_position stays 0: scopeBoardOrdered falls back to score, which is the
     * order they should appear in anyway.
     */
    private function adoptUnstagedLeads(int $workspaceId, ?LeadPipelineStage $first): void
    {
        if (! $first) {
            return;
        }

        Lead::where('workspace_id', $workspaceId)
            ->whereNull('stage_id')
            ->update(['stage_id' => $first->id, 'stage_changed_at' => now()]);
    }

    public function firstStage(int $workspaceId): ?LeadPipelineStage
    {
        return $this->stagesFor($workspaceId)->first();
    }

    /**
     * Move a lead to a stage and slot it at $position within that column.
     *
     * Positions are only ever compared, never required to be gapless, so the two
     * statements below are the whole job — nudge everything at or after the target
     * index down, then drop the lead in. An earlier version also renumbered the
     * column to 0..n-1, which cost one UPDATE per card in it (56 per drag here,
     * unbounded as a tenant grows) and bought nothing.
     *
     * Fires LeadStageChanged only on an actual stage change — reordering within a
     * column is not a pipeline event.
     */
    public function moveLead(Lead $lead, LeadPipelineStage $stage, int $position): Lead
    {
        $from = $lead->stage_id ? $lead->stage->name ?? null : null;
        $changedStage = (int) $lead->stage_id !== (int) $stage->id;

        DB::transaction(function () use ($lead, $stage, $position, $changedStage) {
            // Open a gap at the target index in the destination column.
            Lead::where('workspace_id', $lead->workspace_id)
                ->where('stage_id', $stage->id)
                ->where('id', '!=', $lead->id)
                ->where('board_position', '>=', $position)
                ->increment('board_position');

            $lead->stage_id = $stage->id;
            $lead->board_position = $position;
            if ($changedStage) {
                $lead->stage_changed_at = now();
            }
            $lead->save();
        });

        if ($changedStage) {
            $this->activity->stageChanged($lead, $from, $stage->name);

            LeadStageChanged::dispatch(
                (int) $lead->workspace_id,
                (int) $lead->id,
                $lead->contact?->id,
                $from,
                $stage->name,
                $stage->is_won,
                $stage->is_lost,
            );
        }

        return $lead->refresh();
    }

    /**
     * Rescore a lead and persist the result. Returns true when the lead newly
     * became hot, which is what LeadQualified reports.
     */
    public function rescore(Lead $lead): bool
    {
        $wasHot = $lead->score_band === 'hot';
        $result = $this->scorer->score($lead);

        $lead->update([
            'score' => $result->score,
            'score_band' => $result->band,
            'score_breakdown' => $result->breakdown,
            'scored_at' => now(),
        ]);

        $becameHot = $result->band === 'hot' && ! $wasHot;

        if ($becameHot) {
            $this->activity->qualified($lead, $result->score);

            LeadQualified::dispatch(
                (int) $lead->workspace_id,
                (int) $lead->id,
                $lead->contact?->id,
                $result->score,
                $result->band,
            );
        }

        return $becameHot;
    }

    /** @return int number of leads rescored */
    public function rescoreWorkspace(int $workspaceId): int
    {
        $count = 0;

        Lead::where('workspace_id', $workspaceId)
            ->with('contact:id,lead_id')
            ->chunkById(200, function ($leads) use (&$count) {
                foreach ($leads as $lead) {
                    $this->rescore($lead);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Delete a stage, moving any leads it holds to $fallback so a tenant cannot
     * lose leads by tidying their board.
     */
    public function deleteStage(LeadPipelineStage $stage, LeadPipelineStage $fallback): void
    {
        DB::transaction(function () use ($stage, $fallback) {
            // Both columns number from 0, so a plain reassign would interleave the
            // two sets. Shifting the arrivals past the fallback's last card keeps
            // each group's own order and appends them — in one statement, whatever
            // the column size.
            //
            // lockForUpdate so two stages deleted concurrently into the same
            // fallback can't both read the same max and compute the same offset
            // (which would overlap their positions). Harmless if it does — order
            // is the only thing at stake — but the lock is free inside this
            // transaction.
            $offset = (int) Lead::where('workspace_id', $fallback->workspace_id)
                ->where('stage_id', $fallback->id)
                ->lockForUpdate()
                ->max('board_position') + 1;

            Lead::where('stage_id', $stage->id)->update([
                'stage_id' => $fallback->id,
                'board_position' => DB::raw('board_position + '.$offset),
            ]);

            $stage->delete();
        });
    }

    /** @param  list<int>  $orderedIds */
    public function reorderStages(int $workspaceId, array $orderedIds): void
    {
        DB::transaction(function () use ($workspaceId, $orderedIds) {
            foreach ($orderedIds as $position => $id) {
                LeadPipelineStage::where('workspace_id', $workspaceId)
                    ->where('id', $id)
                    ->update(['position' => $position]);
            }
        });
    }

    public function nextStagePosition(int $workspaceId): int
    {
        return (int) LeadPipelineStage::where('workspace_id', $workspaceId)->max('position') + 1;
    }
}
