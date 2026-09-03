<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Events\CallReceived;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Services\WebhookIdempotencyService;
use Illuminate\Support\Facades\Log;

/**
 * Processes one WAHA webhook event (message / session.status / message.ack).
 *
 * Called INLINE by the webhook controller so inbound messages appear in the
 * inbox within ~1s (no queue/cron wait — important on shared hosting where a
 * persistent queue worker is not possible). ProcessWahaEventJob wraps this for
 * retry/backlog handling only.
 */
class WahaEventProcessor
{
    public function __construct(
        private readonly InboundNormalizer $normalizer,
        private readonly WhatsappDriver $driver,
        private readonly SessionProvisioner $provisioner,
        private readonly AutomationEngine $engine,
        private readonly EngineManager $engines,
    ) {}

    /**
     * @param  array<string,mixed>  $payload  the full WAHA webhook body
     */
    public function process(array $payload, WhatsappWebSession $session): void
    {
        $event = (string) ($payload['event'] ?? '');

        match (true) {
            $event === 'message' || $event === 'message.any' => $this->handleInbound($payload, $session),
            $event === 'session.status' => $this->handleSessionStatus($payload, $session),
            $event === 'message.ack' => $this->handleAck($payload),
            $event === 'poll.vote' => $this->handlePollVote($payload),
            $event === 'message.reaction' => $this->handleReaction($payload),
            $event === 'call.received' || $event === 'call.accepted' || $event === 'call.rejected'
                => $this->handleCall($payload, $session, $event),
            default => Log::info('whatsapp_web.event.ignored', ['event' => $event]),
        };
    }

    /** Resolve the session by name (stable) with an id fallback. */
    public function resolveSession(int $sessionId, ?string $sessionName): ?WhatsappWebSession
    {
        if ($sessionName) {
            $byName = WhatsappWebSession::where('session_name', $sessionName)->first();
            if ($byName) {
                return $byName;
            }
        }

        return WhatsappWebSession::find($sessionId);
    }

    /** @param array<string,mixed> $payload */
    private function handleInbound(array $payload, WhatsappWebSession $session): void
    {
        $normalized = $this->normalizer->normalize($payload, $session);
        if ($normalized === null) {
            return; // outbound echo, group message, or unparseable
        }

        [$value, $msg] = $normalized;
        $this->driver->ingestNormalizedInbound($value, $msg);
    }

    /** @param array<string,mixed> $payload */
    private function handleSessionStatus(array $payload, WhatsappWebSession $session): void
    {
        $raw = strtoupper((string) ($payload['payload']['status'] ?? ''));
        $status = match ($raw) {
            'WORKING' => 'active',
            'SCAN_QR_CODE' => 'scan_qr',
            'STARTING' => 'connecting',
            'FAILED' => 'failed',
            'STOPPED' => 'disconnected',
            default => null,
        };

        if ($status === null) {
            return;
        }

        if ($status === 'active') {
            $this->provisioner->markActive($session, $session->phone_e164, $session->push_name);
        } else {
            $this->provisioner->syncStatus($session, $status);
        }
    }

    /** @param array<string,mixed> $payload */
    private function handleAck(array $payload): void
    {
        $p = $payload['payload'] ?? [];
        $id = (string) ($p['id']['_serialized'] ?? $p['id'] ?? '');
        if ($id === '') {
            return;
        }

        // WAHA ack: 1=sent(server) 2=delivered(device) 3=read 4=played; -1=error
        $ack = (int) ($p['ack'] ?? 0);
        $status = match (true) {
            $ack < 0 => 'failed',
            $ack === 1 => 'sent',
            $ack === 2 => 'delivered',
            $ack >= 3 => 'read',
            default => null,
        };

        if ($status === null || ! Message::where('provider_message_id', $id)->exists()) {
            return;
        }

        $this->driver->applyStatusUpdate(['id' => $id, 'status' => $status]);
    }

    /**
     * A contact voted on a poll an automation sent. WAHA delivers the vote as a
     * `poll.vote` event; write the choice into the run context and resume the
     * run if it was parked after the poll node.
     *
     * @param  array<string,mixed>  $payload
     */
    private function handlePollVote(array $payload): void
    {
        $p = $payload['payload'] ?? [];
        $pollMessageId = (string) ($p['pollMessageId'] ?? ($p['poll']['id'] ?? ''));
        $voter = (string) ($p['from'] ?? ($p['voter'] ?? ''));
        $selected = array_values((array) ($p['vote']['selectedOptions'] ?? ($p['selectedOptions'] ?? [])));

        if ($pollMessageId === '') {
            return;
        }

        $key = 'pollvote:'.$pollMessageId.':'.$voter.':'.implode(',', $selected);
        if (! app(WebhookIdempotencyService::class)->isNewEvent('whatsapp_web', $key)) {
            return;
        }

        $this->engine->applyPollVote($pollMessageId, $selected);
    }

