<?php

namespace Tests\Feature\Api\V1;

use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Media (photo / document / video / audio) send via POST /api/v1/messages/send.
 * The WhatsApp media pipeline is already exercised end-to-end by the mobile
 * inbox controller; these tests pin the same behaviour onto the public API.
 */
class MessageSendMediaApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Swap the WhatsApp channel driver for a mock that records the Message it is
     * handed and returns a canned provider id.
     *
     * @return object{message: ?Message}
     */
    private function fakeWhatsappDriver(string $providerId = 'wamid.MEDIA'): object
    {
        $holder = new class
        {
            public ?Message $message = null;
        };

        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andReturnUsing(function (Message $message) use ($holder, $providerId) {
            $holder->message = $message;

            return $providerId;
        });

        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->with('whatsapp')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);

        return $holder;
    }

    /** Active Cloud API account + WABA so CloudApiClient::forWorkspace() resolves. */
    private function cloudAccount(int $workspaceId): ChannelAccount
    {
        WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspaceId,
            'status' => 'active',
            'credentials' => ['system_user_token' => 'test-token'],
        ]);

        return ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'status' => 'active',
            'display_name' => 'Cloud number',
            'phone_number_id' => '111222333',
        ]);
    }

    private function wahaAccount(int $workspaceId): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'status' => 'active',
            'display_name' => 'WhatsApp (personal)',
            'phone_number_id' => 'wa-web-'.$workspaceId,
        ]);
    }

    private function contact(int $workspaceId): Contact
    {
        return Contact::factory()->create([
            'workspace_id' => $workspaceId,
            'phone_e164' => '+8801700000009',
        ]);
    }

    private function openWindow(Conversation $conversation): void
    {
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'hi',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    // ─── uploaded file, Cloud API account ────────────────────────────────────

    public function test_uploaded_image_is_uploaded_to_cloud_api_and_sent_as_image(): void
    {
        Storage::fake('public');
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA-ID-123'], 200),
        ]);

        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->contact($ws->id);
        $cloud = $this->cloudAccount($ws->id);
        $conversation = Conversation::create([
            'workspace_id' => $ws->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $cloud->id,
            'status' => 'open',
        ]);
        $this->openWindow($conversation);
        $holder = $this->fakeWhatsappDriver();

        $this->withToken($token)
            ->post('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'type' => 'image',
                'caption' => 'Product shot',
                'attachment' => UploadedFile::fake()->image('photo.jpg', 800, 600),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 'sent');

        $this->assertNotNull($holder->message);
        $this->assertSame('image', $holder->message->type);
        $this->assertSame('MEDIA-ID-123', $holder->message->payload['media_id']);
        $this->assertSame('Product shot', $holder->message->payload['caption']);
        $this->assertSame('Product shot', $holder->message->body);
    }

    // ─── media_url (no upload), Cloud API account ────────────────────────────

    public function test_media_url_is_passed_through_as_link_without_upload(): void
    {
        Storage::fake('public');
        Http::fake();

        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->contact($ws->id);
        $cloud = $this->cloudAccount($ws->id);
        $conversation = Conversation::create([
            'workspace_id' => $ws->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $cloud->id,
            'status' => 'open',
        ]);
        $this->openWindow($conversation);
        $holder = $this->fakeWhatsappDriver();

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'type' => 'document',
                'media_url' => 'https://example.com/invoice.pdf',
                'filename' => 'invoice-2026.pdf',
            ])
            ->assertOk();

        $this->assertSame('document', $holder->message->type);
        $this->assertSame('https://example.com/invoice.pdf', $holder->message->payload['link']);
        $this->assertSame('invoice-2026.pdf', $holder->message->payload['filename']);
        Http::assertNothingSent();
    }

    // ─── WAHA account sends media by URL, never uploads ──────────────────────

    public function test_waha_account_sends_uploaded_file_as_public_link(): void
    {
        Storage::fake('public');
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->contact($ws->id);
        $this->wahaAccount($ws->id);
        $holder = $this->fakeWhatsappDriver();

        $this->withToken($token)
            ->post('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'type' => 'image',
                'attachment' => UploadedFile::fake()->image('pic.png'),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame('image', $holder->message->type);
        $this->assertArrayHasKey('link', $holder->message->payload);
        $this->assertStringContainsString('message-media/', $holder->message->payload['link']);
    }

    // ─── validation ─────────────────────────────────────────────────────────

    public function test_media_type_without_attachment_or_url_returns_422(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->contact($ws->id);
        $this->cloudAccount($ws->id);

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'type' => 'image',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachment');
    }

    public function test_media_on_sms_channel_returns_422(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->contact($ws->id);

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'sms',
                'type' => 'image',
                'media_url' => 'https://example.com/x.jpg',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Media messages are only supported on the whatsapp channel.');
    }

    public function test_disallowed_file_extension_returns_422(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->contact($ws->id);
        $this->cloudAccount($ws->id);

        $this->withToken($token)
            ->post('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'type' => 'document',
                'attachment' => UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }
}
