<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Converts a WAHA `message` / `message.any` payload into the ($value, $msg) array
 * pair that WhatsappDriver::processInboundMessage() already understands (the Meta
 * Cloud API `entry.changes.value` shape). This lets the entire ingest path —
 * contact upsert, conversation creation, body extraction, idempotency — be reused.
 *
 * Two directions share one parser:
 *  - normalize():        incoming (fromMe=false). The chat is `from`.
 *  - normalizeFromMe():  sent from the phone / another linked device (fromMe=true).
 *                        The chat is `to`; `from` is our own number.
 * In both cases `$msg['from']` is the CHAT's phone digits, so the contact and
 * conversation resolve to the same person either way.
 *
 * Handles both `@c.us` (phone-number) and `@lid` (Linked ID — number hidden)
 * chat ids; a LID is resolved to a real number via the engine's contacts API.
 */
class InboundNormalizer
{
    public function __construct(private readonly EngineManager $engines) {}

    /**
     * @param  array<string,mixed>  $wahaPayload  the full webhook body
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}|null  [$value, $msg] or null if not an inbound message
     */
    public function normalize(array $wahaPayload, WhatsappWebSession $session): ?array
    {
        $p = $wahaPayload['payload'] ?? [];
        if (! is_array($p) || ($p['fromMe'] ?? false) === true) {
            return null;
        }

        return $this->parse($p, $session, fromMe: false);
    }

    /**
     * A message the account sent itself (phone or another linked device).
     * Returns null for anything that is not fromMe or not a 1:1 chat.
     *
     * @param  array<string,mixed>  $wahaPayload
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}|null
     */
    public function normalizeFromMe(array $wahaPayload, WhatsappWebSession $session): ?array
    {
        $p = $wahaPayload['payload'] ?? [];
        if (! is_array($p) || ($p['fromMe'] ?? false) !== true) {
            return null;
        }

        return $this->parse($p, $session, fromMe: true);
    }

    /**
     * Map a WAHA/WhatsApp ack level to our delivery status. Null = no change
     * (pending / unknown).
     */
    public static function statusFromAck(mixed $ack): ?string
    {
        $level = (int) $ack;

        return match (true) {
            $level < 0 => 'failed',
            $level === 1 => 'sent',
            $level === 2 => 'delivered',
            $level >= 3 => 'read',
            default => null,
        };
    }

    /**
     * WAHA ids are either a serialized string (`true_123@c.us_3EB0...`) or an
     * object (`{_serialized, id, fromMe, remote}`). Always reduce to the string.
     */
    public static function idOf(mixed $id): string
    {
        if (is_array($id)) {
            $serialized = $id['_serialized'] ?? $id['id'] ?? '';

            return is_scalar($serialized) ? (string) $serialized : '';
        }

        return is_scalar($id) ? (string) $id : '';
    }

