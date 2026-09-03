<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    #[Test]
    public function a_new_session_defaults_receipts_on_and_call_reject_off(): void
    {
        $s = WhatsappWebSession::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'session_name' => 'ws-'.$this->ctx['workspace']->id,
            'webhook_token' => str_repeat('a', 48),
        ]);

        $this->assertTrue($s->fresh()->send_receipts);
        $this->assertFalse($s->fresh()->auto_reject_calls);
        $this->assertNull($s->fresh()->call_reject_message);
    }

    /**
     * @param  bool  $receipts  send_receipts value on the session
     */
    private function wahaConversation(bool $receipts): Conversation
    {
        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'],
        ]);

        $name = 'ws-'.$this->ctx['workspace']->id;
        WhatsappWebSession::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'session_name' => $name,
            'engine' => 'waha',
            'status' => 'active',
            'webhook_token' => str_repeat('a', 48),
            'send_receipts' => $receipts,
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => $name,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);

        $contact = Contact::factory()->create([
            'workspace_id' => $this->ctx['workspace']->id,
            'phone_e164' => '+15551230000',
        ]);

        return Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    private function textMessage(Conversation $conv): Message
    {
        return Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'hello there',
            'status' => 'queued',
            'sent_by' => 'automation',
            'sent_at' => now(),
        ]);
    }

    #[Test]
    public function a_send_shows_a_typing_indicator_when_receipts_are_on(): void
    {
        $conv = $this->wahaConversation(receipts: true);

        Http::fake([
            'waha.test/api/startTyping' => Http::response([], 200),
            'waha.test/api/stopTyping' => Http::response([], 200),
            'waha.test/api/sendText' => Http::response(['id' => 'wamid.X'], 201),
        ]);

        app(WhatsappDriver::class)->send($this->textMessage($conv));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/startTyping') && $r['chatId'] === '15551230000@c.us');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/stopTyping') && $r['chatId'] === '15551230000@c.us');
    }

    #[Test]
    public function a_send_does_not_show_a_typing_indicator_when_receipts_are_off(): void
    {
        $conv = $this->wahaConversation(receipts: false);

        Http::fake(['waha.test/*' => Http::response(['id' => 'wamid.Y'], 201)]);

        app(WhatsappDriver::class)->send($this->textMessage($conv));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/startTyping'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/stopTyping'));
    }

    #[Test]
    public function a_typing_failure_never_blocks_the_actual_send(): void
    {
        $conv = $this->wahaConversation(receipts: true);

        Http::fake([
            'waha.test/api/startTyping' => Http::response(['error' => 'boom'], 500),
            'waha.test/api/stopTyping' => Http::response(['error' => 'boom'], 500),
            'waha.test/api/sendText' => Http::response(['id' => 'wamid.Z'], 201),
        ]);

        $id = app(WhatsappDriver::class)->send($this->textMessage($conv));

        $this->assertSame('wamid.Z', $id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
    }

    private function inboundOn(Conversation $conv, string $providerId = 'in_5'): Message
    {
        return Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'hi, are you open?',
            'status' => 'delivered',
            'provider_message_id' => $providerId,
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    #[Test]
    public function opening_a_conversation_sends_a_read_receipt_when_receipts_are_on(): void
    {
        $conv = $this->wahaConversation(receipts: true);
        $conv->update(['unread_count' => 1]);
        $this->inboundOn($conv, 'in_5');

        Http::fake(['waha.test/api/sendSeen' => Http::response([], 200)]);

        $this->actingAs($this->ctx['user'])
            ->get(route('client.inbox.show', $conv))
            ->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendSeen')
            && $r['chatId'] === '15551230000@c.us'
            && $r['messageId'] === 'in_5');
    }

    #[Test]
    public function opening_a_conversation_does_not_send_a_read_receipt_when_receipts_are_off(): void
    {
        $conv = $this->wahaConversation(receipts: false);
        $conv->update(['unread_count' => 1]);
        $this->inboundOn($conv);

        Http::fake(['waha.test/*' => Http::response([], 200)]);

        $this->actingAs($this->ctx['user'])
            ->get(route('client.inbox.show', $conv))
            ->assertOk();

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendSeen'));
    }

    #[Test]
    public function an_ai_reply_marks_the_inbound_message_seen_before_replying(): void
    {
        $conv = $this->wahaConversation(receipts: true);
        $inbound = $this->inboundOn($conv, 'in_9');

        $this->mock(\App\Modules\AI\Services\LlmGateway::class, fn ($m) => $m->shouldReceive('chat')
            ->andReturn(new \App\Modules\AI\Services\Llm\LlmResponse("We're open until 6pm!", 5, 5, 'test-model', 10)));

        Http::fake([
            'waha.test/api/sendSeen' => Http::response([], 200),
            'waha.test/*' => Http::response(['id' => 'out_1'], 201),
        ]);

        $automation = \App\Modules\Automation\Models\Automation::create([
            'workspace_id' => $conv->workspace_id,
            'name' => 'AI answer',
            'status' => 'active',
            'trigger_type' => 'message.received',
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => 'ai_reply', 'position' => ['x' => 0, 'y' => 100], 'data' => ['prompt' => 'be helpful']],
            ],
            'edges' => [['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1']],
        ]);
        $run = \App\Modules\Automation\Models\AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $conv->contact_id,
            'status' => 'pending',
            'context' => ['message_id' => $inbound->id, 'message_body' => 'hi, are you open?'],
            'started_at' => now(),
        ]);

        (new \App\Modules\Automation\Jobs\ExecuteAutomationRunJob($run->id))->handle(app(\App\Modules\Automation\Services\AutomationEngine::class));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendSeen') && $r['messageId'] === 'in_9');
    }
}
