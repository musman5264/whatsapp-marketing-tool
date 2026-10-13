<?php

namespace Tests\Feature\Webhooks;

use App\Listeners\DispatchOutboundWebhookListener;
use App\Models\Media;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The message.received payload sent to webhook subscribers must say who sent the message
 * and whether it carries media. A receiver (for example esystematics) needs both to tell
 * a staff ticket reply from other messages.
 */
class MessageReceivedPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function inboundMessage(?Contact $contact, ?int $mediaId = null, string $body = 'REPLY-TK-0004\nHI', string $type = 'text', ?string $provider = null): Message
    {
        $message = new Message([
            'conversation_id' => 9,
            'direction' => 'inbound',
            'channel' => 'whatsapp',
            'type' => $type,
            'body' => $body,
            'media_id' => $mediaId,
        ]);
        $message->id = 5;

        $conversation = new Conversation(['contact_id' => $contact?->id]);
        $conversation->id = 9;
        $conversation->setRelation('contact', $contact);
        // The placeholder applies only to WhatsApp Web accounts, so the channel account is part of the fixture.
        $conversation->setRelation(
            'channelAccount',
            $provider === null ? null : new ChannelAccount(['channel' => 'whatsapp', 'provider' => $provider]),
        );

        $message->setRelation('conversation', $conversation);

        return $message;
    }

    /** The sender lookup reads the contact row, so the contact must be saved. */
    private function savedContact(string $phone): Contact
    {
        $workspace = Workspace::factory()->create();

        return Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'phone_e164' => $phone,
        ]);
    }

    public function test_text_message_payload_includes_the_sender_number_and_body(): void
    {
        $contact = $this->savedContact('+923001234567');

        $payload = app(DispatchOutboundWebhookListener::class)
            ->messageReceivedPayload($this->inboundMessage($contact, null, "REPLY-TK-0004\nHI this is the reply", 'text'));

        $this->assertSame('message.received', $payload['event']);
        $this->assertSame('+923001234567', $payload['message']['from']);
        $this->assertSame("REPLY-TK-0004\nHI this is the reply", $payload['message']['body']);
        $this->assertNull($payload['message']['media']);
    }

    public function test_image_message_payload_includes_the_media_type(): void
    {
        $media = Media::factory()->create(['mime_type' => 'image/jpeg']);
        $contact = $this->savedContact('+923001234567');

        $payload = app(DispatchOutboundWebhookListener::class)
            ->messageReceivedPayload($this->inboundMessage($contact, $media->id, 'REPLY-TK-0004', 'image'));

        // A media-row attachment has its id and MIME type; filename and link are not filled in here.
        $this->assertSame([
            'id' => $media->id,
            'mime_type' => 'image/jpeg',
            'filename' => null,
            'url' => null,
        ], $payload['message']['media']);
    }

    public function test_photo_without_a_media_row_gets_an_empty_media_placeholder(): void
    {
        $contact = $this->savedContact('+923001234567');

        $payload = app(DispatchOutboundWebhookListener::class)
            ->messageReceivedPayload($this->inboundMessage($contact, null, 'REPLY-TK-0004', 'image', 'whatsapp_web'));

        // WhatsApp Web photos are stored as files, not media rows. The placeholder lets the link be added at send time.
        $this->assertSame([
            'id' => null,
            'mime_type' => null,
            'filename' => null,
            'url' => null,
        ], $payload['message']['media']);
    }

    public function test_meta_cloud_image_without_a_media_row_gets_no_media_placeholder(): void
    {
        $contact = $this->savedContact('+923001234567');

        $payload = app(DispatchOutboundWebhookListener::class)
            ->messageReceivedPayload($this->inboundMessage($contact, null, 'REPLY-TK-0004', 'image', 'meta'));

        // The placeholder is for WhatsApp Web photos only. A Cloud API image without a media row stays null.
        $this->assertNull($payload['message']['media']);
    }

    public function test_sender_is_null_when_the_conversation_has_no_contact(): void
    {
        $payload = app(DispatchOutboundWebhookListener::class)
            ->messageReceivedPayload($this->inboundMessage(null));

        $this->assertNull($payload['message']['from']);
    }
}
