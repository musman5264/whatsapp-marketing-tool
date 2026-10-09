<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Events\MessageSent;
use App\Events\MessageStatusUpdated;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Support\Facades\Log;

/**
 * Backfills the 1:1 inbox from WAHA's own history for one linked number.
 *
 * Covers what the webhook cannot: messages that arrived while the app or WAHA
 * was down, phone-sent replies from before the webhook was subscribed, delivery
 * ticks for those, and the unread state the owner cleared on their phone.
 *
 * Rules:
 *  - only messages not yet stored are ingested (matched on the engine message id);
 *  - backfilled inbound messages never fire MessageReceived / automations / AI
 *    when older than 5 minutes (enforced in WhatsappDriver);
 *  - outbound history only moves ticks forward (the driver never downgrades);
 *  - per-chat failures are counted and skipped, never abort the run.
 */
class WahaHistorySync
{
    public function __construct(
        private readonly EngineManager $engines,
        private readonly InboundNormalizer $normalizer,
        private readonly WhatsappDriver $driver,
    ) {}

    /**
     * @return array{chats: int, ingested: int, statuses: int, unread_reset: int, failed: int}
     */
    public function run(WhatsappWebSession $session, int $chatLimit = 50, int $messageLimit = 30): array
    {
        $stats = ['chats' => 0, 'ingested' => 0, 'statuses' => 0, 'unread_reset' => 0, 'failed' => 0];
        $adapter = $this->engines->adapter();
        $name = $session->session_name;
        // Engine unread counts describe the state at this instant; never clear an
        // inbound that the webhook stored after it.
        $snapshotAt = now();

        try {
            $chats = $adapter->listChats($name, max(1, $chatLimit));
        } catch (\Throwable $e) {
            Log::warning('whatsapp_web.sync.list_failed', ['session' => $name, 'error' => $e->getMessage()]);
            $stats['failed']++;

            return $stats;
        }

        $pauseMicros = app()->runningUnitTests() ? 0 : 300_000; // be gentle with the engine
        $first = true;

        foreach ($chats as $chat) {
            $chatId = InboundNormalizer::idOf($chat['id'] ?? '');
            if (! $this->isDirectChat($chatId)) {
                continue; // groups, broadcasts, newsletters
            }

            if (! $first && $pauseMicros > 0) {
                usleep($pauseMicros);
            }
            $first = false;
            $stats['chats']++;

            try {
                $messages = $adapter->chatMessages($name, $chatId, max(1, $messageLimit));
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::warning('whatsapp_web.sync.chat_failed', ['session' => $name, 'chat' => $chatId, 'error' => $e->getMessage()]);

                continue;
            }

            $result = $this->ingestChat($session, $messages, $stats);

            // The chat's phone digits: a plain @c.us id, else whatever the messages resolved to.
            $digits = str_ends_with($chatId, '@c.us') ? preg_replace('/\D+/', '', explode('@', $chatId)[0]) : $result['digits'];
            if ($digits !== null && $digits !== '') {
                $this->syncUnread($session, $chat, $digits, $snapshotAt, $stats);
            }

            // One inbox refresh per chat that changed, not one per message.
            if ($result['newest'] !== null) {
                try {
                    $result['newest']->load('conversation');
                    MessageSent::dispatch($result['newest']);
                } catch (\Throwable $e) {
                    Log::debug('whatsapp_web.sync.broadcast_failed', ['error' => $e->getMessage()]);
                }
            }
        }

        Log::info('whatsapp_web.sync.done', ['session' => $name] + $stats);

        return $stats;
    }

