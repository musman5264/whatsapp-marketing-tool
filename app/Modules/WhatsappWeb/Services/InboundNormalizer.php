<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Modules\WhatsappWeb\Models\WhatsappWebSession;

/**
 * Converts a WAHA `message` webhook payload into the ($value, $msg) array pair
 * that WhatsappDriver::processInboundMessage() already understands (the Meta
 * Cloud API `entry.changes.value` shape). This lets the entire inbound path —
 * contact upsert, conversation creation, body extraction, idempotency and the
 * MessageReceived event (auto-replies / AI) — be reused unchanged.
 *
 * WAHA `message` payload (WEBJS/NOWEB engines):
 *   {
 *     "event": "message",
 *     "session": "ws-1",
 *     "payload": {
 *       "id": "false_123@c.us_ABC",
 *       "timestamp": 1699999999,
 *       "from": "123@c.us",
 *       "fromMe": false,
 *       "body": "hello",
 *       "hasMedia": false,
 *       "type": "chat" | "image" | "video" | "ptt" | "document" | "location" | ...,
 *       "media": { "url": "https://waha/api/files/...", "mimetype": "image/jpeg", "filename": null },
 *       "location": { "latitude": .., "longitude": .., "description": ".." },
 *       "_data": { ... }
 *     }
 *   }
 */
class InboundNormalizer
{
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
        $fromDigits = preg_replace('/\D+/', '', explode('@', $fromJid)[0]);
        if ($fromDigits === '' || $fromDigits === null) {
            return null;
        }

        // Ignore group / broadcast / status messages — only 1:1 chats (@c.us).
        if ($fromJid !== '' && ! str_ends_with($fromJid, '@c.us')) {
            return null;
        }

        $type = $this->mapType((string) ($p['type'] ?? 'chat'), $p);
        $msg = [
            'id' => (string) ($p['id'] ?? ('wa-web-'.md5(json_encode($p)))),
            'from' => $fromDigits,
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
                // 'unsupported' — keep the raw payload for diagnostics; body switch
                // in the driver will fall through to a generic label.
                $msg['type'] = 'unsupported';
                if ($body !== '') {
                    $msg['text'] = ['body' => $body];
                }
        }

        $value = [
            'metadata' => ['phone_number_id' => $session->session_name],
            'messages' => [$msg],
            'contacts' => [[
                'wa_id' => $fromDigits,
                'profile' => ['name' => $p['notifyName'] ?? ($p['_data']['notifyName'] ?? null)],
            ]],
        ];

        return [$value, $msg];
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
