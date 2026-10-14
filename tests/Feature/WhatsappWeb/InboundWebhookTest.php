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

        // WAHA signs with HMAC-SHA512 and names the algorithm in X-Webhook-Hmac-Algorithm.
        return [
            'X-Webhook-Hmac' => hash_hmac('sha512', $body, 'secret'),
            'X-Webhook-Hmac-Algorithm' => 'sha512',
        ];
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

    /** WAHA cannot map the LID to a phone number: the reply is still a LID. The message must not be lost. */
    #[Test]
    public function lid_sender_that_waha_cannot_map_is_still_stored(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'waha.test/api/contacts*' => \Illuminate\Support\Facades\Http::response([
                'id' => '120297755815945@lid',
                'name' => 'Unknown contact',
            ], 200),
        ]);

        $this->postJson("/webhooks/whatsapp-web/{$this->token}", $this->lidPayload('unmapped'))->assertOk();

        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'false_120297755815945@lid_unmapped',
            'body' => 'hi from an unmapped lid',
        ]);
    }

    /** WAHA is unreachable for the lookup: the message must still be stored, under the LID number. */
    #[Test]
    public function lid_sender_is_stored_when_the_lookup_fails(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'waha.test/api/contacts*' => \Illuminate\Support\Facades\Http::response('error', 502),
        ]);

        $this->postJson("/webhooks/whatsapp-web/{$this->token}", $this->lidPayload('down'))->assertOk();

        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'false_120297755815945@lid_down',
            'body' => 'hi from an unmapped lid',
        ]);
    }

    /** @return array<string, mixed> */
    private function lidPayload(string $suffix): array
    {
        return [
            'event' => 'message',
            'session' => $this->session->session_name,
            'payload' => [
                'id' => 'false_120297755815945@lid_'.$suffix,
                'timestamp' => now()->timestamp,
                'from' => '120297755815945@lid',
                'fromMe' => false,
                'body' => 'hi from an unmapped lid',
                'type' => 'chat',
            ],
        ];
    }

    /** A number WAHA gave once is kept in the database, so a later failed lookup still reaches the real contact. */
    #[Test]
    public function learned_lid_mapping_is_kept_when_a_later_lookup_fails(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'waha.test/api/contacts*' => \Illuminate\Support\Facades\Http::sequence()
                ->push(['id' => '923345266444@c.us', 'name' => 'Muhammad Usman'], 200)
                ->push('error', 502),
        ]);

        $this->postJson("/webhooks/whatsapp-web/{$this->token}", $this->lidPayload('first'))->assertOk();
        $this->postJson("/webhooks/whatsapp-web/{$this->token}", $this->lidPayload('second'))->assertOk();

        $message = \App\Modules\Shared\Models\Message::where('provider_message_id', 'false_120297755815945@lid_second')->first();
        $this->assertNotNull($message);
        $this->assertSame('+923345266444', $message->conversation->contact->phone_e164);
        $this->assertDatabaseHas('whatsapp_web_lid_mappings', [
            'session_name' => $this->session->session_name,
            'lid' => '120297755815945',
            'phone_e164' => '+923345266444',
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

    #[Test]
    public function a_sha256_signature_is_rejected_because_waha_signs_with_sha512(): void
    {
        // WAHA's documented HMAC is SHA-512. A SHA-256 digest is not what WAHA sends.
        $payload = $this->messagePayload('sha256_1@c.us_X');
        $body = json_encode($payload);

        $this->withHeaders(['X-Webhook-Hmac' => hash_hmac('sha256', $body, 'secret')])
            ->postJson("/webhooks/whatsapp-web/{$this->token}", $payload)
            ->assertStatus(401);

        $this->assertDatabaseMissing('messages', ['provider_message_id' => 'sha256_1@c.us_X']);
    }

    #[Test]
    public function a_sha512_signature_without_the_algorithm_header_is_accepted(): void
    {
        // sha512 is WAHA's default, so a digest without the algorithm header still verifies.
        $payload = $this->messagePayload('default_alg_1@c.us_X');
        $body = json_encode($payload);

        $this->withHeaders(['X-Webhook-Hmac' => hash_hmac('sha512', $body, 'secret')])
            ->postJson("/webhooks/whatsapp-web/{$this->token}", $payload)
            ->assertOk();

        $this->assertDatabaseHas('messages', ['provider_message_id' => 'default_alg_1@c.us_X']);
    }

    #[Test]
    public function a_photo_sent_as_a_chat_with_media_is_stored_as_an_image(): void
    {
        // WAHA sends a photo as type "chat" with hasMedia=true and the caption as the body.
        // It must be stored as an image, not as an empty text message.
        Event::fake([MessageReceived::class]);
        \Illuminate\Support\Facades\Http::fake(['waha.test/*' => \Illuminate\Support\Facades\Http::response('bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $payload = [
            'event' => 'message',
            'session' => $this->session->session_name,
            'payload' => [
                'id' => 'false_15551234567@c.us_PHOTO1',
                'timestamp' => now()->timestamp,
                'from' => '15551234567@c.us',
                'fromMe' => false,
                'type' => 'chat',
                'body' => 'This is caption',
                'hasMedia' => true,
                'media' => ['url' => 'http://waha.test/api/files/photo1.jpg', 'mimetype' => 'image/jpeg', 'filename' => null],
            ],
        ];

        $this->withHeaders($this->sign($payload))
            ->postJson("/webhooks/whatsapp-web/{$this->token}", $payload)
            ->assertOk();

        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'false_15551234567@c.us_PHOTO1',
            'type' => 'image',
            'body' => 'This is caption',
        ]);
    }
}
