<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Events\CallReceived;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Modules\WhatsappWeb\Jobs\SyncWhatsappWebHistoryJob;
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
            str_starts_with($event, 'label.')
                => \App\Modules\WhatsappWeb\Services\Waha\WahaLabelSync::fromSystem()?->handleWebhook($payload, $session->session_name),
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

    /**
     * `message.any` carries both directions. fromMe=false is a customer message;
     * fromMe=true is something the account sent from the phone or another linked
     * device (our own API sends are skipped — they are already stored).
     *
     * @param  array<string,mixed>  $payload
     */
    private function handleInbound(array $payload, WhatsappWebSession $session): void
    {
        if (($payload['payload']['fromMe'] ?? false) === true) {
            $this->handleOwnMessage($payload, $session);

            return;
        }

        $normalized = $this->normalizer->normalize($payload, $session);
        if ($normalized === null) {
            return; // group message, or unparseable
        }

        [$value, $msg] = $normalized;
        $this->driver->ingestNormalizedInbound($value, $msg);
    }

    /**
     * A message sent from the phone / another linked device, seen by WAHA as
     * fromMe=true. Stored as an outbound message in the 1:1 thread so the agent
     * sees the reply, and the chat's unread count is cleared.
     *
     * @param  array<string,mixed>  $payload
     */
    private function handleOwnMessage(array $payload, WhatsappWebSession $session): void
    {
        $p = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        // WAHA: source = 'api' when the message was sent through the WAHA API (i.e.
        // by this app — the send path already stored it), 'app' when sent from a
        // WhatsApp client. Absent on some engines.
        $source = strtolower((string) ($p['source'] ?? $payload['source'] ?? ''));
        if ($source === 'api') {
            return;
        }

        $normalized = $this->normalizer->normalizeFromMe($payload, $session);
        if ($normalized === null) {
            return; // group / broadcast / unparseable
        }

        [$value, $msg] = $normalized;
        $providerId = (string) $msg['id'];

        if ($this->findOwnMessage($providerId) !== null) {
            return; // already stored (e.g. redelivered webhook)
        }

        // Unknown source: only treat as an echo of our own send when we stored the
        // same text to this contact in the last 20 seconds.
        if ($source === '' && $this->recentOwnSendMatches($msg, (string) ($p['body'] ?? ''), $session)) {
            return;
        }

        $status = InboundNormalizer::statusFromAck($p['ack'] ?? null) ?? 'sent';
        $this->driver->ingestNormalizedOutbound($value, $msg, $status);
    }

    /**
     * Unknown-source echo guard. Looks for an outbound message with the same text
     * to the same contact created within the last 20 seconds.
     *
     * @param  array<string,mixed>  $msg
     */
    private function recentOwnSendMatches(array $msg, string $body, WhatsappWebSession $session): bool
    {
        if (trim($body) === '') {
            return false;
        }

        $digits = (string) ($msg['from'] ?? '');

        return Message::query()
            ->where('direction', 'out')
            ->where('body', $body)
            ->where('created_at', '>=', now()->subSeconds(20))
            ->whereHas('conversation', fn ($q) => $q
                ->where('workspace_id', $session->workspace_id)
                ->whereHas('contact', fn ($c) => $c->where('phone_e164', '+'.$digits)))
            ->exists();
    }

    /**
     * Find our stored copy of an engine message id. Tolerates the id being stored
     * in a different shape than the webhook sends it (full serialized
     * `true_<chat>_<id>` vs bare `<id>`).
     */
    private function findOwnMessage(string $engineId): ?Message
    {
        if ($engineId === '') {
            return null;
        }

        $bare = $this->bareMessageId($engineId);
        $found = Message::whereIn('provider_message_id', array_values(array_unique(array_filter([$engineId, $bare]))))->first();
        if ($found || strlen($bare) < 8) {
            return $found;
        }

        // Last resort: stored as the serialized form, looked up by the bare id.
        return Message::where('direction', 'out')
            ->where('provider_message_id', 'like', '%_'.$bare)
            ->first();
    }

    /** `true_<chat>@c.us_3EB0ABC` -> `3EB0ABC`; a bare id is returned unchanged. */
    private function bareMessageId(string $engineId): string
    {
        $pos = strrpos($engineId, '_');

        return $pos === false ? $engineId : substr($engineId, $pos + 1);
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
            $wasActive = $session->status === 'active';
            $this->provisioner->markActive($session, $session->phone_e164, $session->push_name);

            // Newly connected (or reconnected): backfill what arrived while offline.
            if (! $wasActive) {
                SyncWhatsappWebHistoryJob::dispatch($session->id)
                    ->onQueue('whatsapp')
                    ->delay(now()->addSeconds(20));
            }
        } else {
            $this->provisioner->syncStatus($session, $status);
        }
    }

    /**
     * Delivery / read ticks for a message we sent (or a phone-sent one we stored).
     * WAHA ack: -1 error, 0 pending, 1 server, 2 device, 3 read, 4 played.
     * Ticks can arrive before the message itself was ingested — then there is
     * nothing to update and the ingest picks up the ack from its own payload.
     *
     * @param  array<string,mixed>  $payload
     */
    private function handleAck(array $payload): void
    {
        $p = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $id = InboundNormalizer::idOf($p['id'] ?? '');
        if ($id === '') {
            return;
        }

        $ack = $p['ack'] ?? $this->ackFromName($p['ackName'] ?? null);
        $status = InboundNormalizer::statusFromAck($ack);
        if ($status === null) {
            return;
        }

        $stored = $this->findOwnMessage($id);
        if ($stored === null) {
            return;
        }

        // Use the id as stored so the driver's exact-match lookup finds the row.
        $this->driver->applyStatusUpdate(['id' => (string) $stored->provider_message_id, 'status' => $status]);
    }

    private function ackFromName(mixed $name): ?int
    {
        return match (strtoupper((string) $name)) {
            'ERROR' => -1,
            'PENDING' => 0,
            'SERVER' => 1,
            'DEVICE' => 2,
            'READ' => 3,
            'PLAYED' => 4,
            default => null,
        };
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
