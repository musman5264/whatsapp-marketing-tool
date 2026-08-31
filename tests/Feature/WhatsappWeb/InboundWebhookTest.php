<?php

namespace Tests\Feature\WhatsappWeb;

use App\Events\MessageReceived;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private WhatsappWebSession $session;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'],
            'webhook_secret' => 'secret',
        ]);

        $this->token = Str::random(48);
        $name = 'ws-'.$this->ctx['workspace']->id;
        $this->session = WhatsappWebSession::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'session_name' => $name,
            'engine' => 'waha',
            'status' => 'active',
            'webhook_token' => $this->token,
        ]);

        ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => $name,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);
    }

    private function sign(array $payload): array
    {
        $body = json_encode($payload);

        return ['X-Webhook-Hmac' => hash_hmac('sha256', $body, 'secret')];
    }

    private function messagePayload(string $id = 'false_123@c.us_ABC', string $body = 'Hi there'): array
    {
        return [
            'event' => 'message',
            'session' => $this->session->session_name,
            'payload' => [
                'id' => $id,
                'timestamp' => now()->timestamp,
                'from' => '15551234567@c.us',
                'fromMe' => false,
                'body' => $body,
                'type' => 'chat',
                'notifyName' => 'Sam',
            ],
        ];
    }

    #[Test]
    public function inbound_message_creates_contact_conversation_and_message(): void
    {
        Event::fake([MessageReceived::class]);
        $payload = $this->messagePayload();

        $res = $this->withHeaders($this->sign($payload))
            ->postJson("/webhooks/whatsapp-web/{$this->token}", $payload);

        $res->assertOk();
        $this->assertDatabaseHas('contacts', [
            'phone_e164' => '+15551234567',
            'workspace_id' => $this->ctx['workspace']->id,
        ]);
        $this->assertDatabaseHas('messages', [
            'channel' => 'whatsapp',
            'direction' => 'in',
            'provider_message_id' => 'false_123@c.us_ABC',
            'body' => 'Hi there',
        ]);
        Event::assertDispatched(MessageReceived::class);
    }

    #[Test]
    public function unknown_token_is_rejected(): void
    {
        $payload = $this->messagePayload();
        $this->withHeaders($this->sign($payload))
            ->postJson('/webhooks/whatsapp-web/not-a-real-token', $payload)
            ->assertStatus(403);
    }

    #[Test]
    public function bad_signature_is_rejected(): void
    {
        $payload = $this->messagePayload();
        $this->withHeaders(['X-Webhook-Hmac' => 'deadbeef'])
            ->postJson("/webhooks/whatsapp-web/{$this->token}", $payload)
            ->assertStatus(401);
    }

    #[Test]
    public function lid_sender_is_resolved_to_a_phone_number(): void
    {
        // WhatsApp LID contacts hide the number in the payload; the engine's
        // /api/contacts lookup returns the real phone-number JID.
        \Illuminate\Support\Facades\Http::fake([
            'waha.test/api/contacts*' => \Illuminate\Support\Facades\Http::response([
                'id' => '923345266444@c.us',
                'number' => '120297755815945',
                'name' => 'Muhammad Usman',
            ], 200),
        ]);

        $payload = [
            'event' => 'message',
            'session' => $this->session->session_name,
            'payload' => [
                'id' => 'false_120297755815945@lid_ZZZ',
                'timestamp' => now()->timestamp,
                'from' => '120297755815945@lid',
                'fromMe' => false,
                'body' => 'hi from a lid contact',
                'type' => 'chat',
            ],
        ];

        $this->postJson("/webhooks/whatsapp-web/{$this->token}", $payload)->assertOk();

        $this->assertDatabaseHas('contacts', ['phone_e164' => '+923345266444']);
        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'false_120297755815945@lid_ZZZ',
            'body' => 'hi from a lid contact',
        ]);
    }

    #[Test]
    public function unsigned_request_with_valid_token_is_accepted(): void
    {
        // Not every engine build signs webhooks; the 48-char URL token is the
        // primary auth. An unsigned request with a valid token still processes.
        $payload = $this->messagePayload('unsigned_1@c.us_X', 'no signature here');

        $this->postJson("/webhooks/whatsapp-web/{$this->token}", $payload)->assertOk();

        $this->assertDatabaseHas('messages', ['provider_message_id' => 'unsigned_1@c.us_X']);
    }

    #[Test]
    public function duplicate_event_is_processed_once(): void
    {
        $payload = $this->messagePayload('dup_1@c.us_X');

        $this->withHeaders($this->sign($payload))->postJson("/webhooks/whatsapp-web/{$this->token}", $payload)->assertOk();
        $this->withHeaders($this->sign($payload))->postJson("/webhooks/whatsapp-web/{$this->token}", $payload)->assertOk();

        $this->assertSame(1, \App\Modules\Shared\Models\Message::where('provider_message_id', 'dup_1@c.us_X')->count());
    }

    #[Test]
    public function outbound_echo_is_ignored(): void
    {
        $payload = $this->messagePayload();
        $payload['payload']['fromMe'] = true;

        $this->withHeaders($this->sign($payload))
            ->postJson("/webhooks/whatsapp-web/{$this->token}", $payload)
            ->assertOk();

        $this->assertSame(0, \App\Modules\Shared\Models\Message::count());
    }
}
