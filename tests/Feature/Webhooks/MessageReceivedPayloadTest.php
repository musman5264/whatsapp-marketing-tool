<?php

namespace Tests\Feature\Webhooks;

use App\Listeners\DispatchOutboundWebhookListener;
use App\Models\Media;
use App\Models\Workspace;
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

    private function inboundMessage(?Contact $contact, ?int $mediaId = null, string $body = 'REPLY-TK-0004\nHI'): Message
    {
        $message = new Message([
            'conversation_id' => 9,
            'direction' => 'inbound',
            'channel' => 'whatsapp',
            'body' => $body,
            'media_id' => $mediaId,
        ]);
        $message->id = 5;

        $conversation = new Conversation(['contact_id' => $contact?->id]);
        $conversation->id = 9;
        $conversation->setRelation('contact', $contact);

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
            ->messageReceivedPayload($this->inboundMessage($contact, null, "REPLY-TK-0004\nHI this is the reply"));

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
            ->messageReceivedPayload($this->inboundMessage($contact, $media->id, 'REPLY-TK-0004'));

        $this->assertSame($media->id, $payload['message']['media']['id']);
        $this->assertSame('image/jpeg', $payload['message']['media']['mime_type']);
    }

    public function test_sender_is_null_when_the_conversation_has_no_contact(): void
    {
        $payload = app(DispatchOutboundWebhookListener::class)
            ->messageReceivedPayload($this->inboundMessage(null));

        $this->assertNull($payload['message']['from']);
    }
}
