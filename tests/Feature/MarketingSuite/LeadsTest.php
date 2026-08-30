<?php

namespace Tests\Feature\MarketingSuite;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Leads\Jobs\ScrapeLeadsJob;
use App\Modules\Leads\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadsTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithWorkspace(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);
        $user->refresh();

        return [$user, $workspace];
    }

    #[Test]
    public function scrape_request_dispatches_job(): void
    {
        Queue::fake();

        [$user] = $this->createUserWithWorkspace();

        $response = $this->actingAs($user)->post('/app/leads/scrape', [
            'keyword' => 'restaurants',
            'location' => 'Dhaka',
        ]);

        $response->assertStatus(302);
        Queue::assertPushed(ScrapeLeadsJob::class);
    }

    #[Test]
    public function push_to_contacts_creates_contact_and_marks_lead(): void
    {
        [$user, $workspace] = $this->createUserWithWorkspace();

        $phone = '+8801700000001';
        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Restaurant',
            'phone' => $phone,
            'pushed_to_contacts' => false,
        ]);

        $response = $this->actingAs($user)->post('/app/leads/push-to-contacts', [
            'ids' => [$lead->id],
        ]);

        $response->assertStatus(302);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'pushed_to_contacts' => true]);
    }

    #[Test]
    public function cannot_delete_other_workspace_lead(): void
    {
        [$userA, $workspaceA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        $lead = Lead::factory()->create(['workspace_id' => $workspaceB->id]);

        $response = $this->actingAs($userA)->delete("/app/leads/{$lead->id}");
        $response->assertStatus(403);
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }
}
