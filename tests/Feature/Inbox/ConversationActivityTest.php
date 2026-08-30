<?php

namespace Tests\Feature\Inbox;

use App\Models\User;
use App\Modules\Inbox\Models\ConversationActivity;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationActivityTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();

        $workspace = $this->ctx['workspace'];
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'WA',
            'status' => 'active',
        ]);
        $this->conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => 'bot',
        ]);
    }

    public function test_creating_a_conversation_logs_created_activity(): void
    {
        $activity = ConversationActivity::where('conversation_id', $this->conversation->id)
            ->where('type', 'created')
            ->first();

        $this->assertNotNull($activity);
        $this->assertNull($activity->user_id);
        $this->assertEquals($this->conversation->workspace_id, $activity->workspace_id);
    }

    public function test_assigning_an_agent_logs_assigned_activity(): void
    {
        $user = $this->ctx['user'];

        $this->actingAs($user)
            ->post(route('client.inbox.assign', $this->conversation), ['user_id' => $user->id])
            ->assertRedirect();

        $activity = $this->conversation->activities()->where('type', 'assigned')->first();
        $this->assertNotNull($activity);
        $this->assertEquals($user->id, $activity->user_id);
        $this->assertEquals($user->id, $activity->meta['to_id']);
        $this->assertEquals($user->name, $activity->meta['to_name']);
    }

    public function test_reassigning_logs_transferred_and_unassigning_logs_unassigned(): void
    {
        $user = $this->ctx['user'];
        $other = User::factory()->create([
            'workspace_id' => $this->ctx['workspace']->id,
            'client_id' => $user->client_id,
        ]);

        $this->conversation->update(['assigned_user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('client.inbox.assign', $this->conversation), ['user_id' => $other->id])
            ->assertRedirect();

        $transfer = $this->conversation->activities()->where('type', 'transferred')->first();
        $this->assertNotNull($transfer);
        $this->assertEquals($user->id, $transfer->meta['from_id']);
        $this->assertEquals($other->id, $transfer->meta['to_id']);

        $this->actingAs($user)
            ->post(route('client.inbox.assign', $this->conversation), ['user_id' => null])
            ->assertRedirect();

        $unassigned = $this->conversation->activities()->where('type', 'unassigned')->first();
        $this->assertNotNull($unassigned);
        $this->assertEquals($other->id, $unassigned->meta['from_id']);
    }

    public function test_status_change_and_handover_are_logged(): void
    {
        $user = $this->ctx['user'];

        $this->actingAs($user)
            ->post(route('client.inbox.status', $this->conversation), ['status' => 'resolved'])
            ->assertRedirect();

        $status = $this->conversation->activities()->where('type', 'status_changed')->first();
        $this->assertNotNull($status);
        $this->assertEquals('open', $status->meta['from']);
        $this->assertEquals('resolved', $status->meta['to']);

        $this->actingAs($user)
            ->postJson(route('client.inbox.handover', $this->conversation), ['mode' => 'human'])
            ->assertOk();

        $handover = $this->conversation->activities()->where('type', 'handover')->first();
        $this->assertNotNull($handover);
        $this->assertEquals('human', $handover->meta['to']);
    }

    public function test_activities_endpoint_returns_log_and_is_workspace_scoped(): void
    {
        $user = $this->ctx['user'];
        $this->conversation->update(['status' => 'pending']);

        $response = $this->actingAs($user)
            ->getJson(route('client.inbox.activities.index', $this->conversation))
            ->assertOk()
            ->json();

        $types = array_column($response, 'type');
        $this->assertContains('created', $types);
        $this->assertContains('status_changed', $types);

        // A user from a different workspace cannot read the log
        $foreign = $this->createWorkspaceContext();
        $this->actingAs($foreign['user'])
            ->getJson(route('client.inbox.activities.index', $this->conversation))
            ->assertForbidden();
    }
}
