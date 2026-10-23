<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Modules\WhatsappWeb\Models\WhatsappWebLidMapping;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
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
        [$phoneDigits, $contactName] = $this->resolveSender($chatJid, $session, $p, $fromMe);
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
    private function resolveSender(string $fromJid, WhatsappWebSession $session, array $payload = [], bool $fromMe = false): array
    {
        $left = explode('@', $fromJid)[0];

        // Plain phone-number JID — use as-is.
        if (str_ends_with($fromJid, '@c.us') || str_ends_with($fromJid, '@s.whatsapp.net')) {
            return [preg_replace('/\D+/', '', $left), null];
        }

        // LID (or anything else) — ask the engine to resolve it. Cache the result
        // so we don't hit the engine on every message from the same sender.
        if (str_ends_with($fromJid, '@lid')) {
            // A number learned before is kept in the database. A cache clear (every deploy) or a failed
            // WAHA lookup must not send the message to a different contact.
            $known = WhatsappWebLidMapping::where('session_name', $session->session_name)->where('lid', $left)->first();
            if ($known !== null) {
                return [preg_replace('/\D+/', '', (string) $known->getAttribute('phone_e164')), $known->getAttribute('name')];
            }

            // The message itself often carries the real number next to the LID, so no WAHA call is needed.
            // NOWEB: _data.key.remoteJidAlt (WAHA issue #1608). GOWS: _data.Info.SenderAlt / RecipientAlt
            // (whatsmeow field names; unverified on the live engine, harmless when absent).
            $alt = $this->altNumberDigits($payload, $fromMe);
            if ($alt !== '') {
                $name = $fromMe ? null : $this->pushNameOf($payload);
                WhatsappWebLidMapping::query()->insertOrIgnore([
                    'session_name' => $session->session_name,
                    'lid' => $left,
                    'phone_e164' => '+'.$alt,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [$alt, $name];
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
            $name = $resolved['name'] ?? null;

            if ($digits !== '') {
                // insertOrIgnore: two webhooks for the same LID at the same time must not fail on the unique key.
                WhatsappWebLidMapping::query()->insertOrIgnore([
                    'session_name' => $session->session_name,
                    'lid' => $left,
                    'phone_e164' => '+'.$digits,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [$digits, $name];
            }

            // WAHA could not map this LID to a phone number, or could not be asked. The message
            // is kept (CLAUDE.md: an inbound message is never lost): the LID's own digits stand in
            // for the number. Not cached, so the next message from this sender is resolved again.
            // data_keys: shows where (if anywhere) this engine puts the sender's number, so the field names can be checked against a real payload.
            Log::info('whatsapp_web.inbound.lid_unmapped', [
                'from' => $fromJid,
                'session' => $session->session_name,
                'data_keys' => $this->keyPaths($payload['_data'] ?? null),
            ]);

            return [$left, $name ?? ($fromMe ? null : $this->pushNameOf($payload))];
        }

        return ['', null];
    }

    /**
     * The real phone number (digits) WAHA put beside a LID chat id in the message payload, or ''.
     * Keys, at any depth under `_data` (the nesting WAHA uses is not documented):
     * NOWEB `remoteJidAlt`; GOWS (whatsmeow types.MessageSource) `SenderAlt` for a message we received and
     * `RecipientAlt` for one we sent. RecipientAlt of a received DM is OUR OWN number, so it is never used there.
     *
     * @param  array<string,mixed>  $p
     */
    private function altNumberDigits(array $p, bool $fromMe): string
    {
        $keys = ['remoteJidAlt', $fromMe ? 'RecipientAlt' : 'SenderAlt'];
        $found = [];
        $this->collectByKey($p['_data'] ?? null, $keys, $found);

        foreach ($found as $jid) {
            if (is_string($jid) && preg_match('/^(\d{6,15})(?::\d+)?@(s\.whatsapp\.net|c\.us)$/', $jid, $m)) {
                return $m[1];
            }
        }

        return '';
    }

    /**
     * The customer's own WhatsApp profile name from the payload (NOWEB `pushName`, GOWS `PushName`), any depth.
     * Only for messages we received: on one we sent it is our own name.
     *
     * @param  array<string,mixed>  $p
     */
    private function pushNameOf(array $p): ?string
    {
        $found = [];
        $this->collectByKey($p['_data'] ?? null, ['pushName', 'PushName'], $found);
        $name = $found[0] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** @param list<string> $keys @param list<mixed> $found */
    private function collectByKey(mixed $node, array $keys, array &$found, int $depth = 0): void
    {
        if (! is_array($node) || $depth > 6) {
            return;
        }
        foreach ($node as $k => $v) {
            if (in_array($k, $keys, true) && is_string($v)) {
                $found[] = $v;
            } elseif (is_array($v)) {
                $this->collectByKey($v, $keys, $found, $depth + 1);
            }
        }
    }

    /** @return list<string> dotted key paths of a payload (no values), to log its shape */
    private function keyPaths(mixed $node, string $prefix = '', int $depth = 0): array
    {
        if (! is_array($node) || $depth > 3) {
            return [];
        }
        $out = [];
        foreach ($node as $k => $v) {
            $path = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            $out[] = $path;
            array_push($out, ...$this->keyPaths($v, $path, $depth + 1));
        }

        return array_slice($out, 0, 60);
    }

    /** @param array<string,mixed> $payload */
    private function mapType(string $wahaType, array $payload): string
    {
        // WAHA sends a photo, video or file as type "chat" (or "text") with hasMedia=true and the
        // caption as the body. Decide the kind from the MIME type, or it is stored as an empty text.
        if (in_array(strtolower($wahaType), ['chat', 'text'], true) && ($payload['hasMedia'] ?? false) === true) {
            $mime = strtolower((string) (is_array($payload['media'] ?? null)
                ? ($payload['media']['mimetype'] ?? '')
                : ($payload['mimetype'] ?? '')));

            return match (true) {
                str_starts_with($mime, 'image/') => 'image',
                str_starts_with($mime, 'video/') => 'video',
                str_starts_with($mime, 'audio/') => 'audio',
                default => 'document',
            };
        }

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