    /**
     * Ingest one chat's history (engine returns newest first; ingest oldest first
     * so the thread order is right).
     *
     * @param  list<array<string,mixed>>  $messages
     * @param  array{chats: int, ingested: int, statuses: int, unread_reset: int, failed: int}  $stats
     * @return array{digits: ?string, newest: ?Message}
     */
    private function ingestChat(WhatsappWebSession $session, array $messages, array &$stats): array
    {
        $digits = null;
        $newest = null;

        foreach (array_reverse($messages) as $m) {
            $engineId = InboundNormalizer::idOf($m['id'] ?? '');
            if ($engineId === '') {
                continue;
            }

            $fromMe = ($m['fromMe'] ?? false) === true;
            $stored = Message::where('provider_message_id', $engineId)->first();

            if ($stored) {
                // Already have it: just catch up the delivery/read tick on our own sends.
                $status = $fromMe ? InboundNormalizer::statusFromAck($m['ack'] ?? null) : null;
                if ($status !== null && $status !== $stored->status) {
                    $this->driver->applyStatusUpdate(['id' => $engineId, 'status' => $status]);
                    $stats['statuses']++;
                }

                continue;
            }

            // Our own API sends are stored by the send path; never re-create them.
            if ($fromMe && strtolower((string) ($m['source'] ?? '')) === 'api') {
                continue;
            }

            try {
                if ($fromMe) {
                    $normalized = $this->normalizer->normalizeFromMe(['payload' => $m], $session);
                    if ($normalized === null) {
                        continue;
                    }
                    [$value, $msg] = $normalized;
                    $message = $this->driver->ingestNormalizedOutbound(
                        $value,
                        $msg,
                        InboundNormalizer::statusFromAck($m['ack'] ?? null) ?? 'sent',
                        historical: true,
                    );
                } else {
                    $normalized = $this->normalizer->normalize(['payload' => $m], $session);
                    if ($normalized === null) {
                        continue;
                    }
                    [$value, $msg] = $normalized;
                    $message = $this->driver->ingestNormalizedInbound($value, $msg, historical: true);
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::warning('whatsapp_web.sync.message_failed', ['session' => $session->session_name, 'message' => $engineId, 'error' => $e->getMessage()]);

                continue;
            }

            $stats['ingested']++;
            $digits = (string) ($msg['from'] ?? $digits);
            $newest = $message;
        }

        return ['digits' => $digits, 'newest' => $newest];
    }

    /**
     * WAHA's chat overview may expose an unread count. When the engine reports 0
     * (the owner has read the chat on their phone) clear ours too. WAHA has no
     * event for "read on another device", so this is the only signal.
     *
     * @param  array<string,mixed>  $chat
     * @param  array{chats: int, ingested: int, statuses: int, unread_reset: int, failed: int}  $stats
     */
    private function syncUnread(WhatsappWebSession $session, array $chat, string $digits, \DateTimeInterface $snapshotAt, array &$stats): void
    {
        $unread = $this->unreadCountOf($chat);
        if ($unread !== 0) {
            return;
        }

        $conversation = $this->conversationFor($session, $digits);
        if (! $conversation || (int) $conversation->unread_count === 0) {
            return;
        }

        // An inbound stored after the engine snapshot is newer than its "0".
        if ($conversation->last_inbound_at && $conversation->last_inbound_at->gt($snapshotAt)) {
            return;
        }

        $conversation->update(['unread_count' => 0]);
        $stats['unread_reset']++;

        $latest = $conversation->messages()->latest('id')->first();
        if ($latest instanceof Message) {
            $latest->load('conversation');
            MessageStatusUpdated::dispatch($latest);
        }
    }

    /**
     * Defensive: the engine has shipped the unread count in more than one place.
     *
     * @param  array<string,mixed>  $chat
     */
    private function unreadCountOf(array $chat): ?int
    {
        $candidates = [
            $chat['_chat']['unreadCount'] ?? null,
            $chat['unreadCount'] ?? null,
            $chat['_chat']['unread'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (is_int($c) || (is_string($c) && ctype_digit($c))) {
                return (int) $c;
            }
        }

        return null;
    }

    private function conversationFor(WhatsappWebSession $session, string $digits): ?Conversation
    {
        $accountId = ChannelAccount::where('workspace_id', $session->workspace_id)
            ->where('channel', 'whatsapp')
            ->where('phone_number_id', $session->session_name)
            ->value('id');
        $contactId = Contact::where('workspace_id', $session->workspace_id)
            ->where('phone_e164', '+'.$digits)
            ->value('id');

        if (! $accountId || ! $contactId) {
            return null;
        }

        return Conversation::where('workspace_id', $session->workspace_id)
            ->where('channel_account_id', $accountId)
            ->where('contact_id', $contactId)
            ->first();
    }

    private function isDirectChat(string $chatId): bool
    {
        return str_ends_with($chatId, '@c.us') || str_ends_with($chatId, '@lid');
    }
}
