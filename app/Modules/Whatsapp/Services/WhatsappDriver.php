<?php

namespace App\Modules\Whatsapp\Services;

use App\Events\MessageReceived;
use App\Events\MessageStatusUpdated;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ContactService;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\WhatsappWeb\Services\EngineManager;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappDriver implements ChannelDriverInterface
{
    public function __construct(
        private readonly ContactService $contactService,
    ) {}

    public function send(Message $message): string
    {
        $conversation = $message->conversation;
        $contact = $conversation->contact;
        $phone = $contact->phone_e164;

        // WhatsApp Web (personal number via WAHA) — a distinct provider under the
        // same `whatsapp` channel. No Cloud API, no templates.
        if ($conversation->channelAccount?->provider === 'whatsapp_web') {
            return $this->sendViaWhatsappWeb($conversation, $message, (string) $phone);
        }

        // Prefer the phone number tied to this conversation's channel account so
        // outbound replies go from the same number the customer wrote to.
        $phoneNumberId = $conversation->channelAccount?->phone_number_id;
        $client = $phoneNumberId
            ? CloudApiClient::forPhoneNumber($phoneNumberId, $conversation->workspace_id)
            : null;
        $client ??= CloudApiClient::forWorkspace($conversation->workspace_id);

        if (! $client) {
            throw new \RuntimeException('No active WhatsApp account for workspace.');
        }

        $payload = $message->payload ?? [];

        $resp = match ($message->type) {
            'template' => $client->sendTemplate($phone, $payload['template']['name'] ?? '', $payload['template']['language'] ?? 'en', $payload['template']['components'] ?? []),
            'interactive' => $client->sendInteractive($phone, $payload['interactive'] ?? []),
            'image' => $client->sendMedia($phone, 'image', $payload['media_id'] ?? '', $payload['caption'] ?? null, null, $payload['link'] ?? null),
            'video' => $client->sendMedia($phone, 'video', $payload['media_id'] ?? '', $payload['caption'] ?? null, null, $payload['link'] ?? null),
            'document' => $client->sendMedia($phone, 'document', $payload['media_id'] ?? '', $payload['caption'] ?? null, $payload['filename'] ?? null, $payload['link'] ?? null),
            'audio' => $client->sendMedia($phone, 'audio', $payload['media_id'] ?? ''),
            'location' => $client->sendLocation(
                $phone,
                (float) ($payload['location']['latitude'] ?? 0),
                (float) ($payload['location']['longitude'] ?? 0),
                $payload['location']['name'] ?? null,
                $payload['location']['address'] ?? null,
            ),
            default => $client->sendText($phone, $message->body ?? ''),
        };

        if (! $resp->successful()) {
            throw new \RuntimeException('WhatsApp send failed: '.$resp->body());
        }

        return $resp->json('messages.0.id', '');
    }

    /**
     * Outbound send for the `whatsapp_web` provider (WAHA engine). Media is sent
     * by public URL (payload.link / payload.preview_url) — the inbox/mobile
     * controllers skip the Meta media upload for this provider. Templates and
     * interactive messages are not supported on a personal number.
     */
    private function sendViaWhatsappWeb(Conversation $conversation, Message $message, string $phone): string
    {
        $session = $conversation->channelAccount?->phone_number_id;
        if (! $session) {
            throw new \RuntimeException('This conversation has no linked WhatsApp Web session.');
        }

        $adapter = app(EngineManager::class)->adapter();
        $payload = $message->payload ?? [];
        $caption = $payload['caption'] ?? $message->body;
        $link = $payload['link'] ?? $payload['preview_url'] ?? null;

        return match ($message->type) {
            'template', 'interactive' => throw new \RuntimeException('Templates and interactive messages are not supported on a WhatsApp Web (personal) number. Send plain text or media instead.'),
            'image', 'video', 'document', 'audio' => $link
                ? $adapter->sendMedia($session, $phone, $message->type, (string) $link, $caption, $payload['filename'] ?? null)
                : throw new \RuntimeException('No downloadable URL for this media message.'),
            'location' => $adapter->sendLocation(
                $session,
                $phone,
                (float) ($payload['location']['latitude'] ?? 0),
                (float) ($payload['location']['longitude'] ?? 0),
                $payload['location']['name'] ?? null,
                $payload['location']['address'] ?? null,
            ),
            default => $adapter->sendText($session, $phone, $message->body ?? ''),
        };
    }

    /**
     * Ingest one already-normalised inbound message (Meta `value`/`msg` shape).
     * Used by the WhatsApp Web engine adapter, which produces this shape from
     * its own webhook payload rather than the Meta `entry.changes` envelope.
     *
     * @param  array<string,mixed>  $value
     * @param  array<string,mixed>  $msg
     */
    public function ingestNormalizedInbound(array $value, array $msg): Message
    {
        return $this->processInboundMessage($value, $msg);
    }

    /**
     * Public entry for engine status callbacks (WAHA `message.ack`).
     *
     * @param  array<string,mixed>  $status
     */
    public function applyStatusUpdate(array $status): void
    {
        $this->processStatusUpdate($status);
    }

    public function receiveWebhook(Request $request): array
    {
        return $this->processWebhookPayload($request->all());
    }

    public function processWebhookPayload(array $payload, string $verifyToken = ''): array
    {
        $processed = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            $wabaId = (string) ($entry['id'] ?? '');

            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? '';
                $value = $change['value'] ?? [];

                if ($field === 'message_template_status_update') {
                    $this->processTemplateStatusUpdate($wabaId, $value);

                    continue;
                }

                if (in_array($field, ['phone_number_quality_update', 'phone_number_name_update', 'account_update'], true)) {
                    $this->processPhoneNumberUpdate($value);

                    continue;
                }

                foreach ($value['messages'] ?? [] as $msg) {
                    try {
                        $processed[] = $this->processInboundMessage($value, $msg);
                    } catch (\Throwable $e) {
                        Log::error('WhatsApp webhook processing failed', ['error' => $e->getMessage(), 'msg' => $msg]);
                    }
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->processStatusUpdate($status);
                }
            }
        }

        return $processed;
    }

    private function processTemplateStatusUpdate(string $wabaId, array $value): void
    {
        $event = strtoupper((string) ($value['event'] ?? ''));
        $name = $value['message_template_name'] ?? null;
        $language = $value['message_template_language'] ?? 'en';

        if (! $wabaId || ! $name || ! $event) {
            return;
        }

        $statusMap = [
            'APPROVED' => 'APPROVED',
            'REJECTED' => 'REJECTED',
            'PENDING' => 'PENDING',
            'PAUSED' => 'PAUSED',
            'DISABLED' => 'PAUSED',
        ];
        $status = $statusMap[$event] ?? null;
        if (! $status) {
            return;
        }

        $reason = $value['reason'] ?? $value['rejection_reason'] ?? null;

        WhatsappTemplate::where('waba_id', $wabaId)
            ->where('name', $name)
            ->where('language', $language)
            ->update(array_filter([
                'status' => $status,
                'rejection_reason' => $status === 'REJECTED' ? (is_string($reason) ? $reason : json_encode($reason)) : null,
                'meta_template_id' => isset($value['message_template_id'])
                    ? (string) $value['message_template_id']
                    : null,
            ]));
    }

    private function processPhoneNumberUpdate(array $value): void
    {
        $phoneNumberId = $value['phone_number_id'] ?? null;
        if (! $phoneNumberId) {
            return;
        }

        // Map Meta's name decision to our name_status values
        $decision = strtoupper((string) ($value['decision'] ?? ''));
        $nameStatus = match ($decision) {
            'APPROVED' => 'APPROVED',
            'REJECTED' => 'DECLINED',
            default => null,
        };

        $patch = array_filter([
            'quality_rating' => $value['current_quality_rating'] ?? $value['quality_rating'] ?? null,
            'messaging_limit_tier' => $value['current_limit'] ?? $value['messaging_limit_tier'] ?? null,
            'display_phone' => $value['display_phone_number'] ?? null,
            // When a name is approved, verified_name updates to the new name
            'verified_name' => $nameStatus === 'APPROVED'
                                          ? ($value['requested_verified_name'] ?? $value['verified_name'] ?? null)
                                          : ($value['verified_name'] ?? null),
            'name_status' => $nameStatus,
            // Clear requested_verified_name once the decision is made
            'requested_verified_name' => in_array($nameStatus, ['APPROVED', 'DECLINED'], true) ? null : null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($patch === []) {
            return;
        }

        WhatsappPhoneNumber::where('phone_number_id', (string) $phoneNumberId)->update($patch);

        Log::info('whatsapp.phone_number.updated', [
            'phone_number_id' => $phoneNumberId,
            'patch' => $patch,
        ]);
    }

    public function verifyCreds(): bool
    {
        return true;
    }

    private function processInboundMessage(array $value, array $msg): Message
    {
        $msgId = $msg['id'] ?? null;

        // Idempotency guard — skip if already processed or being processed concurrently.
        // insertOrIgnore is atomic, so only one concurrent request gets affected=1.
        // If affected=0 (already seen), never fall through — throw so the outer
        // try-catch skips this duplicate without creating a second message or auto-reply.
        if ($msgId && ! app(WebhookIdempotencyService::class)->isNewEvent('whatsapp_msg', $msgId)) {
            $existing = Message::where('provider_message_id', $msgId)->first();
            if ($existing) {
                return $existing;
            }
            // Race condition: the first request hasn't committed the message yet.
            // Skip rather than fall through and create a duplicate.
            throw new \RuntimeException("Duplicate webhook skipped (concurrent): {$msgId}");
        }

        // TEMP DIAGNOSTIC (remove once incoming poll shape is confirmed):
        // log the raw payload of unsupported / error-bearing messages so we can
        // see exactly how WhatsApp delivers polls and other unsupported types.
        if (($msg['type'] ?? '') === 'unsupported' || ! empty($msg['errors'])) {
            Log::info('whatsapp.inbound.unsupported_payload', [
                'type' => $msg['type'] ?? null,
                'msg' => $msg,
            ]);
        }

        $phoneId = $value['metadata']['phone_number_id'] ?? '';
        $fromPhone = $msg['from'] ?? '';

        $channelAccount = ChannelAccount::where('phone_number_id', $phoneId)
            ->where('channel', 'whatsapp')
            ->first();

        if (! $channelAccount) {
            Log::warning('WhatsApp inbound dropped — no channel_account match', [
                'phone_number_id' => $phoneId,
                'from' => $fromPhone,
                'msg_id' => $msg['id'] ?? null,
                'hint' => 'The phone_number_id received from Meta does not exist in channel_accounts. Re-run the WhatsApp setup or verify the configured number id.',
            ]);

            // Skip persisting — without a workspace the message would be invisible
            // and would corrupt the inbox queries that filter by workspace_id.
            throw new \RuntimeException("No channel_account found for phone_number_id={$phoneId}");
        }

        $workspaceId = (int) $channelAccount->workspace_id;

        $contact = $this->contactService->upsert($workspaceId, [
            'phone_e164' => '+'.$fromPhone,
            'opt_in_whatsapp' => true,
            'source' => 'whatsapp_inbound',
        ]);

        $conversation = Conversation::firstOrCreate(
            ['workspace_id' => $workspaceId, 'contact_id' => $contact->id, 'channel_account_id' => $channelAccount?->id],
            ['status' => 'open', 'external_thread_id' => $fromPhone]
        );

        $type = $msg['type'] ?? 'text';
        $interactive = is_array($msg['interactive'] ?? null) ? $msg['interactive'] : [];
        $textBlock = is_array($msg['text'] ?? null) ? $msg['text'] : [];

        // Extract a human-readable body for every message type
        $body = ($textBlock['body'] ?? null)
            ?? (($msg['button'] ?? [])['text'] ?? null)
            ?? (($interactive['button_reply'] ?? [])['title'] ?? null)
            ?? (($interactive['list_reply'] ?? [])['title'] ?? null)
            ?? (is_array($msg[$type] ?? null) && ! isset($msg[$type][0]) ? ($msg[$type]['caption'] ?? null) : null)
            ?? ($msg['caption'] ?? null)
            ?? ($msg['errors'][0]['title'] ?? null);

        // Type-specific body fallbacks so conversation preview is meaningful
        if ($body === null || $body === '') {
            $body = match ($type) {
                'location' => implode(', ', array_filter([
                    $msg['location']['name'] ?? null,
                    $msg['location']['address'] ?? null,
                    isset($msg['location']['latitude'], $msg['location']['longitude'])
                        ? ($msg['location']['latitude'].','.$msg['location']['longitude'])
                        : null,
                ])) ?: '📍 Location',
                'contacts' => isset($msg['contacts'][0]['name']['formatted_name'])
                    ? ('👤 '.$msg['contacts'][0]['name']['formatted_name'])
                    : '👤 Contact',
                'poll' => '📊 '.($msg['poll']['question'] ?? ($msg['interactive']['poll_creation']['name'] ?? 'Poll')),
                'event' => '📅 '.($msg['event']['title'] ?? ($msg['event']['name'] ?? 'Event')),
                'image' => '🖼 Image',
                'video' => '🎬 Video',
                'audio' => '🎤 Audio',
                'document' => '📄 '.($msg['document']['filename'] ?? 'Document'),
                'sticker' => '😊 Sticker',
                'reaction' => $msg['reaction']['emoji'] ?? '👍',
                default => '',
            };
        }

        $allowedTypes = ['text', 'template', 'media', 'interactive', 'reaction', 'image', 'video',
            'document', 'audio', 'location', 'contacts', 'sticker', 'order', 'poll', 'event', 'unsupported'];

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => in_array($type, $allowedTypes, true) ? $type : 'unsupported',
            'payload' => $msg,
            'body' => $body,
            'status' => 'delivered',
            'provider_message_id' => $msg['id'] ?? null,
            'sent_by' => 'human',
            'sent_at' => now()->createFromTimestamp($msg['timestamp'] ?? time()),
        ]);

        $conversation->update([
            'last_message_at' => $message->sent_at,
            'status' => 'open',
            'unread_count' => $conversation->unread_count + 1,
            'last_inbound_at' => $message->sent_at,
            // If contact replies after we responded, reset first_response_at for next cycle
            'first_response_at' => $conversation->first_response_at && $conversation->last_inbound_at
                ? ($message->sent_at > $conversation->first_response_at ? null : $conversation->first_response_at)
                : $conversation->first_response_at,
        ]);

        // Fire typed event for automations / AI
        MessageReceived::dispatch($message);

        return $message;
    }

    private function processStatusUpdate(array $status): void
    {
        $providerId = $status['id'] ?? null;
        $newStatus = $status['status'] ?? null;

        if (! $providerId || ! $newStatus) {
            return;
        }

        $statusMap = ['sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed'];
        $mapped = $statusMap[$newStatus] ?? null;
        if (! $mapped) {
            return;
        }

        // Status priority — never downgrade (e.g. delivered -> sent).
        $priority = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        $newPriority = $priority[$mapped] ?? 0;

        // 1. Update inbox `messages` row for this wamid.
        $message = Message::where('provider_message_id', $providerId)->first();
        if ($message) {
            $current = $priority[$message->status] ?? 0;
            if ($newPriority >= $current) {
                $message->update(['status' => $mapped]);
                $message->load('conversation');
                MessageStatusUpdated::dispatch($message);
            }
        }

        // 2. Update campaign_recipients row for this wamid (separate table).
        $recipient = CampaignRecipient::where('provider_message_id', $providerId)->first();
        if ($recipient) {
            $current = $priority[$recipient->status] ?? 0;
            if ($newPriority < $current) {
                return;
            }

            $now = now();
            $patch = ['status' => $mapped];

            if ($mapped === 'sent' && ! $recipient->sent_at) {
                $patch['sent_at'] = $now;
            }
            if ($mapped === 'delivered') {
                if (! $recipient->sent_at) {
                    $patch['sent_at'] = $now;
                }
                if (! $recipient->delivered_at) {
                    $patch['delivered_at'] = $now;
                }
            }
            if ($mapped === 'read') {
                if (! $recipient->sent_at) {
                    $patch['sent_at'] = $now;
                }
                if (! $recipient->delivered_at) {
                    $patch['delivered_at'] = $now;
                }
                if (! $recipient->read_at) {
                    $patch['read_at'] = $now;
                }
            }
            if ($mapped === 'failed') {
                $patch['failed_reason'] = substr(
                    $status['errors'][0]['title']
                        ?? $status['errors'][0]['message']
                        ?? 'unknown',
                    0,
                    512,
                );
            }

            $recipient->update($patch);
        }
    }
}
