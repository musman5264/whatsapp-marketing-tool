<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Services\WahaMediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-0032: the login-checked media address for a WhatsApp Web message must use the
 * conversation's uuid (the route key), not its integer id. Before the fix,
 * WahaMediaStore::authenticatedUrl() passed $message->conversation_id, so the address
 * returned 404. These tests were red on that code and are green now.
 */
class AuthenticatedMediaUrlTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://waha.test:3000';

    private const BYTES = 'INBOUND-JPEG-BYTES';

    private $user;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();

        $ctx = $this->createWorkspaceContext();
        $this->user = $ctx['user'];
        $workspace = $ctx['workspace'];

        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'label' => 'WAHA',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => self::BASE, 'api_key' => 'waha-secret-key-123'],
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
            'phone_number_id' => 'wa-web-test',
        ]);

        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $this->conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    /**
     * An inbound WhatsApp Web photo as WahaMediaStore::store() leaves it: the file is on the
     * private disk as message-media/{id}.jpg, and the payload records where it is.
     */
    private function storedInboundPhoto(): Message
    {
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'image',
            'payload' => [],
            'body' => '',
            'sent_at' => now(),
        ]);

        $own = "message-media/{$message->id}.jpg";
        Storage::disk('local')->put($own, self::BYTES);
        $message->update(['payload' => [
            'preview_url' => '/storage/'.$own,
            'mime_type' => 'image/jpeg',
            'media_path' => $own,
        ]]);

        return $message->refresh();
    }

    #[Test]
    public function an_inbound_whatsapp_web_photo_is_served_at_its_authenticated_url(): void
    {
        $message = $this->storedInboundPhoto();
        $url = app(WahaMediaStore::class)->authenticatedUrl($message);

        $response = $this->actingAs($this->user)->get($url);

        $response->assertOk();
        $this->assertSame(self::BYTES, $response->streamedContent());
    }

    #[Test]
    public function the_authenticated_url_uses_the_conversation_uuid_not_its_integer_id(): void
    {
        $message = $this->storedInboundPhoto();
        $url = app(WahaMediaStore::class)->authenticatedUrl($message);

        $this->assertSame(
            route('client.inbox.message-media', ['conversation' => $this->conversation, 'message' => $message->id]),
            $url
        );
        $this->assertStringContainsString($this->conversation->uuid, $url);
    }
}
