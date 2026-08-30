<?php

namespace Tests\Feature\MarketingSuite;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Leads\Contracts\LeadScorer;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadScoringConfig;
use App\Modules\Leads\Services\Scoring\RuleBasedLeadScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadScoringTest extends TestCase
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

    private function lead(Workspace $workspace, array $attrs = []): Lead
    {
        return Lead::factory()->create($attrs + ['workspace_id' => $workspace->id]);
    }

    private function scorer(): LeadScorer
    {
        return app(LeadScorer::class);
    }

    #[Test]
    public function the_contract_resolves_to_the_rule_based_scorer(): void
    {
        $this->assertInstanceOf(RuleBasedLeadScorer::class, $this->scorer());
    }

    #[Test]
    public function a_lead_maxing_every_rule_scores_100_and_is_hot(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace, [
            'phone' => '+8801700000001',
            'website' => 'https://example.com',
            'rating' => 5,
            'review_count' => 500,
        ]);

        $result = $this->scorer()->score($lead);

        $this->assertSame(100, $result->score);
        $this->assertSame('hot', $result->band);
    }

    #[Test]
    public function a_lead_with_no_signals_scores_zero_and_is_cold(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace, [
            'phone' => null,
            'website' => null,
            'rating' => null,
            'review_count' => 0,
        ]);

        $result = $this->scorer()->score($lead);

        $this->assertSame(0, $result->score);
        $this->assertSame('cold', $result->band);
    }

    #[Test]
    public function every_rule_reports_why_it_scored_what_it_did(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace, ['phone' => null, 'website' => 'https://example.com', 'rating' => 4.0, 'review_count' => 12]);

        $breakdown = collect($this->scorer()->score($lead)->breakdown);

        $this->assertSame(
            ['has_phone', 'rating', 'review_volume', 'has_website'],
            $breakdown->pluck('rule')->all()
        );

        // Every entry must carry a human reason and a max the UI can render a bar against.
        $breakdown->each(function ($row) {
            $this->assertNotEmpty($row['detail']);
            $this->assertGreaterThan(0, $row['max']);
            $this->assertLessThanOrEqual($row['max'], $row['points']);
        });

        $this->assertStringContainsString('cannot be messaged', $breakdown->firstWhere('rule', 'has_phone')['detail']);
        $this->assertSame(0, $breakdown->firstWhere('rule', 'has_phone')['points']);
        $this->assertStringContainsString('12 Google reviews', $breakdown->firstWhere('rule', 'review_volume')['detail']);
    }

    #[Test]
    public function a_phone_number_is_worth_more_than_a_website(): void
    {
        [, $workspace] = $this->actor();
        $base = ['rating' => null, 'review_count' => 0];

        $phoneOnly = $this->scorer()->score($this->lead($workspace, $base + ['phone' => '+8801700000001', 'website' => null]));
        $siteOnly = $this->scorer()->score($this->lead($workspace, $base + ['phone' => null, 'website' => 'https://example.com']));

        $this->assertGreaterThan($siteOnly->score, $phoneOnly->score);
    }

    #[Test]
    public function review_volume_scales_logarithmically_not_linearly(): void
    {
        [, $workspace] = $this->actor();
        $base = ['phone' => null, 'website' => null, 'rating' => null];

        $ten = $this->scorer()->score($this->lead($workspace, $base + ['review_count' => 10]))->score;
        $hundred = $this->scorer()->score($this->lead($workspace, $base + ['review_count' => 100]))->score;

        // Linear scaling would make 100 reviews worth 10x of 10 reviews; log scaling
        // keeps the first reviews worth the most.
        $this->assertGreaterThan($ten, $hundred);
        $this->assertLessThan($ten * 10, $hundred);
    }

    #[Test]
    public function review_volume_saturates_rather_than_overflowing_its_weight(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace, ['phone' => null, 'website' => null, 'rating' => null, 'review_count' => 100000]);

        $row = collect($this->scorer()->score($lead)->breakdown)->firstWhere('rule', 'review_volume');

        $this->assertSame($row['max'], $row['points']);
    }

    #[Test]
    public function custom_weights_change_the_score(): void
    {
        [, $workspace] = $this->actor();
        LeadScoringConfig::create([
            'workspace_id' => $workspace->id,
            // Phone is everything for this tenant.
            'weights' => ['has_phone' => 100, 'rating' => 0, 'review_volume' => 0, 'has_website' => 0],
            'thresholds' => LeadScoringConfig::DEFAULT_THRESHOLDS,
        ]);

        $phoneOnly = $this->lead($workspace, ['phone' => '+8801700000001', 'website' => null, 'rating' => null, 'review_count' => 0]);
        $noPhone = $this->lead($workspace, ['phone' => null, 'website' => 'https://example.com', 'rating' => 5, 'review_count' => 500]);

        $this->assertSame(100, $this->scorer()->score($phoneOnly)->score);
        $this->assertSame(0, $this->scorer()->score($noPhone)->score);
    }

    #[Test]
    public function weights_that_do_not_total_100_are_rescaled(): void
    {
        [, $workspace] = $this->actor();
        LeadScoringConfig::create([
            'workspace_id' => $workspace->id,
            'weights' => ['has_phone' => 5, 'rating' => 5, 'review_volume' => 5, 'has_website' => 5],
            'thresholds' => LeadScoringConfig::DEFAULT_THRESHOLDS,
        ]);

        $lead = $this->lead($workspace, ['phone' => '+8801700000001', 'website' => 'https://example.com', 'rating' => 5, 'review_count' => 500]);

        // Raw weights sum to 20, but a maxed lead must still score 100.
        $this->assertSame(100, $this->scorer()->score($lead)->score);
    }

    #[Test]
    public function custom_thresholds_change_the_band(): void
    {
        [, $workspace] = $this->actor();
        LeadScoringConfig::create([
            'workspace_id' => $workspace->id,
            'weights' => LeadScoringConfig::DEFAULT_WEIGHTS,
            'thresholds' => ['hot' => 100, 'warm' => 90],
        ]);

        $lead = $this->lead($workspace, ['phone' => '+8801700000001', 'website' => null, 'rating' => 4, 'review_count' => 20]);
        $result = $this->scorer()->score($lead);

        $this->assertLessThan(90, $result->score);
        $this->assertSame('cold', $result->band);
    }

    #[Test]
    public function a_warm_threshold_above_the_hot_threshold_cannot_strand_the_warm_band(): void
    {
        [, $workspace] = $this->actor();
        $config = LeadScoringConfig::create([
            'workspace_id' => $workspace->id,
            'weights' => LeadScoringConfig::DEFAULT_WEIGHTS,
            'thresholds' => ['hot' => 50, 'warm' => 80],
        ]);

        $this->assertSame(['hot' => 50, 'warm' => 50], $config->effectiveThresholds());
    }

    #[Test]
    public function a_stale_weight_key_from_an_older_release_is_ignored(): void
    {
        [, $workspace] = $this->actor();
        $config = LeadScoringConfig::create([
            'workspace_id' => $workspace->id,
            'weights' => ['has_phone' => 50, 'whatsapp_status' => 50],
            'thresholds' => LeadScoringConfig::DEFAULT_THRESHOLDS,
        ]);

        $this->assertArrayNotHasKey('whatsapp_status', $config->effectiveWeights());
        $this->assertSame(50, $config->effectiveWeights()['has_phone']);
    }

    #[Test]
    public function saving_scoring_settings_rescores_the_existing_board(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = $this->lead($workspace, ['phone' => '+8801700000001', 'website' => null, 'rating' => null, 'review_count' => 0]);

        $this->actingAs($user)->put(route('client.leads.pipeline.scoring.update'), [
            'weights' => ['has_phone' => 100, 'rating' => 0, 'review_volume' => 0, 'has_website' => 0],
            'thresholds' => ['hot' => 70, 'warm' => 40],
        ])->assertStatus(302);

        // Stored bands must not linger from the previous formula.
        $lead->refresh();
        $this->assertSame(100, $lead->score);
        $this->assertSame('hot', $lead->score_band);
        $this->assertNotNull($lead->scored_at);
    }

    #[Test]
    public function scoring_settings_reject_a_warm_threshold_above_hot(): void
    {
        [$user] = $this->actor();

        $this->actingAs($user)->put(route('client.leads.pipeline.scoring.update'), [
            'weights' => LeadScoringConfig::DEFAULT_WEIGHTS,
            'thresholds' => ['hot' => 40, 'warm' => 80],
        ])->assertSessionHasErrors('thresholds.warm');
    }

    #[Test]
    public function scoring_settings_reject_all_zero_weights(): void
    {
        [$user] = $this->actor();

        $this->actingAs($user)->put(route('client.leads.pipeline.scoring.update'), [
            'weights' => ['has_phone' => 0, 'rating' => 0, 'review_volume' => 0, 'has_website' => 0],
            'thresholds' => ['hot' => 70, 'warm' => 40],
        ])->assertSessionHasErrors('weights');
    }

    #[Test]
    public function rescore_only_touches_the_acting_workspace(): void
    {
        [$userA, $workspaceA] = $this->actor();
        [, $workspaceB] = $this->actor();

        $mine = $this->lead($workspaceA, ['phone' => '+8801700000001', 'score' => null]);
        $theirs = $this->lead($workspaceB, ['phone' => '+8801700000002', 'score' => null]);

        $this->actingAs($userA)->post(route('client.leads.pipeline.rescore'))->assertStatus(302);

        $this->assertNotNull($mine->fresh()->score);
        $this->assertNull($theirs->fresh()->score);
    }
}
