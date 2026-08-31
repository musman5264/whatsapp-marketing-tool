<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Converts a WAHA `message` webhook payload into the ($value, $msg) array pair
 * that WhatsappDriver::processInboundMessage() already understands (the Meta
 * Cloud API `entry.changes.value` shape). This lets the entire inbound path —
 * contact upsert, conversation creation, body extraction, idempotency and the
 * MessageReceived event (auto-replies / AI) — be reused unchanged.
 *
 * Handles both `@c.us` (phone-number) and `@lid` (Linked ID — number hidden)
 * senders; a LID is resolved to a real number via the engine's contacts API.
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

        $fromJid = (string) ($p['from'] ?? '');
        if ($fromJid === '') {
            return null;
        }

        // Only 1:1 chats. Skip groups, broadcasts, newsletters, status.
        if (str_contains($fromJid, '@g.us')
            || str_contains($fromJid, '@broadcast')
            || str_contains($fromJid, '@newsletter')
            || str_contains($fromJid, 'status@')) {
            return null;
        }

        // Resolve the sender to a real phone number.
        [$phoneDigits, $contactName] = $this->resolveSender($fromJid, $session);
        if ($phoneDigits === '') {
            Log::warning('whatsapp_web.inbound.unresolved_sender', ['from' => $fromJid, 'session' => $session->session_name]);

            return null;
        }

        $type = $this->mapType((string) ($p['type'] ?? 'chat'), $p);
        $msg = [
            'id' => (string) ($p['id'] ?? ('wa-web-'.md5(json_encode($p)))),
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

        $name = $contactName
            ?? ($p['notifyName'] ?? null)
            ?? (is_array($p['_data'] ?? null) ? ($p['_data']['notifyName'] ?? null) : null);
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
