<?php

namespace Tests\Feature\Inbox;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxReactionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function wahaConversation(array $workspaceAttrs = []): Conversation
    {
        $workspace = $workspaceAttrs['workspace'] ?? $this->ctx['workspace'];

        IntegrationConfig::firstOrCreate(
            ['provider' => 'whatsapp_web'],
            [
                'mode' => 'live',
                'enabled' => true,
                'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'],
            ],
        );

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-'.$workspace->id,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);

        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+15559998888',
        ]);

        return Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    private function inbound(Conversation $conv): Message
    {
        return Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'the message being reacted to',
            'status' => 'delivered',
            'provider_message_id' => 'in_9',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    #[Test]
    public function an_agent_can_react_to_an_inbound_message_and_it_is_sent_via_waha(): void
    {
        $conv = $this->wahaConversation();
        $inbound = $this->inbound($conv);

        Http::fake(['waha.test/api/reaction' => Http::response([], 200)]);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.messages.react', [
                'conversation' => $conv->uuid,
                'message' => $inbound->id,
            ]), ['emoji' => '👍'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $reaction = Message::where('conversation_id', $conv->id)
            ->where('type', 'reaction')
            ->first();

        $this->assertNotNull($reaction);
        $this->assertSame($inbound->id, $reaction->payload['target_message_id']);
        $this->assertSame('👍', $reaction->payload['emoji']);
        $this->assertSame('out', $reaction->direction);

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/api/reaction')
            && $r['messageId'] === 'in_9'
            && $r['reaction'] === '👍');
    }

    #[Test]
    public function reacting_on_a_conversation_in_another_workspace_is_forbidden(): void
    {
        $other = $this->createWorkspaceContext();
        $conv = $this->wahaConversation(['workspace' => $other['workspace']]);
        $inbound = $this->inbound($conv);

        Http::fake(['waha.test/api/reaction' => Http::response([], 200)]);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.messages.react', [
                'conversation' => $conv->uuid,
                'message' => $inbound->id,
            ]), ['emoji' => '👍'])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, Message::where('type', 'reaction')->count());
    }
}