    /**
     * @param  array<string,mixed>  $p  the WAHA message payload
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}|null
     */
    private function parse(array $p, WhatsappWebSession $session, bool $fromMe): ?array
    {
        // The chat we are talking to: the sender for inbound, the recipient for fromMe.
        $chatJid = self::idOf($fromMe ? ($p['to'] ?? '') : ($p['from'] ?? ''));
        if ($chatJid === '') {
            return null;
        }

        // Only 1:1 chats. Skip groups, broadcasts, newsletters, status.
        if (str_contains($chatJid, '@g.us')
            || str_contains($chatJid, '@broadcast')
            || str_contains($chatJid, '@newsletter')
            || str_contains($chatJid, 'status@')) {
            return null;
        }

        // Resolve the chat to a real phone number.
        [$phoneDigits, $contactName] = $this->resolveSender($chatJid, $session);
        if ($phoneDigits === '') {
            Log::warning('whatsapp_web.inbound.unresolved_sender', ['from' => $chatJid, 'session' => $session->session_name]);

            return null;
        }

        $type = $this->mapType((string) ($p['type'] ?? 'chat'), $p);
        $id = self::idOf($p['id'] ?? '');
        $msg = [
            'id' => $id !== '' ? $id : ('wa-web-'.md5(json_encode($p))),
            'from' => $phoneDigits,
            'timestamp' => (int) ($p['timestamp'] ?? time()),
            'type' => $type,
        ];

        $body = (string) ($p['body'] ?? '');
        $mediaUrl = is_array($p['media'] ?? null) ? ($p['media']['url'] ?? null) : null;
        $mediaName = is_array($p['media'] ?? null) ? ($p['media']['filename'] ?? null) : null;

        switch ($type) {
            case 'text':
                $msg['text'] = ['body' => $body];
                break;

            case 'image':
            case 'video':
            case 'audio':
            case 'document':
                $msg[$type] = array_filter([
                    'link' => $mediaUrl,
                    'caption' => $body !== '' ? $body : null,
                    'filename' => $mediaName,
                ], fn ($v) => $v !== null && $v !== '');
                break;

            case 'location':
                $loc = is_array($p['location'] ?? null) ? $p['location'] : [];
                $msg['location'] = array_filter([
                    'latitude' => $loc['latitude'] ?? null,
                    'longitude' => $loc['longitude'] ?? null,
                    'name' => $loc['name'] ?? ($loc['description'] ?? null),
                    'address' => $loc['address'] ?? null,
                ], fn ($v) => $v !== null);
                break;

            default:
                $msg['type'] = 'unsupported';
                if ($body !== '') {
                    $msg['text'] = ['body' => $body];
                }
        }

        // The push name belongs to the CHAT's contact. On fromMe the notifyName is
        // our own name, so only the resolved contact name is used there.
        $name = $contactName;
        if (! $fromMe) {
            $name ??= $p['notifyName'] ?? null;
            $name ??= is_array($p['_data'] ?? null) ? ($p['_data']['notifyName'] ?? null) : null;
        }
        // Drop unusable notifyName (WhatsApp sometimes sends garbled single chars).
        if (is_string($name) && mb_strlen(trim($name)) < 2) {
            $name = null;
        }

        $value = [
            'metadata' => ['phone_number_id' => $session->session_name],
            'messages' => [$msg],
            'contacts' => [[
                'wa_id' => $phoneDigits,
                'profile' => ['name' => $name],
            ]],
        ];

        return [$value, $msg];
    }

    /**
     * @return array{0: string, 1: ?string}  [phone digits without +, display name]
     */
    private function resolveSender(string $fromJid, WhatsappWebSession $session): array
    {
        $left = explode('@', $fromJid)[0];

        // Plain phone-number JID — use as-is.
        if (str_ends_with($fromJid, '@c.us') || str_ends_with($fromJid, '@s.whatsapp.net')) {
            return [preg_replace('/\D+/', '', $left), null];
        }

        // LID (or anything else) — ask the engine to resolve it. Cache the result
        // so we don't hit the engine on every message from the same sender.
        if (str_ends_with($fromJid, '@lid')) {
            $cacheKey = 'wwlid:'.$session->session_name.':'.$left;
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }

            try {
                $resolved = $this->engines->adapter()->resolveContact($session->session_name, $fromJid);
            } catch (\Throwable $e) {
                Log::warning('whatsapp_web.inbound.resolve_failed', ['from' => $fromJid, 'error' => $e->getMessage()]);
                $resolved = null;
            }

            $digits = $resolved && $resolved['phone_e164']
                ? preg_replace('/\D+/', '', $resolved['phone_e164'])
                : '';
            $result = [$digits, $resolved['name'] ?? null];

            if ($digits !== '') {
                Cache::put($cacheKey, $result, now()->addDay());
            }

            return $result;
        }

        return ['', null];
    }

    /** @param array<string,mixed> $payload */
    private function mapType(string $wahaType, array $payload): string
    {
        return match (strtolower($wahaType)) {
            'chat', 'text' => 'text',
            'image' => 'image',
            'video' => 'video',
            'ptt', 'audio', 'voice' => 'audio',
            'document' => 'document',
            'location' => 'location',
            default => ($payload['hasMedia'] ?? false) ? 'document' : 'unsupported',
        };
    }
}
