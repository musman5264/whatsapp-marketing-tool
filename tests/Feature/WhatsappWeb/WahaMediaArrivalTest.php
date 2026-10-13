<?php

namespace Tests\Feature\WhatsappWeb;

use App\Events\MessageMediaReady;
use App\Events\MessageReceived;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WahaMediaArrivalTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://waha.test';

    private WhatsappWebSession $session;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        $ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => self::BASE],
            'webhook_secret' => 'secret',
        ]);

        $this->token = Str::random(48);
        $name = 'ws-'.$ctx['workspace']->id;
        $this->session = WhatsappWebSession::create([
            'workspace_id' => $ctx['workspace']->id,
            'session_name' => $name,
            'engine' => 'waha',
            'status' => 'active',
            'webhook_token' => $this->token,
        ]);
        ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => $name,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);
    }

    private function imagePayload(string $id = 'false_555@c.us_IMG'): array
    {
        return [
            'event' => 'message',
            'session' => $this->session->session_name,
            'payload' => [
                'id' => $id,
                'timestamp' => now()->timestamp,
                'from' => '15551230000@c.us',
                'fromMe' => false,
                'body' => '',
                'type' => 'image',
                'notifyName' => 'Sam',
                'media' => ['url' => self::BASE.'/api/files/photo.jpeg', 'mimetype' => 'image/jpeg'],
            ],
        ];
    }

    private function postWebhook(array $payload)
    {
        $body = json_encode($payload);

        return $this->postJson("/webhooks/whatsapp-web/{$this->token}", $payload, [
            'X-Webhook-Hmac' => hash_hmac('sha512', $body, 'secret'),
        ]);
    }

    #[Test]
    public function inbound_image_is_stored_at_arrival_and_announced(): void
    {
        Event::fake([MessageReceived::class, MessageMediaReady::class]);
        Http::fake([self::BASE.'/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg'])]);

        $this->postWebhook($this->imagePayload())->assertOk();

        $message = Message::where('provider_message_id', 'false_555@c.us_IMG')->firstOrFail();
        $this->assertNotEmpty($message->payload['preview_url']);
        $this->assertSame('image/jpeg', $message->payload['mime_type']);
        $this->assertSame("message-media/{$message->id}.jpg", $message->payload['media_path']);
        Storage::disk('local')->assertExists("message-media/{$message->id}.jpg");
        Event::assertDispatched(MessageReceived::class);
        Event::assertDispatched(MessageMediaReady::class, fn ($e) => $e->message->id === $message->id);
    }

    #[Test]
    public function an_engine_failure_keeps_the_message_and_skips_the_announcement(): void
    {
        Event::fake([MessageReceived::class, MessageMediaReady::class]);
        Http::fake([self::BASE.'/*' => Http::response('boom', 500)]);

        $this->postWebhook($this->imagePayload('false_556@c.us_IMG'))->assertOk();

        $message = Message::where('provider_message_id', 'false_556@c.us_IMG')->firstOrFail();
        $this->assertArrayNotHasKey('preview_url', $message->payload);
        Event::assertDispatched(MessageReceived::class);
        Event::assertNotDispatched(MessageMediaReady::class);
    }
}
