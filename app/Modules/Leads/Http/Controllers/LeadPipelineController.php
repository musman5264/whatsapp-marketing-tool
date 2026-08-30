<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Jobs\RescoreWorkspaceLeadsJob;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadScoringConfig;
use App\Modules\Leads\Services\ActivityRecorder;
use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeadPipelineController extends Controller
{
    /**
     * Cards fetched per column. Beyond this the column reports its true total and
     * the tenant narrows with search or the band/due filters — a kanban that
     * renders 5,000 cards is unusable long before it is slow.
     */
    private const CARDS_PER_COLUMN = 50;

    public function __construct(
        private readonly PipelineManager $pipeline,
        private readonly ActivityRecorder $activity,
    ) {}

    /** Board query with the tenant's filters applied — the shape both the counts and each column need. */
    private function filtered(int $wid, array $filters): Builder
    {
        $query = Lead::where('workspace_id', $wid);

        if (! empty($filters['band'])) {
            $query->where('score_band', $filters['band']);
        }

        if (! empty($filters['due'])) {
            $query->followUpDue();
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('category', 'like', $term));
        }

        return $query;
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    /** Every stage/lead lookup goes through this, so a cross-workspace id 404s. */
    private function stage(Request $request, int $stageId): LeadPipelineStage
    {
        return LeadPipelineStage::where('workspace_id', $this->workspaceId($request))
            ->findOrFail($stageId);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $stages = $this->pipeline->stagesFor($wid);

        $filters = $request->validate([
            'band' => ['nullable', Rule::in(['hot', 'warm', 'cold'])],
            'search' => ['nullable', 'string', 'max:128'],
            'due' => ['nullable', 'boolean'],
            'lead' => ['nullable', 'integer'],
        ]);

        // A workspace accumulates leads forever (60 per scrape, no cap), so the
        // board must never fetch the lot: thousands of rows would be thousands of
        // DOM cards and a multi-megabyte payload. Each column shows its top slice
        // and reports its true size.
        $matching = fn () => $this->filtered($wid, $filters);

        $totals = $matching()
            ->selectRaw('stage_id, count(*) as aggregate')
            ->groupBy('stage_id')
            ->pluck('aggregate', 'stage_id');

        // Header counts must reflect the whole board, not the rendered slice —
        // summing capped columns would undercount. Cheap: both hit indexes.
        $boardTotal = (int) $totals->sum();
        $hotTotal = (int) $matching()->where('score_band', 'hot')->count();

        return Inertia::render('Leads/Pipeline', [
            // One query per column, and columns are few — bounded, unlike a query
            // per lead.
            'stages' => $stages->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'color' => $s->color,
                'position' => $s->position,
                'is_won' => $s->is_won,
                'is_lost' => $s->is_lost,
                'total' => (int) ($totals[$s->id] ?? 0),
                'leads' => $matching()
                    ->where('stage_id', $s->id)
                    ->with('contact:id,lead_id')
                    ->boardOrdered()
                    ->limit(self::CARDS_PER_COLUMN)
                    ->get(),
            ]),
            'perColumn' => self::CARDS_PER_COLUMN,
            'boardTotal' => $boardTotal,
            'hotTotal' => $hotTotal,
            'colors' => LeadPipelineStage::COLORS,
            'filters' => $filters,
            'scoring' => $this->scoringPayload($wid),
            'activityTypes' => LeadActivity::MANUAL_TYPES,
            'callOutcomes' => LeadActivity::OUTCOMES,

            // Only ever one lead's history, never all 50+. Null without ?lead, so a
            // plain board load costs nothing — and a regular prop (rather than an
            // optional one) means ?lead=5 also opens the modal on a cold page load,
            // which keeps the URL shareable.
            'leadDetail' => $this->leadDetail($wid, $request->integer('lead') ?: null),
        ]);
    }

    /** One lead plus its follow-up history, for the detail modal. */
    private function leadDetail(int $wid, ?int $leadId): ?array
    {
        if (! $leadId) {
            return null;
        }

        $lead = Lead::where('workspace_id', $wid)
            ->with(['stage:id,name', 'contact:id,lead_id', 'activities.user:id,name'])
            ->find($leadId);

        if (! $lead) {
            return null;
        }

        // Arr::only over toArray(), never $lead->only(): only() reads raw
        // attributes via getAttribute() and so skips MasksDemoData, which masks in
        // toArray(). Using it here would hand the public demo real phone numbers
        // and addresses that the board itself masks.
        return [
            'lead' => Arr::only($lead->toArray(), [
                'id', 'name', 'phone', 'email', 'website', 'address', 'category',
                'rating', 'review_count', 'score', 'score_band', 'score_breakdown',
                'stage_id', 'next_follow_up_at', 'last_activity_at',
            ]) + ['contact' => $lead->contact ? ['id' => $lead->contact->id] : null],
            'activities' => $lead->activities->map(fn (LeadActivity $a) => Arr::only($a->toArray(), [
                'id', 'type', 'body', 'meta', 'occurred_at',
            ]) + [
                'is_system' => $a->isSystem(),
                'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
            ])->values(),
        ];
    }

    /**
     * Log a follow-up. The stage change rides along in the same request because
     * the call IS the reason the lead moved — two round trips would let one half
     * land without the other.
     */
    public function storeActivity(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless((int) $lead->workspace_id === $this->workspaceId($request), 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(LeadActivity::MANUAL_TYPES)],
            'body' => ['nullable', 'string', 'max:2000'],
            'outcome' => ['nullable', Rule::in(LeadActivity::OUTCOMES)],
            'next_follow_up_at' => ['nullable', 'date'],
            'clear_follow_up' => ['nullable', 'boolean'],
            'stage_id' => ['nullable', 'integer'],
        ]);

        // A note with no words and no outcome records nothing.
        if ($data['type'] === 'note' && blank($data['body'] ?? null)) {
            throw ValidationException::withMessages([
                'body' => __('Add a note before saving.'),
            ]);
        }

        $meta = array_filter(['outcome' => $data['outcome'] ?? null]);

        $this->activity->record($lead, $data['type'], $data['body'] ?? null, $meta);

        if (! empty($data['clear_follow_up'])) {
            $lead->update(['next_follow_up_at' => null]);
        } elseif (! empty($data['next_follow_up_at'])) {
            $lead->update(['next_follow_up_at' => $data['next_follow_up_at']]);
        }

        if (! empty($data['stage_id']) && (int) $data['stage_id'] !== (int) $lead->stage_id) {
            $stage = $this->stage($request, (int) $data['stage_id']);
            $this->pipeline->moveLead($lead, $stage, 0);
        }

        return back()->with('success', __('Activity logged.'));
    }

    public function move(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless((int) $lead->workspace_id === $this->workspaceId($request), 403);

        $data = $request->validate([
            'stage_id' => ['required', 'integer'],
            'position' => ['required', 'integer', 'min:0'],
        ]);

        $this->pipeline->moveLead($lead, $this->stage($request, $data['stage_id']), $data['position']);

        return back();
    }

    public function storeStage(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'color' => ['required', Rule::in(LeadPipelineStage::COLORS)],
            'is_won' => ['boolean'],
            'is_lost' => ['boolean'],
        ]);

        LeadPipelineStage::create([
            'workspace_id' => $wid,
            'name' => $data['name'],
            'color' => $data['color'],
            'is_won' => $data['is_won'] ?? false,
            'is_lost' => $data['is_lost'] ?? false,
            'position' => $this->pipeline->nextStagePosition($wid),
        ]);

        return back()->with('success', __('Stage created.'));
    }

    public function updateStage(Request $request, int $stage): RedirectResponse
    {
        $model = $this->stage($request, $stage);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'color' => ['required', Rule::in(LeadPipelineStage::COLORS)],
            'is_won' => ['boolean'],
            'is_lost' => ['boolean'],
        ]);

        $model->update($data);

        return back()->with('success', __('Stage updated.'));
    }

    public function destroyStage(Request $request, int $stage): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $model = $this->stage($request, $stage);

        // A board with no columns has nowhere to put leads and no way back.
        $fallback = LeadPipelineStage::where('workspace_id', $wid)
            ->where('id', '!=', $model->id)
            ->orderBy('position')
            ->first();

        if (! $fallback) {
            throw ValidationException::withMessages([
                'stage' => __('You cannot delete your only stage.'),
            ]);
        }

        $this->pipeline->deleteStage($model, $fallback);

        return back()->with('success', __('Stage deleted. Its leads moved to :stage.', ['stage' => $fallback->name]));
    }

    public function reorderStages(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        // Scope to this workspace's stages so a foreign id can't be renumbered.
        $owned = LeadPipelineStage::where('workspace_id', $wid)
            ->whereIn('id', $data['ids'])
            ->pluck('id')
            ->all();

        $ordered = array_values(array_filter($data['ids'], fn ($id) => in_array($id, $owned, true)));

        $this->pipeline->reorderStages($wid, $ordered);

        return back();
    }

    public function rescore(Request $request): RedirectResponse
    {
        RescoreWorkspaceLeadsJob::dispatch($this->workspaceId($request))->onQueue('leads');

        return back()->with('success', __('Rescoring your leads — the board will update shortly.'));
    }

    public function updateScoring(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $rules = ['thresholds.hot' => ['required', 'integer', 'min:1', 'max:100'],
            'thresholds.warm' => ['required', 'integer', 'min:0', 'max:100']];

        foreach (array_keys(LeadScoringConfig::DEFAULT_WEIGHTS) as $rule) {
            $rules["weights.{$rule}"] = ['required', 'integer', 'min:0', 'max:100'];
        }

        $data = $request->validate($rules);

        if ($data['thresholds']['warm'] > $data['thresholds']['hot']) {
            throw ValidationException::withMessages([
                'thresholds.warm' => __('The warm threshold cannot be higher than the hot threshold.'),
            ]);
        }

        if (array_sum($data['weights']) === 0) {
            throw ValidationException::withMessages([
                'weights' => __('At least one rule must have a weight above zero.'),
            ]);
        }

        $config = LeadScoringConfig::forWorkspace($wid);
        $config->fill(['workspace_id' => $wid, 'weights' => $data['weights'], 'thresholds' => $data['thresholds']]);
        $config->save();

        // Weights that no longer match the stored scores would leave the board
        // showing bands from the previous formula.
        RescoreWorkspaceLeadsJob::dispatch($wid)->onQueue('leads');

        return back()->with('success', __('Scoring settings saved. Your leads are being rescored.'));
    }

    /** @return array{weights: array<string, int>, thresholds: array<string, int>, rules: list<string>} */
    private function scoringPayload(int $wid): array
    {
        $config = LeadScoringConfig::forWorkspace($wid);

        return [
            'weights' => $config->effectiveWeights(),
            'thresholds' => $config->effectiveThresholds(),
            'rules' => array_keys(LeadScoringConfig::DEFAULT_WEIGHTS),
        ];
    }
}
