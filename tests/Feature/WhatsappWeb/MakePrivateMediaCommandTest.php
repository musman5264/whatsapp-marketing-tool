<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Services\WahaMediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * T-0029: `php artisan media:make-private` moves old WhatsApp Web media from the public disk
 * (served at /storage/message-media/...) to the private local disk and points the message
 * links at the login route. Without --apply it changes nothing.
 *
 * Both disks are faked, so the real storage folders are never touched.
 */
class MakePrivateMediaCommandTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    /** Set only by the save-failure test: saving this message throws. */
    private ?int $failingMessageId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');

        $ctx = $this->createWorkspaceContext();
        $workspace = $ctx['workspace'];

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

        // Does nothing unless a test sets $failingMessageId, so it cannot affect other tests.
        Message::saving(function (Message $message) {
            if ($this->failingMessageId !== null && (int) $message->id === $this->failingMessageId) {
                throw new RuntimeException('Simulated failure while saving the message.');
            }
        });
    }

    protected function tearDown(): void
    {
        $this->failingMessageId = null;
        parent::tearDown();
    }

    /** A WhatsApp Web photo stored the old way: preview_url and link point at the public path. */
    private function oldPhoto(): Message
    {
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'channel' => 'whatsapp',
            'type' => 'image',
            'payload' => [],
            'body' => '',
            'sent_at' => now(),
        ]);

        $publicUrl = '/storage/'.$this->mediaPath($message);
        $message->update(['payload' => [
            'id' => 'wa-'.$message->id,
            'caption' => 'Holiday photo',
            'mime_type' => 'image/jpeg',
            'preview_url' => $publicUrl,
            'link' => $publicUrl,
        ]]);

        return $message->fresh();
    }

    /** Relative path of a message's file, as the command names it: message-media/{id}.{ext}. */
    private function mediaPath(Message $message): string
    {
        return "message-media/{$message->id}.jpg";
    }

    private function loginUrl(Message $message): string
    {
        return route('client.inbox.message-media', ['conversation' => $message->conversation_id, 'message' => $message->id]);
    }

    /** A photo sent from the app and saved under a random hash name: the public file is message-media/{hash}.jpg and the message links to it. */
    private function hashNamedPhoto(string $hash): Message
    {
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'image',
            'payload' => [],
            'body' => '',
            'sent_at' => now(),
        ]);

        $publicUrl = "/storage/message-media/{$hash}.jpg";
        $message->update(['payload' => [
            'caption' => 'Sent from the app',
            'mime_type' => 'image/jpeg',
            'preview_url' => $publicUrl,
            'link' => $publicUrl,
        ]]);

        return $message->fresh();
    }

    #[Test]
    public function dry_run_changes_nothing_and_lists_the_file(): void
    {
        $message = $this->oldPhoto();
        $path = $this->mediaPath($message);
        Storage::disk('public')->put($path, 'PUBLIC-PHOTO');
        $before = $message->payload;

        $this->artisan('media:make-private')
            ->expectsOutputToContain("{$message->id}.jpg")
            ->assertExitCode(0);

        Storage::disk('public')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
        $this->assertEqualsCanonicalizing($before, $message->fresh()->payload);
    }

    #[Test]
    public function apply_copies_the_file_to_the_private_disk_and_removes_the_public_copy(): void
    {
        $message = $this->oldPhoto();
        $path = $this->mediaPath($message);
        Storage::disk('public')->put($path, 'PUBLIC-PHOTO-BYTES');

        $this->artisan('media:make-private', ['--apply' => true])->assertExitCode(0);

        Storage::disk('local')->assertExists($path);
        $this->assertSame('PUBLIC-PHOTO-BYTES', Storage::disk('local')->get($path));
        Storage::disk('public')->assertMissing($path);
    }

    #[Test]
    public function apply_points_the_links_at_the_login_route_and_keeps_the_other_payload_keys(): void
    {
        $message = $this->oldPhoto();
        Storage::disk('public')->put($this->mediaPath($message), 'PUBLIC-PHOTO');

        $this->artisan('media:make-private', ['--apply' => true])->assertExitCode(0);

        $payload = $message->fresh()->payload;
        $this->assertSame($this->loginUrl($message), $payload['preview_url']);
        $this->assertSame($this->loginUrl($message), $payload['link']);
        $this->assertSame($this->mediaPath($message), $payload['media_path']);
        // Keys the command must leave alone.
        $this->assertSame('wa-'.$message->id, $payload['id']);
        $this->assertSame('image/jpeg', $payload['mime_type']);
        $this->assertSame('Holiday photo', $payload['caption']);
    }

    #[Test]
    public function after_apply_the_stored_copy_is_found_for_its_message(): void
    {
        $message = $this->oldPhoto();
        Storage::disk('public')->put($this->mediaPath($message), 'PUBLIC-PHOTO');

        $this->artisan('media:make-private', ['--apply' => true])->assertExitCode(0);

        $fresh = $message->fresh();
        $this->assertSame(
            $this->mediaPath($message),
            app(WahaMediaStore::class)->existingPath($fresh, $fresh->payload),
        );
    }

    #[Test]
    public function running_apply_twice_moves_nothing_on_the_second_run(): void
    {
        $message = $this->oldPhoto();
        $path = $this->mediaPath($message);
        Storage::disk('public')->put($path, 'PUBLIC-PHOTO');

        $this->artisan('media:make-private', ['--apply' => true])->assertExitCode(0);
        $afterFirstRun = $message->fresh()->payload;

        $this->artisan('media:make-private', ['--apply' => true])->assertExitCode(0);

        Storage::disk('public')->assertMissing($path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('PUBLIC-PHOTO', Storage::disk('local')->get($path));
        $this->assertEqualsCanonicalizing($afterFirstRun, $message->fresh()->payload);
    }

    #[Test]
    public function a_different_size_file_already_on_the_private_disk_is_not_overwritten(): void
    {
        $message = $this->oldPhoto();
        $path = $this->mediaPath($message);
        Storage::disk('public')->put($path, 'PUBLIC-PHOTO-BYTES-LONGER');
        Storage::disk('local')->put($path, 'OLD');
        $before = $message->payload;

        $this->artisan('media:make-private', ['--apply' => true])->assertExitCode(1);

        // The private copy is left as it was, and the public copy is kept.
        Storage::disk('local')->assertExists($path);
        $this->assertSame('OLD', Storage::disk('local')->get($path));
        Storage::disk('public')->assertExists($path);
        $this->assertSame('PUBLIC-PHOTO-BYTES-LONGER', Storage::disk('public')->get($path));
        // Links are not rewritten to a login route that would serve the wrong file.
        $this->assertEqualsCanonicalizing($before, $message->fresh()->payload);
    }

    #[Test]
    public function files_outside_message_media_are_not_touched(): void
    {
        $message = $this->oldPhoto();
        Storage::disk('public')->put($this->mediaPath($message), 'PUBLIC-PHOTO');
        Storage::disk('public')->put('logos/x.png', 'LOGO-BYTES');

        $this->artisan('media:make-private', ['--apply' => true])->assertExitCode(0);

        // The photo was moved, so the run did its work; the logo is still exactly where it was.
        Storage::disk('local')->assertExists($this->mediaPath($message));
        Storage::disk('public')->assertExists('logos/x.png');
        $this->assertSame('LOGO-BYTES', Storage::disk('public')->get('logos/x.png'));
        Storage::disk('local')->assertMissing('logos/x.png');
    }

    #[Test]
    public function a_failed_message_save_keeps_the_public_copy_and_exits_1(): void
    {
        $message = $this->oldPhoto();
        $path = $this->mediaPath($message);
        Storage::disk('public')->put($path, 'PUBLIC-PHOTO');
        $this->failingMessageId = (int) $message->id;

        $this->artisan('media:make-private', ['--apply' => true])->assertExitCode(1);

        Storage::disk('public')->assertExists($path);
        $this->assertSame('PUBLIC-PHOTO', Storage::disk('public')->get($path));
    }

    #[Test]
    public function a_hash_named_outbound_photo_moves_to_its_message_id_and_its_links_are_rewritten(): void
    {
        $hash = '9f2c4e7a1b8d3e6f0a5c2b9d8e7f6a1b3c4d5e6f';
        $message = $this->hashNamedPhoto($hash);
        Storage::disk('public')->put("message-media/{$hash}.jpg", 'SENT-PHOTO-BYTES');

        $this->artisan('media:make-private', ['--apply' => true])->assertExitCode(0);

        // The private copy is named after the message id, not the hash.
        Storage::disk('local')->assertExists($this->mediaPath($message));
        $this->assertSame('SENT-PHOTO-BYTES', Storage::disk('local')->get($this->mediaPath($message)));
        Storage::disk('public')->assertMissing("message-media/{$hash}.jpg");

        $payload = $message->fresh()->payload;
        $this->assertSame($this->loginUrl($message), $payload['preview_url']);
        $this->assertSame($this->loginUrl($message), $payload['link']);
        $this->assertSame($this->mediaPath($message), $payload['media_path']);
        $this->assertSame('image/jpeg', $payload['mime_type']);

        $fresh = $message->fresh();
        $this->assertSame(
            $this->mediaPath($message),
            app(WahaMediaStore::class)->existingPath($fresh, $fresh->payload),
        );
    }

    #[Test]
    public function an_orphan_hash_named_file_keeps_its_own_path(): void
    {
        // No message links to this file, so nothing names it after a message id.
        $hash = 'c4e8a2d6f0b1397e5a8c4d2f6b0e9a7d1c3f5b8e';
        $path = "message-media/{$hash}.jpg";
        Storage::disk('public')->put($path, 'ORPHAN-PHOTO-BYTES');

        $this->artisan('media:make-private', ['--apply' => true])->assertExitCode(0);

        Storage::disk('local')->assertExists($path);
        $this->assertSame('ORPHAN-PHOTO-BYTES', Storage::disk('local')->get($path));
        Storage::disk('public')->assertMissing($path);
    }
}
