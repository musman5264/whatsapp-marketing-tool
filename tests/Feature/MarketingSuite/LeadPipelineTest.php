<?php

namespace Tests\Feature\MarketingSuite;

use App\Events\LeadQualified;
use App\Events\LeadStageChanged;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadPipelineTest extends TestCase
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

    private function stages(Workspace $workspace)
    {
        return app(PipelineManager::class)->stagesFor($workspace->id);
    }

    #[Test]
    public function opening_the_board_seeds_the_default_stages_once(): void
    {
        [$user, $workspace] = $this->actor();

        $this->actingAs($user)->get(route('client.leads.pipeline.index'))->assertOk();
        $this->assertSame(count(LeadPipelineStage::DEFAULTS), LeadPipelineStage::where('workspace_id', $workspace->id)->count());

        // Revisiting must not seed a second set.
        $this->actingAs($user)->get(route('client.leads.pipeline.index'))->assertOk();
        $this->assertSame(count(LeadPipelineStage::DEFAULTS), LeadPipelineStage::where('workspace_id', $workspace->id)->count());

        $this->assertDatabaseHas('lead_pipeline_stages', ['workspace_id' => $workspace->id, 'name' => 'New', 'position' => 0]);
        $this->assertDatabaseHas('lead_pipeline_stages', ['workspace_id' => $workspace->id, 'name' => 'Won', 'is_won' => true]);
    }

    #[Test]
    public function moving_a_lead_updates_its_stage_and_fires_the_event(): void
    {
        Event::fake([LeadStageChanged::class]);
        [$user, $workspace] = $this->actor();
        $stages = $this->stages($workspace);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'stage_id' => $stages->first()->id,
        ]);
        $target = $stages->get(1);

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.move', $lead->id), ['stage_id' => $target->id, 'position' => 0])
            ->assertStatus(302);

        $lead->refresh();
        $this->assertSame((int) $target->id, (int) $lead->stage_id);
        $this->assertNotNull($lead->stage_changed_at);

        Event::assertDispatched(LeadStageChanged::class, fn ($e) => $e->leadId === $lead->id
            && $e->toStage === $target->name
            && $e->fromStage === $stages->first()->name);
    }

    #[Test]
    public function reordering_within_the_same_stage_does_not_fire_a_stage_change(): void
    {
        Event::fake([LeadStageChanged::class]);
        [$user, $workspace] = $this->actor();
        $stage = $this->stages($workspace)->first();

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => $stage->id, 'board_position' => 0]);
        Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => $stage->id, 'board_position' => 1]);

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.move', $lead->id), ['stage_id' => $stage->id, 'position' => 1])
            ->assertStatus(302);

        Event::assertNotDispatched(LeadStageChanged::class);
    }

    #[Test]
    public function moving_into_a_won_stage_flags_the_event_as_won(): void
    {
        Event::fake([LeadStageChanged::class]);
        [$user, $workspace] = $this->actor();
        $stages = $this->stages($workspace);
        $won = $stages->firstWhere('is_won', true);

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => $stages->first()->id]);

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.move', $lead->id), ['stage_id' => $won->id, 'position' => 0])
            ->assertStatus(302);

        Event::assertDispatched(LeadStageChanged::class, fn ($e) => $e->isWon === true && $e->isLost === false);
    }

    #[Test]
    public function a_lead_pushed_to_contacts_carries_its_contact_id_on_the_event(): void
    {
        Event::fake([LeadStageChanged::class]);
        [$user, $workspace] = $this->actor();
        $stages = $this->stages($workspace);

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => $stages->first()->id]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'lead_id' => $lead->id]);

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.move', $lead->id), ['stage_id' => $stages->get(1)->id, 'position' => 0])
            ->assertStatus(302);

        Event::assertDispatched(LeadStageChanged::class, fn ($e) => $e->contactId === $contact->id);
    }

    #[Test]
    public function pushing_a_lead_to_contacts_links_the_contact_back_to_the_lead(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'phone' => '+8801700000009',
            'pushed_to_contacts' => false,
        ]);

        $this->actingAs($user)
            ->post(route('client.leads.push-to-contacts'), ['ids' => [$lead->id]])
            ->assertStatus(302);

        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'lead_id' => $lead->id]);
        $this->assertSame($lead->id, (int) $lead->fresh()->contact->lead_id);
    }

    #[Test]
    public function a_lead_cannot_be_moved_into_another_workspaces_stage(): void
    {
        [$userA, $workspaceA] = $this->actor();
        [, $workspaceB] = $this->actor();

        $lead = Lead::factory()->create(['workspace_id' => $workspaceA->id, 'stage_id' => $this->stages($workspaceA)->first()->id]);
        $foreignStage = $this->stages($workspaceB)->first();

        $this->actingAs($userA)
            ->put(route('client.leads.pipeline.move', $lead->id), ['stage_id' => $foreignStage->id, 'position' => 0])
            ->assertStatus(404);

        $this->assertNotSame((int) $foreignStage->id, (int) $lead->fresh()->stage_id);
    }

    #[Test]
    public function a_lead_from_another_workspace_cannot_be_moved(): void
    {
        [$userA, $workspaceA] = $this->actor();
        [, $workspaceB] = $this->actor();

        $foreignLead = Lead::factory()->create(['workspace_id' => $workspaceB->id]);
        $stage = $this->stages($workspaceA)->first();

        $this->actingAs($userA)
            ->put(route('client.leads.pipeline.move', $foreignLead->id), ['stage_id' => $stage->id, 'position' => 0])
            ->assertStatus(403);
    }

    #[Test]
    public function deleting_a_stage_moves_its_leads_to_the_fallback_rather_than_losing_them(): void
    {
        [$user, $workspace] = $this->actor();
        $stages = $this->stages($workspace);
        $doomed = $stages->get(2);

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => $doomed->id]);

        $this->actingAs($user)
            ->delete(route('client.leads.pipeline.stages.destroy', $doomed->id))
            ->assertStatus(302);

        $this->assertDatabaseMissing('lead_pipeline_stages', ['id' => $doomed->id]);
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
        $this->assertSame((int) $stages->first()->id, (int) $lead->fresh()->stage_id);
    }

    #[Test]
    public function the_last_remaining_stage_cannot_be_deleted(): void
    {
        [$user, $workspace] = $this->actor();
        $stages = $this->stages($workspace);
        $keep = $stages->first();

        LeadPipelineStage::where('workspace_id', $workspace->id)->where('id', '!=', $keep->id)->delete();

        $this->actingAs($user)
            ->delete(route('client.leads.pipeline.stages.destroy', $keep->id))
            ->assertSessionHasErrors('stage');

        $this->assertDatabaseHas('lead_pipeline_stages', ['id' => $keep->id]);
    }

    #[Test]
    public function a_stage_from_another_workspace_cannot_be_deleted(): void
    {
        [$userA] = $this->actor();
        [, $workspaceB] = $this->actor();
        $foreignStage = $this->stages($workspaceB)->first();

        $this->actingAs($userA)
            ->delete(route('client.leads.pipeline.stages.destroy', $foreignStage->id))
            ->assertStatus(404);

        $this->assertDatabaseHas('lead_pipeline_stages', ['id' => $foreignStage->id]);
    }

    #[Test]
    public function stage_reorder_ignores_ids_from_another_workspace(): void
    {
        [$userA, $workspaceA] = $this->actor();
        [, $workspaceB] = $this->actor();

        $mine = $this->stages($workspaceA);
        $foreign = $this->stages($workspaceB)->first();
        $foreignPosition = $foreign->position;

        $this->actingAs($userA)
            ->put(route('client.leads.pipeline.stages.reorder'), [
                'ids' => [$mine->get(1)->id, $mine->first()->id, $foreign->id],
            ])
            ->assertStatus(302);

        $this->assertSame(0, (int) $mine->get(1)->fresh()->position);
        $this->assertSame(1, (int) $mine->first()->fresh()->position);
        $this->assertSame((int) $foreignPosition, (int) $foreign->fresh()->position);
    }

    #[Test]
    public function unstaged_leads_are_adopted_into_the_first_stage(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => null]);

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stages.0.leads.0.id', $lead->id)
            );

        // Drawn in column one is not enough — the row must actually own the stage,
        // or reordering the stages would relocate it.
        $first = LeadPipelineStage::where('workspace_id', $workspace->id)->orderBy('position')->first();
        $this->assertSame((int) $first->id, (int) $lead->fresh()->stage_id);
    }

    #[Test]
    public function adopting_unstaged_leads_does_not_disturb_leads_already_placed(): void
    {
        [$user, $workspace] = $this->actor();
        $stages = $this->stages($workspace);
        $placed = Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => $stages->last()->id]);
        $orphan = Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => null]);

        $this->actingAs($user)->get(route('client.leads.pipeline.index'))->assertOk();

        $this->assertSame((int) $stages->last()->id, (int) $placed->fresh()->stage_id);
        $this->assertSame((int) $stages->first()->id, (int) $orphan->fresh()->stage_id);
    }

    #[Test]
    public function adoption_does_not_reach_into_another_workspace(): void
    {
        [$userA, $workspaceA] = $this->actor();
        [, $workspaceB] = $this->actor();

        $foreignOrphan = Lead::factory()->create(['workspace_id' => $workspaceB->id, 'stage_id' => null]);

        $this->actingAs($userA)->get(route('client.leads.pipeline.index'))->assertOk();

        $this->assertNull($foreignOrphan->fresh()->stage_id);
    }

    #[Test]
    public function the_board_only_shows_this_workspaces_leads(): void
    {
        [$userA, $workspaceA] = $this->actor();
        [, $workspaceB] = $this->actor();

        Lead::factory()->create(['workspace_id' => $workspaceA->id, 'stage_id' => $this->stages($workspaceA)->first()->id, 'name' => 'Mine']);
        Lead::factory()->create(['workspace_id' => $workspaceB->id, 'stage_id' => $this->stages($workspaceB)->first()->id, 'name' => 'Theirs']);

        $this->actingAs($userA)
            ->get(route('client.leads.pipeline.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stages.0.leads', fn ($leads) => collect($leads)->count() === 1
                && collect($leads)->first()['name'] === 'Mine'
            ));
    }

    #[Test]
    public function the_board_can_be_filtered_by_score_band(): void
    {
        [$user, $workspace] = $this->actor();
        $stage = $this->stages($workspace)->first();

        Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => $stage->id, 'score_band' => 'hot', 'name' => 'Hot Lead']);
        Lead::factory()->create(['workspace_id' => $workspace->id, 'stage_id' => $stage->id, 'score_band' => 'cold', 'name' => 'Cold Lead']);

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['band' => 'hot']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stages.0.leads', fn ($leads) => collect($leads)->count() === 1
                && collect($leads)->first()['name'] === 'Hot Lead'
            ));
    }

    #[Test]
    public function qualification_fires_only_on_the_transition_into_hot(): void
    {
        Event::fake([LeadQualified::class]);
        [, $workspace] = $this->actor();
        $manager = app(PipelineManager::class);

        // Maxes every rule: phone, website, 5.0 rating, saturated reviews.
        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'phone' => '+8801700000001',
            'website' => 'https://example.com',
            'rating' => 5,
            'review_count' => 500,
            'score_band' => null,
        ]);

        $this->assertTrue($manager->rescore($lead));
        $this->assertSame('hot', $lead->fresh()->score_band);
        Event::assertDispatchedTimes(LeadQualified::class, 1);

        // Already hot — rescoring must not re-fire.
        $this->assertFalse($manager->rescore($lead->fresh()));
        Event::assertDispatchedTimes(LeadQualified::class, 1);
    }
}