    /**
     * A contact reacted to a message we sent. WAHA delivers the reaction as a
     * `message.reaction` event; store the emoji on our copy of the reacted
     * message and fire the `reaction.received` automation trigger.
     *
     * @param  array<string,mixed>  $payload
     */
    private function handleReaction(array $payload): void
    {
        $p = $payload['payload'] ?? [];
        $emoji = (string) ($p['reaction']['text'] ?? $p['reaction']['emoji'] ?? ($p['text'] ?? ''));
        $targetProviderId = (string) ($p['reaction']['messageId'] ?? $p['reaction']['id'] ?? ($p['messageId'] ?? ''));
        $rxId = (string) ($p['id'] ?? ($targetProviderId.':'.$emoji));

        if ($targetProviderId === '') {
            return;
        }

        if (! app(WebhookIdempotencyService::class)->isNewEvent('whatsapp_web', 'reaction:'.$rxId)) {
            return;
        }

        $message = Message::where('provider_message_id', $targetProviderId)->first();
        if (! $message) {
            return;
        }

        $message->update(['reaction_emoji' => $emoji !== '' ? $emoji : null]);
        \App\Events\ReactionReceived::dispatch($message, $emoji);
    }

    /**
     * An incoming voice/video call on the personal number. Every call event is
     * logged into the caller's conversation as a "📞 …" line so agents see call
     * history inline. On `call.received`, when the number's `auto_reject_calls`
     * toggle is on we reject it (and optionally send a canned reply), and either
     * way we fire the `call.received` automation trigger.
     *
     * @param  array<string,mixed>  $payload
     */
    private function handleCall(array $payload, WhatsappWebSession $session, string $event): void
    {
        $p = $payload['payload'] ?? [];
        $callId = (string) ($p['id'] ?? '');
        $fromJid = (string) ($p['from'] ?? ($p['peerJid'] ?? ''));
        $phone = preg_replace('/\D+/', '', explode('@', $fromJid)[0] ?? '');
        if ($callId === '' || $phone === '') {
            return;
        }

        if (! app(WebhookIdempotencyService::class)->isNewEvent('whatsapp_web', 'call:'.$callId.':'.$event)) {
            return;
        }

        $callType = ($p['isVideo'] ?? false) ? 'video' : 'audio';

        $contact = Contact::firstOrCreate(
            ['workspace_id' => $session->workspace_id, 'phone_e164' => '+'.$phone],
        );
        $account = ChannelAccount::where('workspace_id', $session->workspace_id)
            ->where('channel', 'whatsapp')
            ->where('phone_number_id', $session->session_name)
            ->first();
        $conversation = Conversation::firstOrCreate(
            ['workspace_id' => $session->workspace_id, 'contact_id' => $contact->id, 'channel_account_id' => $account?->id],
            ['status' => 'open'],
        );

        $label = match ($event) {
            'call.received' => '📞 Missed call',
            'call.rejected' => '📞 Call rejected',
            'call.accepted' => '📞 Call answered',
            default => '📞 Call',
        };
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $label.' ('.$callType.')',
            'status' => 'delivered',
            'sent_by' => 'bot',
            'sent_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);

        if ($event !== 'call.received') {
            return;
        }

        if ($session->auto_reject_calls) {
            try {
                $this->engines->adapter()->rejectCall($session->session_name, $callId);
            } catch (\Throwable $e) {
                Log::warning('whatsapp_web.call.reject_failed', ['call' => $callId, 'error' => $e->getMessage()]);
            }

            if (trim((string) $session->call_reject_message) !== '') {
                $out = Message::create([
                    'conversation_id' => $conversation->id,
                    'direction' => 'out',
                    'channel' => 'whatsapp',
                    'type' => 'text',
                    'body' => $session->call_reject_message,
                    'status' => 'queued',
                    'sent_by' => 'bot',
                    'sent_at' => now(),
                ]);
                try {
                    $this->driver->send($out);
                    $out->update(['status' => 'sent']);
                } catch (\Throwable $e) {
                    $out->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);
                }
            }
        }

        CallReceived::dispatch($session->workspace_id, $contact->id, $callId, $callType, '+'.$phone);
    }
}
