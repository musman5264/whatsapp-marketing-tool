<?php

namespace Tests\Feature\MarketingSuite;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadActivityTest extends TestCase
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

    private function lead(Workspace $workspace, array $attrs = []): Lead
    {
        return Lead::factory()->create($attrs + [
            'workspace_id' => $workspace->id,
            'stage_id' => $this->stages($workspace)->first()->id,
        ]);
    }

    #[Test]
    public function logging_a_call_records_it_against_the_acting_user(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        $this->actingAs($user)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'call',
            'body' => 'Rang reception, asked for the owner.',
            'outcome' => 'no_answer',
        ])->assertStatus(302);

        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'call')->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->id, (int) $activity->user_id);
        $this->assertSame('no_answer', $activity->meta['outcome']);
        $this->assertSame($workspace->id, (int) $activity->workspace_id);
        $this->assertNotNull($activity->occurred_at);
    }

    #[Test]
    public function logging_an_activity_stamps_last_activity_on_the_lead(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = $this->lead($workspace, ['last_activity_at' => null]);

        $this->actingAs($user)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'note',
            'body' => 'Left a voicemail.',
        ])->assertStatus(302);

        $this->assertNotNull($lead->fresh()->last_activity_at);
    }

    #[Test]
    public function a_note_with_no_body_is_rejected(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        $this->actingAs($user)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'note',
            'body' => '   ',
        ])->assertSessionHasErrors('body');

        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('type', 'note')->count());
    }

    #[Test]
    public function a_system_type_cannot_be_posted_by_a_user(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        // Otherwise a user could forge "qualified" or "stage_changed" history.
        $this->actingAs($user)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'stage_changed',
            'body' => 'faked',
        ])->assertSessionHasErrors('type');

        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->count());
    }

    #[Test]
    public function logging_can_set_and_later_clear_the_next_follow_up(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = $this->lead($workspace);
        $due = now()->addDays(3)->startOfMinute();

        $this->actingAs($user)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'call',
            'outcome' => 'no_answer',
            'next_follow_up_at' => $due->toDateTimeString(),
        ])->assertStatus(302);

        $this->assertSame($due->toDateTimeString(), $lead->fresh()->next_follow_up_at->toDateTimeString());

        $this->actingAs($user)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'call',
            'outcome' => 'answered',
            'clear_follow_up' => true,
        ])->assertStatus(302);

        $this->assertNull($lead->fresh()->next_follow_up_at);
    }

    #[Test]
    public function logging_a_touch_can_move_the_lead_in_the_same_request(): void
    {
        [$user, $workspace] = $this->actor();
        $stages = $this->stages($workspace);
        $lead = $this->lead($workspace);
        $contacted = $stages->get(1);

        $this->actingAs($user)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'call',
            'outcome' => 'answered',
            'stage_id' => $contacted->id,
        ])->assertStatus(302);

        $this->assertSame((int) $contacted->id, (int) $lead->fresh()->stage_id);

        // Both the call and the resulting move are in the history.
        $types = LeadActivity::where('lead_id', $lead->id)->pluck('type')->all();
        $this->assertContains('call', $types);
        $this->assertContains('stage_changed', $types);
    }

    #[Test]
    public function logging_a_touch_without_a_stage_change_does_not_invent_one(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        $this->actingAs($user)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'call',
            'outcome' => 'answered',
            'stage_id' => $lead->stage_id,
        ])->assertStatus(302);

        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('type', 'stage_changed')->count());
    }

    #[Test]
    public function a_lead_cannot_be_moved_into_another_workspaces_stage_while_logging(): void
    {
        [$userA, $workspaceA] = $this->actor();
        [, $workspaceB] = $this->actor();
        $lead = $this->lead($workspaceA);
        $foreignStage = $this->stages($workspaceB)->get(1);

        $this->actingAs($userA)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'call',
            'stage_id' => $foreignStage->id,
        ])->assertStatus(404);

        $this->assertNotSame((int) $foreignStage->id, (int) $lead->fresh()->stage_id);
    }

    #[Test]
    public function activity_cannot_be_logged_against_another_workspaces_lead(): void
    {
        [$userA] = $this->actor();
        [, $workspaceB] = $this->actor();
        $foreignLead = $this->lead($workspaceB);

        $this->actingAs($userA)->post(route('client.leads.pipeline.activities.store', $foreignLead->id), [
            'type' => 'note',
            'body' => 'should not land',
        ])->assertStatus(403);

        $this->assertSame(0, LeadActivity::where('lead_id', $foreignLead->id)->count());
    }

    #[Test]
    public function moving_a_lead_writes_stage_history_naming_both_ends(): void
    {
        [$user, $workspace] = $this->actor();
        $stages = $this->stages($workspace);
        $lead = $this->lead($workspace);

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.move', $lead->id), ['stage_id' => $stages->get(1)->id, 'position' => 0])
            ->assertStatus(302);

        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'stage_changed')->first();

        $this->assertNotNull($activity);
        $this->assertSame($stages->first()->name, $activity->meta['from']);
        $this->assertSame($stages->get(1)->name, $activity->meta['to']);
        $this->assertSame($user->id, (int) $activity->user_id);
    }

    #[Test]
    public function qualifying_is_recorded_as_a_system_entry_with_no_user(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace, [
            'phone' => '+8801700000001',
            'website' => 'https://example.com',
            'rating' => 5,
            'review_count' => 500,
            'score_band' => null,
        ]);

        app(PipelineManager::class)->rescore($lead);

        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'qualified')->first();

        $this->assertNotNull($activity);
        $this->assertNull($activity->user_id);
        $this->assertTrue($activity->isSystem());
        $this->assertSame(100, $activity->meta['score']);
    }

    #[Test]
    public function pushing_to_contacts_is_recorded(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = $this->lead($workspace, ['phone' => '+8801700000055', 'pushed_to_contacts' => false]);

        $this->actingAs($user)
            ->post(route('client.leads.push-to-contacts'), ['ids' => [$lead->id]])
            ->assertStatus(302);

        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'pushed_to_contacts')->first();
        $this->assertNotNull($activity);
        $this->assertSame((int) $lead->fresh()->contact->id, (int) $activity->meta['contact_id']);
    }

    #[Test]
    public function deleting_a_lead_takes_its_history_with_it(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        $this->actingAs($user)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'note', 'body' => 'Some history.',
        ])->assertStatus(302);

        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->count());

        $this->actingAs($user)->delete(route('client.leads.destroy', $lead->id))->assertStatus(302);

        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->count());
    }

    #[Test]
    public function the_board_does_not_carry_timelines_until_a_lead_is_asked_for(): void
    {
        [$user, $workspace] = $this->actor();
        $this->lead($workspace);

        // The board holds 50+ leads; loading every timeline to draw cards nobody
        // opened would be waste. Without ?lead there is no detail at all.
        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('leadDetail', null));
    }

    #[Test]
    public function asking_for_a_lead_returns_it_with_its_history(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = $this->lead($workspace, ['name' => 'Serenity Spa']);

        $this->actingAs($user)->post(route('client.leads.pipeline.activities.store', $lead->id), [
            'type' => 'call', 'body' => 'Spoke to the owner.', 'outcome' => 'answered',
        ]);

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['lead' => $lead->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('leadDetail.lead.name', 'Serenity Spa')
                ->where('leadDetail.activities.0.type', 'call')
                ->where('leadDetail.activities.0.body', 'Spoke to the owner.')
                ->where('leadDetail.activities.0.user.name', $user->name)
            );
    }

    #[Test]
    public function another_workspaces_lead_cannot_be_fetched_by_id(): void
    {
        [$userA] = $this->actor();
        [, $workspaceB] = $this->actor();
        $foreign = $this->lead($workspaceB, ['name' => 'Not Yours']);

        $this->actingAs($userA)
            ->get(route('client.leads.pipeline.index', ['lead' => $foreign->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('leadDetail', null));
    }

    #[Test]
    public function the_board_can_be_filtered_to_leads_that_are_due(): void
    {
        [$user, $workspace] = $this->actor();
        $this->lead($workspace, ['name' => 'Overdue', 'next_follow_up_at' => now()->subDay()]);
        $this->lead($workspace, ['name' => 'Later', 'next_follow_up_at' => now()->addWeek()]);
        $this->lead($workspace, ['name' => 'No follow-up', 'next_follow_up_at' => null]);

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['due' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stages.0.leads', fn ($leads) => collect($leads)->count() === 1
                && collect($leads)->first()['name'] === 'Overdue'
            ));
    }

    #[Test]
    public function history_is_newest_first(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        LeadActivity::create(['workspace_id' => $workspace->id, 'lead_id' => $lead->id, 'type' => 'note', 'body' => 'older', 'occurred_at' => now()->subDays(2)]);
        LeadActivity::create(['workspace_id' => $workspace->id, 'lead_id' => $lead->id, 'type' => 'note', 'body' => 'newer', 'occurred_at' => now()]);

        $this->assertSame(['newer', 'older'], $lead->fresh()->activities->pluck('body')->all());
    }
}
