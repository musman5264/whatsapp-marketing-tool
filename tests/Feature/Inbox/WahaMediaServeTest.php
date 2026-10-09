<?php

namespace Tests\Feature\Inbox;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WhatsApp-Web (WAHA) media: the payload carries a `link` instead of a Cloud media id.
 * serveMedia must fetch it through the configured engine, cache it, and redirect.
 */
class WahaMediaServeTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://waha.test:3000';

    private const KEY = 'waha-secret-key-123';

    private $user;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();

        $ctx = $this->createWorkspaceContext();
        $this->user = $ctx['user'];
        $workspace = $ctx['workspace'];

        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'label' => 'WAHA',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => self::BASE, 'api_key' => self::KEY],
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'provider' => 'waha',
            'display_name' => 'WA Web',
            'status' => 'active',
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $this->conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    private function mediaMessage(array $payload, string $type = 'image'): Message
    {
        return Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'channel' => 'whatsapp',
            'type' => $type,
            'payload' => $payload,
            'body' => '',
            'sent_at' => now(),
        ]);
    }

    private function mediaUrl(Message $message): string
    {
        return route('client.inbox.message-media', [$this->conversation, $message]);
    }

    public function test_waha_link_is_fetched_with_api_key_cached_and_redirected(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg'])]);
        $message = $this->mediaMessage(['id' => 'wa-1', 'image' => ['link' => self::BASE.'/api/files/abc.jpeg']]);

        $response = $this->actingAs($this->user)->get($this->mediaUrl($message));

        $response->assertRedirect();
        $this->assertStringContainsString("message-media/{$message->id}.jpg", (string) $response->headers->get('Location'));
        Storage::disk('public')->assertExists("message-media/{$message->id}.jpg");
        Http::assertSent(fn ($request) => $request->url() === self::BASE.'/api/files/abc.jpeg'
            && $request->hasHeader('X-Api-Key', self::KEY));

        $message->refresh();
        $this->assertNotEmpty($message->payload['preview_url']);
        $this->assertSame('image/jpeg', $message->payload['mime_type']);
    }

    public function test_second_request_uses_the_cached_copy_without_calling_the_engine(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg'])]);
        $message = $this->mediaMessage(['id' => 'wa-2', 'image' => ['link' => self::BASE.'/api/files/x.jpeg']]);

        $this->actingAs($this->user)->get($this->mediaUrl($message))->assertRedirect();
        // Any further engine call now fails the test.
        Http::fake(function () {
            $this->fail('The engine was contacted again for a cached media file.');
        });
        $message->refresh();
        $this->actingAs($this->user)->get($this->mediaUrl($message))->assertRedirect();
    }

    public function test_link_host_is_replaced_by_the_configured_engine_origin(): void
    {
        // WAHA reports an internal URL (e.g. localhost). We must only ever contact the
        // configured engine, so the link host is rebased and the path/query kept.
        Http::fake([self::BASE.'/*' => Http::response('PNG', 200, ['Content-Type' => 'image/png'])]);
        $message = $this->mediaMessage(['id' => 'wa-3', 'image' => ['link' => 'http://localhost:3000/api/files/p.png?v=2']], 'image');

        $this->actingAs($this->user)->get($this->mediaUrl($message))->assertRedirect();

        Http::assertSent(fn ($request) => $request->url() === self::BASE.'/api/files/p.png?v=2');
        Http::assertSentCount(1);
    }

    public function test_engine_404_returns_404_and_never_leaks_the_api_key(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('gone', 404)]);
        $message = $this->mediaMessage(['id' => 'wa-4', 'image' => ['link' => self::BASE.'/api/files/gone.jpeg']]);

        $response = $this->actingAs($this->user)->get($this->mediaUrl($message));

        $response->assertNotFound();
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
    }

    public function test_engine_error_returns_502_without_leaking_the_api_key(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('boom', 500)]);
        $message = $this->mediaMessage(['id' => 'wa-5', 'image' => ['link' => self::BASE.'/api/files/err.jpeg']]);

        $response = $this->actingAs($this->user)->get($this->mediaUrl($message));

        $response->assertStatus(502);
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
    }

    public function test_missing_engine_configuration_returns_503(): void
    {
        IntegrationConfig::where('provider', 'whatsapp_web')->delete();
        Http::fake();
        $message = $this->mediaMessage(['id' => 'wa-6', 'image' => ['link' => self::BASE.'/api/files/n.jpeg']]);

        $this->actingAs($this->user)->get($this->mediaUrl($message))->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_message_without_media_id_or_link_still_returns_404(): void
    {
        Http::fake();
        $message = $this->mediaMessage(['id' => 'wa-7', 'text' => ['body' => 'hi']], 'text');

        $this->actingAs($this->user)->get($this->mediaUrl($message))->assertNotFound();
        Http::assertNothingSent();
    }
}
