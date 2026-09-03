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
}
