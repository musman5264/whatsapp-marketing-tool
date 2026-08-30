<?php

namespace Tests\Feature\MarketingSuite;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Leads\Jobs\RescoreWorkspaceLeadsJob;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadScrapeJob;
use App\Modules\Leads\Services\GooglePlacesScraper;
use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Production-readiness guards for lead management: demo-mode PII, secret leakage,
 * query cost, and referential integrity.
 */
class LeadProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);
        $user->refresh();

        return [$user, $workspace];
    }

    private function stages(Workspace $w)
    {
        return app(PipelineManager::class)->stagesFor($w->id);
    }

    // ── Demo mode: PII must not reach the browser ────────────────────────────

    #[Test]
    public function demo_mode_masks_lead_pii_in_the_detail_modal(): void
    {
        config(['app.demo_mode' => true]);
        [$user, $workspace] = $this->actor();

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'stage_id' => $this->stages($workspace)->first()->id,
            'name' => 'Serenity Spa & Salon',
            'phone' => '+8801712345678',
            'email' => 'owner@serenityspa.com',
            'address' => '12 Gulshan Avenue, Dhaka',
        ]);

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['lead' => $lead->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('leadDetail.lead.phone', fn ($v) => $v !== '+8801712345678')
                ->where('leadDetail.lead.email', fn ($v) => $v !== 'owner@serenityspa.com')
            );
    }

    #[Test]
    public function demo_mode_scrubs_pii_out_of_follow_up_notes(): void
    {
        config(['app.demo_mode' => true]);
        [$user, $workspace] = $this->actor();
        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'stage_id' => $this->stages($workspace)->first()->id,
        ]);

        LeadActivity::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'type' => 'note',
            'body' => 'Rang +8801712345678, email owner@serenityspa.com for pricing.',
            'occurred_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['lead' => $lead->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('leadDetail.activities.0.body', function ($body) {
                    $this->assertStringNotContainsString('8801712345678', $body);
                    $this->assertStringNotContainsString('owner@serenityspa.com', $body);

                    return true;
                })
            );
    }

    #[Test]
    public function outside_demo_mode_the_real_details_are_returned(): void
    {
        config(['app.demo_mode' => false]);
        [$user, $workspace] = $this->actor();
        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'stage_id' => $this->stages($workspace)->first()->id,
            'phone' => '+8801712345678',
        ]);

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['lead' => $lead->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('leadDetail.lead.phone', '+8801712345678'));
    }

    // ── Secrets must never reach the tenant ──────────────────────────────────

    #[Test]
    public function a_connection_failure_never_stores_the_api_key_on_the_job(): void
    {
        [, $workspace] = $this->actor();
        $key = 'AIzaSyFAKE-super-secret-key-12345';

        IntegrationConfig::create([
            'provider' => 'google_places',
            'label' => IntegrationConfig::LABELS['google_places'],
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['api_key' => $key],
        ]);

        // Guzzle puts the whole failed URL — key and all — in its message.
        Http::fake(function () use ($key) {
            throw new ConnectionException(
                'cURL error 6: Could not resolve host: maps.googleapis.com for '
                ."https://maps.googleapis.com/maps/api/place/textsearch/json?query=spa&key={$key}"
            );
        });

        $job = LeadScrapeJob::create([
            'workspace_id' => $workspace->id,
            'keyword' => 'spa',
            'location' => 'Dhaka',
            'status' => 'pending',
        ]);

        app(GooglePlacesScraper::class)->run($job);
        $job->refresh();

        $this->assertSame('failed', $job->status);
        $this->assertStringNotContainsString($key, $job->error);
        $this->assertStringContainsString('[redacted]', $job->error);
    }

    #[Test]
    public function the_redacted_error_still_reaches_the_tenant_readably(): void
    {
        [$user, $workspace] = $this->actor();
        LeadScrapeJob::create([
            'workspace_id' => $workspace->id,
            'keyword' => 'spa',
            'location' => 'Dhaka',
            'status' => 'failed',
            'error' => 'Google rejected the API key (check billing).',
        ]);

        // The job list is serialized to the browser, which is exactly why the
        // error text must be safe to show.
        $this->actingAs($user)
            ->get(route('client.leads.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('scrapeJobs.0.error', 'Google rejected the API key (check billing).'));
    }

    // ── Query cost ───────────────────────────────────────────────────────────

    /** Queries run by $work, ignoring the framework's fixed per-request overhead. */
    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * Absolute counts are meaningless here — every page pays ~50 queries of
     * shared middleware overhead (settings, locales, currencies) regardless of
     * this module. What matters is that the cost does not grow with the data, so
     * measure the delta between a small and a large column.
     */
    #[Test]
    public function moving_a_lead_costs_the_same_whatever_the_column_size(): void
    {
        [$user, $workspace] = $this->actor();
        $stages = $this->stages($workspace);

        $move = function (int $columnSize) use ($user, $workspace, $stages) {
            Lead::where('workspace_id', $workspace->id)->delete();
            Lead::factory()->count($columnSize)->create(['workspace_id' => $workspace->id, 'stage_id' => $stages->get(1)->id]);
            $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => $stages->first()->id]);

            return $this->countQueries(fn () => $this->actingAs($user)
                ->put(route('client.leads.pipeline.move', $lead->id), ['stage_id' => $stages->get(1)->id, 'position' => 0])
                ->assertStatus(302));
        };

        $small = $move(3);
        $large = $move(40);

        // The old version renumbered every card in the destination column, so this
        // delta was ~37. It must be flat.
        $this->assertLessThanOrEqual(
            $small + 2,
            $large,
            "Moving into a 40-card column ran {$large} queries vs {$small} for a 3-card column — cost scales with column size."
        );
    }

    #[Test]
    public function the_board_does_not_query_per_lead(): void
    {
        [$user, $workspace] = $this->actor();
        $stage = $this->stages($workspace)->first();

        $load = function (int $leads) use ($user, $workspace, $stage) {
            Lead::where('workspace_id', $workspace->id)->delete();
            Lead::factory()->count($leads)->create(['workspace_id' => $workspace->id, 'stage_id' => $stage->id]);

            return $this->countQueries(fn () => $this->actingAs($user)
                ->get(route('client.leads.pipeline.index'))->assertOk());
        };

        $small = $load(3);
        $large = $load(40);

        $this->assertLessThanOrEqual(
            $small + 2,
            $large,
            "Board load ran {$large} queries for 40 leads vs {$small} for 3 — an N+1 is scaling with lead count."
        );
    }

    #[Test]
    public function a_column_is_capped_but_still_reports_its_true_size(): void
    {
        [$user, $workspace] = $this->actor();
        $stage = $this->stages($workspace)->first();
        Lead::factory()->count(60)->create(['workspace_id' => $workspace->id, 'stage_id' => $stage->id]);

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // The payload is capped…
                ->where('stages.0.leads', fn ($leads) => collect($leads)->count() === 50)
                // …but the count the tenant reads is the real one.
                ->where('stages.0.total', 60)
            );
    }

    #[Test]
    public function a_capped_column_shows_the_highest_scoring_leads_first(): void
    {
        [$user, $workspace] = $this->actor();
        $stage = $this->stages($workspace)->first();

        Lead::factory()->count(55)->create([
            'workspace_id' => $workspace->id, 'stage_id' => $stage->id,
            'board_position' => 0, 'score' => 10, 'score_band' => 'cold',
        ]);
        $best = Lead::factory()->create([
            'workspace_id' => $workspace->id, 'stage_id' => $stage->id,
            'board_position' => 0, 'score' => 99, 'score_band' => 'hot', 'name' => 'Best Lead',
        ]);

        // Truncation must not hide the leads worth calling.
        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stages.0.leads.0.id', $best->id));
    }

    #[Test]
    public function the_header_totals_count_the_whole_board_not_the_rendered_slice(): void
    {
        [$user, $workspace] = $this->actor();
        $stage = $this->stages($workspace)->first();
        Lead::factory()->count(60)->create(['workspace_id' => $workspace->id, 'stage_id' => $stage->id, 'score_band' => 'hot']);

        // 60 > the 50-card cap: a client-side sum of the slice would report 50.
        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('boardTotal', 60)
                ->where('hotTotal', 60)
            );
    }

    #[Test]
    public function column_totals_respect_the_active_filter(): void
    {
        [$user, $workspace] = $this->actor();
        $stage = $this->stages($workspace)->first();
        Lead::factory()->count(4)->create(['workspace_id' => $workspace->id, 'stage_id' => $stage->id, 'score_band' => 'cold']);
        Lead::factory()->count(2)->create(['workspace_id' => $workspace->id, 'stage_id' => $stage->id, 'score_band' => 'hot']);

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['band' => 'hot']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stages.0.total', 2));
    }

    #[Test]
    public function opening_a_lead_does_not_query_per_activity(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'stage_id' => $this->stages($workspace)->first()->id,
        ]);

        $load = function (int $activities) use ($user, $workspace, $lead) {
            LeadActivity::where('lead_id', $lead->id)->delete();
            for ($i = 0; $i < $activities; $i++) {
                LeadActivity::create([
                    'workspace_id' => $workspace->id,
                    'lead_id' => $lead->id,
                    'user_id' => $user->id,
                    'type' => 'note',
                    'body' => "note {$i}",
                    'occurred_at' => now(),
                ]);
            }

            return $this->countQueries(fn () => $this->actingAs($user)
                ->get(route('client.leads.pipeline.index', ['lead' => $lead->id]))->assertOk());
        };

        $small = $load(2);
        $large = $load(30);

        // activities.user must stay eager-loaded — one query per author would be
        // an N+1 the moment a lead has real history.
        $this->assertLessThanOrEqual(
            $small + 2,
            $large,
            "Opening a lead with 30 activities ran {$large} queries vs {$small} with 2 — activities.user is N+1."
        );
    }

    // ── Long work belongs on the queue ───────────────────────────────────────

    #[Test]
    public function rescoring_a_workspace_is_queued_not_run_in_the_request(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->actor();

        $this->actingAs($user)->post(route('client.leads.pipeline.rescore'))->assertStatus(302);

        Queue::assertPushed(RescoreWorkspaceLeadsJob::class, fn ($job) => $job->workspaceId === $workspace->id);
    }

    #[Test]
    public function saving_scoring_settings_queues_a_rescore(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->actor();

        $this->actingAs($user)->put(route('client.leads.pipeline.scoring.update'), [
            'weights' => ['has_phone' => 40, 'rating' => 20, 'review_volume' => 20, 'has_website' => 20],
            'thresholds' => ['hot' => 70, 'warm' => 40],
        ])->assertStatus(302);

        Queue::assertPushed(RescoreWorkspaceLeadsJob::class, fn ($job) => $job->workspaceId === $workspace->id);
    }

    // ── Referential integrity ────────────────────────────────────────────────

    #[Test]
    public function deleting_a_stage_row_directly_does_not_lose_leads(): void
    {
        [$user, $workspace] = $this->actor();
        $stages = $this->stages($workspace);
        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => $stages->get(2)->id]);

        // Bypasses PipelineManager entirely — a bad import or a future code path.
        DB::table('lead_pipeline_stages')->where('id', $stages->get(2)->id)->delete();

        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
        $this->assertNull($lead->fresh()->stage_id, 'FK should null the stage, never delete the lead.');

        // And the orphan comes back on the board rather than vanishing.
        $this->actingAs($user)->get(route('client.leads.pipeline.index'))->assertOk();
        $this->assertSame((int) $stages->first()->id, (int) $lead->fresh()->stage_id);
    }

    #[Test]
    public function deleting_a_stage_through_the_ui_keeps_both_columns_in_order(): void
    {
        [$user, $workspace] = $this->actor();
        $stages = $this->stages($workspace);
        $keep = $stages->first();
        $doomed = $stages->get(1);

        $kept = Lead::factory()->count(3)->create(['workspace_id' => $workspace->id, 'stage_id' => $keep->id])
            ->each(fn ($l, $i) => $l->update(['board_position' => $i]));
        $moved = Lead::factory()->count(3)->create(['workspace_id' => $workspace->id, 'stage_id' => $doomed->id])
            ->each(fn ($l, $i) => $l->update(['board_position' => $i]));

        $this->actingAs($user)->delete(route('client.leads.pipeline.stages.destroy', $doomed->id))->assertStatus(302);

        // Merging two 0..2 columns must not collide — arrivals append after the kept.
        $positions = Lead::where('stage_id', $keep->id)->orderBy('board_position')->pluck('board_position');
        $this->assertSame(6, $positions->count());
        $this->assertSame($positions->unique()->count(), $positions->count(), 'board_position collided when merging columns.');
    }

    #[Test]
    public function deleting_the_author_keeps_their_history(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'stage_id' => $this->stages($workspace)->first()->id,
        ]);

        $this->actingAs($user)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'note', 'body' => 'Important context.',
        ])->assertStatus(302);

        $activity = LeadActivity::where('lead_id', $lead->id)->firstOrFail();

        // A staff member leaving must not erase what they recorded.
        DB::table('users')->where('id', $user->id)->delete();

        $activity->refresh();
        $this->assertSame('Important context.', $activity->body);
        $this->assertNull($activity->user_id);
    }

    #[Test]
    public function stage_defaults_are_seeded_once_per_workspace_even_when_hit_twice(): void
    {
        [, $workspace] = $this->actor();

        app(PipelineManager::class)->stagesFor($workspace->id);
        app(PipelineManager::class)->stagesFor($workspace->id);

        $this->assertSame(
            count(LeadPipelineStage::DEFAULTS),
            LeadPipelineStage::where('workspace_id', $workspace->id)->count()
        );
    }
}
