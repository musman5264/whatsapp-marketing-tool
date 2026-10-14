<?php

namespace Tests\Feature\Inbox;

use App\Models\User;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Outbound composer images (task T-0031).
 *
 * A composer image must be stored under its message id ("message-media/{id}.jpg"), and its
 * payload must point at the login-checked route, so the chat can show it and WAHA can fetch
 * it through a signed link. The reply controller currently stores a random hash name and
 * sets no preview_url, so these tests are expected to FAIL until that is fixed.
 */
class OutboundMediaNamingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://waha.test:3000';

    private User $user;

    private int $workspaceId;

    private Conversation $wahaConversation;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake disks: nothing is written to the real storage/app/private or storage/app/public.
        $local = Storage::fake('local');
        Storage::fake('public');

        // Storage::fake() stubs temporaryUrl() with an unsigned URL. Build it the way
        // LocalFilesystemAdapter::getTemporaryUrl() does: a relative signature (ServeFile checks
        // hasValidRelativeSignature), prefixed with the app URL. The link WAHA receives can then be
        // requested and checked.
        $local->buildTemporaryUrlsUsing(function (string $path, \DateTimeInterface $expiration): string {
            return URL::to(URL::temporarySignedRoute('storage.local', $expiration, ['path' => $path], absolute: false));
        });

        Http::preventStrayRequests();

        $ctx = $this->createWorkspaceContext();
        $this->user = $ctx['user'];
        $this->workspaceId = $ctx['workspace']->id;

        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'label' => 'WAHA',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => self::BASE, 'api_key' => 'waha-secret-key-123'],
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => $this->workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
            'phone_number_id' => 'wa-web-test',
        ]);

        $this->wahaConversation = Conversation::create([
            'workspace_id' => $this->workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => $this->contact('+8801700000009')->id,
            'status' => 'open',
        ]);
    }

    #[Test]
    public function a_whatsapp_web_composer_image_is_stored_under_its_message_id(): void
    {
        $this->fakeWahaSendOk();

        $this->replyWithImage($this->wahaConversation, UploadedFile::fake()->image('photo.jpg', 120, 90));

        $message = $this->lastOutbound($this->wahaConversation);
        $expected = "message-media/{$message->id}.jpg";

        $this->assertSame(
            [$expected],
            Storage::disk('local')->allFiles('message-media'),
            'Only the message-named file should be stored, not a file under a random hash name.'
        );
        Storage::disk('local')->assertExists($expected);
    }

    #[Test]
    public function the_whatsapp_web_message_points_at_its_message_named_file_and_login_route(): void
    {
        $this->fakeWahaSendOk();

        $this->replyWithImage($this->wahaConversation, UploadedFile::fake()->image('photo.jpg', 120, 90));

        $message = $this->lastOutbound($this->wahaConversation);
        $this->assertSame("message-media/{$message->id}.jpg", $message->payload['media_path'] ?? null);
        $this->assertSame(
            $this->loginMediaUrl($this->wahaConversation, $message),
            $message->payload['preview_url'] ?? null
        );
    }

    #[Test]
    public function the_login_route_serves_a_whatsapp_web_composer_image_to_its_workspace_user(): void
    {
        $this->fakeWahaSendOk();
        $image = UploadedFile::fake()->image('photo.jpg', 120, 90);
        $bytes = file_get_contents($image->getRealPath());

        $this->replyWithImage($this->wahaConversation, $image);
        $message = $this->lastOutbound($this->wahaConversation);

        $response = $this->actingAs($this->user)
            ->get($this->loginMediaUrl($this->wahaConversation, $message));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($bytes, $response->streamedContent());
    }

    #[Test]
    public function the_waha_send_gets_a_signed_link_to_the_message_named_file(): void
    {
        $this->fakeWahaSendOk();
        $image = UploadedFile::fake()->image('photo.jpg', 120, 90);
        $bytes = file_get_contents($image->getRealPath());

        $this->replyWithImage($this->wahaConversation, $image);
        $message = $this->lastOutbound($this->wahaConversation);
        $link = $message->payload['link'] ?? '';

        $this->assertNotSame('', $link, 'The message payload has no link for WAHA to fetch.');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/sendImage')
            && ($request->data()['file']['url'] ?? null) === $link);

        // The link must name the message's own file, not a random hash name.
        $this->assertStringEndsWith(
            "/message-media/{$message->id}.jpg",
            parse_url($link, PHP_URL_PATH) ?: ''
        );

        // The link works now: WAHA fetches it once and gets the file's bytes.
        $served = $this->get($link);
        $served->assertOk();
        $this->assertSame($bytes, $served->streamedContent());
    }

    #[Test]
    public function a_cloud_api_composer_image_points_at_its_message_named_file_and_keeps_its_media_id(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA-ID-123'], 200),
            'https://graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
        ]);

        WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $this->workspaceId,
            'status' => 'active',
            'credentials' => ['system_user_token' => 'test-token'],
        ]);

        $cloud = ChannelAccount::create([
            'workspace_id' => $this->workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'Cloud number',
            'status' => 'active',
            'phone_number_id' => '111222333',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $this->workspaceId,
            'channel_account_id' => $cloud->id,
            'contact_id' => $this->contact('+8801700000010')->id,
            'status' => 'open',
        ]);

        // The Cloud API only accepts a free-form reply inside the 24-hour window, which an inbound message opens.
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

        $this->replyWithImage($conversation, UploadedFile::fake()->image('photo.jpg', 120, 90));

        $message = $this->lastOutbound($conversation);
        $this->assertSame("message-media/{$message->id}.jpg", $message->payload['media_path'] ?? null);
        $this->assertSame(
            $this->loginMediaUrl($conversation, $message),
            $message->payload['preview_url'] ?? null
        );
        $this->assertSame('MEDIA-ID-123', $message->payload['media_id'] ?? null);
    }

    #[Test]
    public function the_message_is_kept_and_the_reply_does_not_fail_when_the_waha_send_fails(): void
    {
        $this->fakeWahaSendFails();

        $response = $this->replyWithImage($this->wahaConversation, UploadedFile::fake()->image('photo.jpg', 120, 90));

        $this->assertLessThan(500, $response->getStatusCode());

        $message = $this->lastOutbound($this->wahaConversation);
        $expected = "message-media/{$message->id}.jpg";
        Storage::disk('local')->assertExists($expected);
        $this->assertSame($expected, $message->payload['media_path'] ?? null);
        $this->assertSame(
            $this->loginMediaUrl($this->wahaConversation, $message),
            $message->payload['preview_url'] ?? null
        );
    }

    private function contact(string $phone): Contact
    {
        return Contact::factory()->create([
            'workspace_id' => $this->workspaceId,
            'phone_e164' => $phone,
        ]);
    }

    private function fakeWahaSendOk(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['id' => 'waha-msg-1'], 200)]);
    }

    private function fakeWahaSendFails(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('engine error', 500)]);
    }

    private function replyWithImage(Conversation $conversation, UploadedFile $image): TestResponse
    {
        return $this->actingAs($this->user)->post(
            route('client.inbox.reply', ['conversation' => $conversation]),
            ['attachment' => $image],
        );
    }

    private function lastOutbound(Conversation $conversation): Message
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'out')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /** The login-checked address the inbox uses for a message's media. */
    private function loginMediaUrl(Conversation $conversation, Message $message): string
    {
        return route('client.inbox.message-media', ['conversation' => $conversation, 'message' => $message]);
    }
}
