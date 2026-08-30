<?php

namespace Tests\Feature\Campaign;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Jobs\DispatchCampaignChunkJob;
use App\Modules\Broadcasting\Jobs\FinalizeCampaignJob;
use App\Modules\Broadcasting\Jobs\LaunchCampaignJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdempotentLaunchTest extends TestCase
{
    use RefreshDatabase;

    private function ctx(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        return [$user, $workspace];
    }

    #[Test]
    public function relaunching_a_paused_campaign_does_not_duplicate_recipients(): void
    {
        Queue::fake();

        [$user, $workspace] = $this->ctx();

        // 5 contacts in the workspace, all opted-in by factory default.
        Contact::factory()->count(5)->create([
            'workspace_id' => $workspace->id,
            'opt_in_sms' => true,
        ]);

        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'status' => 'queued',
            'audience_type' => 'contact_list',
            'payload_json' => ['body' => 'Hi'],
        ]);

        // First launch — materialises 5 recipients.
        (new LaunchCampaignJob($campaign->id))->handle();
        $this->assertSame(5, CampaignRecipient::where('campaign_id', $campaign->id)->count());

        // User pauses, then re-launches.
        $campaign->update(['status' => 'queued']);

        (new LaunchCampaignJob($campaign->id))->handle();

        // Still exactly 5 — no duplicates because of the unique index + insertOrIgnore.
        $this->assertSame(5, CampaignRecipient::where('campaign_id', $campaign->id)->count());
    }

    #[Test]
    public function launch_filters_out_contacts_that_did_not_opt_in_for_the_channel(): void
    {
        Queue::fake();

        [$user, $workspace] = $this->ctx();

        // 3 SMS-opted-in, 2 opted out for SMS but in for WhatsApp.
        Contact::factory()->count(3)->create([
            'workspace_id' => $workspace->id,
            'opt_in_sms' => true,
        ]);
        Contact::factory()->count(2)->create([
            'workspace_id' => $workspace->id,
            'opt_in_sms' => false,
        ]);

        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'status' => 'queued',
            'audience_type' => 'contact_list',
            'payload_json' => ['body' => 'Hi'],
        ]);

        (new LaunchCampaignJob($campaign->id))->handle();

        $this->assertSame(3, CampaignRecipient::where('campaign_id', $campaign->id)->count());
    }

    #[Test]
    public function launch_with_empty_audience_marks_campaign_failed(): void
    {
        Queue::fake();

        [$user, $workspace] = $this->ctx();

        // Workspace has no contacts at all.
        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'status' => 'queued',
            'audience_type' => 'contact_list',
            'payload_json' => ['body' => 'Hi'],
        ]);

        (new LaunchCampaignJob($campaign->id))->handle();

        $campaign->refresh();
        $this->assertSame('failed', $campaign->status);
        Queue::assertNotPushed(DispatchCampaignChunkJob::class);
        Queue::assertNotPushed(FinalizeCampaignJob::class);
    }
}
