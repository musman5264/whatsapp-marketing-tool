<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan media:fix-links` rewrites WhatsApp Web media links that were built with the
 * conversation's integer id (5e7137e to 6282223). The login route binds the conversation by
 * its uuid, so those links returned 404. Without --apply it changes nothing.
 */
class FixMediaLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    /** A photo saved with the broken link: the route is built from the integer conversation id. */
    private function brokenPhoto(): Message
    {
        $message = $this->photo();
        $broken = route('client.inbox.message-media', ['conversation' => $this->conversation->id, 'message' => $message->id]);

        $message->update(['payload' => $this->payload($message, $broken)]);

        return $message->fresh();
    }

    /** A photo whose link already uses the uuid route, as new links do. */
    private function workingPhoto(): Message
    {
        $message = $this->photo();
        $message->update(['payload' => $this->payload($message, $this->loginUrl($message))]);

        return $message->fresh();
    }

    private function photo(): Message
    {
        return Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'channel' => 'whatsapp',
            'type' => 'image',
            'payload' => [],
            'body' => '',
            'sent_at' => now(),
        ]);
    }

    /** The WAHA engine link under the type key must never be touched: only our own login links are fixed. */
    private function payload(Message $message, string $previewUrl): array
    {
        return [
            'id' => 'wa-'.$message->id,
            'mime_type' => 'image/jpeg',
            'media_path' => 'message-media/'.$message->id.'.jpg',
            'preview_url' => $previewUrl,
            'image' => ['link' => 'https://waha.example/api/files/photo.jpg'],
        ];
    }

    private function loginUrl(Message $message): string
    {
        return route('client.inbox.message-media', ['conversation' => $this->conversation, 'message' => $message->id]);
    }

    public function test_dry_run_lists_the_broken_link_and_changes_nothing(): void
    {
        $broken = $this->brokenPhoto();
        $before = $broken->payload['preview_url'];

        $this->artisan('media:fix-links')
            ->expectsOutputToContain('Found 1 message(s)')
            ->assertSuccessful();

        $this->assertSame($before, $broken->fresh()->payload['preview_url']);
    }

    public function test_apply_points_the_broken_link_at_the_uuid_route(): void
    {
        $broken = $this->brokenPhoto();

        $this->artisan('media:fix-links --apply')
            ->expectsOutputToContain('Fixed 1 message(s).')
            ->assertSuccessful();

        $payload = $broken->fresh()->payload;
        $this->assertSame($this->loginUrl($broken), $payload['preview_url']);
        $this->assertStringContainsString($this->conversation->uuid, $payload['preview_url']);
        $this->assertSame('https://waha.example/api/files/photo.jpg', $payload['image']['link']);
    }

    public function test_working_links_are_left_alone_and_a_second_run_changes_nothing(): void
    {
        $broken = $this->brokenPhoto();
        $working = $this->workingPhoto();
        $workingBefore = $working->payload;

        $this->artisan('media:fix-links --apply')->assertSuccessful();

        $this->assertSame($workingBefore, $working->fresh()->payload);

        $this->artisan('media:fix-links')
            ->expectsOutputToContain('Found 0 message(s)')
            ->assertSuccessful();

        $this->assertSame($this->loginUrl($broken), $broken->fresh()->payload['preview_url']);
    }
}
