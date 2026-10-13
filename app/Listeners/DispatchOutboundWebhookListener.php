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
    /** Message types that can carry a file. */
    private const MEDIA_TYPES = ['image', 'video', 'audio', 'document'];

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
     * unknown). `media` is null for text. For a media row it is the id and MIME type. For a WhatsApp Web file
     * (no media row) it is a placeholder whose url is filled in by WebhookMediaLinks when the webhook is sent.
     *
     * @return array<string, mixed>
     */
    public function messageReceivedPayload(Message $message): array
    {
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
                'media' => $this->mediaPayload($message),
            ],
        ];
    }

    /**
     * @return array{id: int|null, mime_type: string|null, filename: string|null, url: string|null}|null
     */
    private function mediaPayload(Message $message): ?array
    {
        $media = $message->media_id ? Media::find($message->media_id) : null;
        if ($media) {
            return ['id' => $media->id, 'mime_type' => $media->mime_type, 'filename' => null, 'url' => null];
        }

        // Only WhatsApp Web files are stored without a media row. Other providers keep media null.
        $isWhatsappWeb = $message->conversation?->getAttribute('channelAccount')?->provider === 'whatsapp_web';
        if ($isWhatsappWeb && in_array($message->getAttribute('type'), self::MEDIA_TYPES, true)) {
            // The placeholder is filled in with the signed link when the webhook is sent.
            return ['id' => null, 'mime_type' => null, 'filename' => null, 'url' => null];
        }

        return null;
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
