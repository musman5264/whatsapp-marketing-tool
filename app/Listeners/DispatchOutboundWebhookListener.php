<?php

namespace App\Listeners;

use App\Events\CampaignCompleted;
use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Models\Media;
use App\Models\User;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Message;
use App\Services\WebhookDispatchService;

/**
 * Fan-out platform events to registered outbound webhook endpoints.
 */
class DispatchOutboundWebhookListener
{
    public function __construct(private readonly WebhookDispatchService $webhookService) {}

    public function handleContactCreated(ContactCreated $event): void
    {
        $contact = $event->contact;
        $user = User::where('workspace_id', $contact->workspace_id)->first();
        if (! $user) {
            return;
        }

        $this->webhookService->dispatch($user, 'contact.created', [
            'event' => 'contact.created',
            'contact' => [
                'id' => $contact->id,
                'phone_e164' => $contact->phone_e164,
                'email' => $contact->email,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
            ],
        ]);
    }

    public function handleMessageReceived(MessageReceived $event): void
    {
        $message = $event->message;
        $conversation = $message->conversation;
        if (! $conversation) {
            return;
        }

        $user = User::where('workspace_id', $conversation->workspace_id)->first();
        if (! $user) {
            return;
        }

        $this->webhookService->dispatch($user, 'message.received', $this->messageReceivedPayload($message));
    }

    /**
     * What subscribers receive for a message. `from` is the sender's number as stored on the contact (null when
     * unknown). `media` is null for text, or the media id and MIME type for an attachment.
     *
     * @return array<string, mixed>
     */
    public function messageReceivedPayload(Message $message): array
    {
        $media = $message->media_id ? Media::find($message->media_id) : null;

        $contactId = $message->conversation?->getAttribute('contact_id');
        $from = $contactId ? Contact::query()->whereKey($contactId)->value('phone_e164') : null;

        return [
            'event' => 'message.received',
            'message' => [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'direction' => $message->direction,
                'channel' => $message->channel,
                'body' => $message->body,
                'sent_at' => $message->sent_at?->toIso8601String(),
                'from' => $from,
                'media' => $media ? ['id' => $media->id, 'mime_type' => $media->mime_type] : null,
            ],
        ];
    }

    public function handleCampaignCompleted(CampaignCompleted $event): void
    {
        $campaign = $event->campaign;
        $user = User::where('workspace_id', $campaign->workspace_id)->first();
        if (! $user) {
            return;
        }

        $this->webhookService->dispatch($user, 'campaign.completed', [
            'event' => 'campaign.completed',
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'channel' => $campaign->channel,
                'status' => $campaign->status,
                'totals' => $campaign->totals_json ?? [],
            ],
        ]);
    }
}
