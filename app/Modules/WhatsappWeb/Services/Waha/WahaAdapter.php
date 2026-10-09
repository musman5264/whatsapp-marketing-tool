<?php

namespace App\Modules\WhatsappWeb\Services\Waha;

use App\Modules\WhatsappWeb\Contracts\EngineAdapter;
use Illuminate\Support\Facades\Log;

/**
 * WAHA implementation of EngineAdapter.
 *
 * WAHA REST reference: https://waha.devlike.pro/docs/how-to/
 *  - POST   /api/sessions                       create a session
 *  - POST   /api/sessions/{s}/start             start it
 *  - PUT    /api/sessions/{s}                   update config (webhooks); restarts a running session
 *  - GET    /api/{s}/chats/overview?limit=      recent chats (history sync)
 *  - GET    /api/{s}/chats/{chatId}/messages    recent messages of a chat (history sync)
 *  - GET    /api/sessions/{s}                   status ({status: STARTING|SCAN_QR_CODE|WORKING|FAILED|STOPPED})
 *  - GET    /api/{s}/auth/qr?format=image       QR (binary PNG) — we request base64
 *  - GET    /api/sessions/{s}/me                paired account ({id, pushName})
 *  - POST   /api/sessions/{s}/logout            unlink
 *  - DELETE /api/sessions/{s}                   delete
 *  - POST   /api/sendText  {session, chatId, text}
 *  - POST   /api/sendImage|sendFile|sendVoice|sendVideo {session, chatId, file:{url}, caption}
 *  - POST   /api/sendLocation {session, chatId, latitude, longitude, title}
 *
 * chatId is `<digits>@c.us` (no leading +).
 */
class WahaAdapter implements EngineAdapter
{
    /**
     * Webhook events subscribed on every session. Append new engine events
     * (e.g. label.*) here — this is the single source of truth.
     *
     * `message.any` (not `message`) so messages the user sends from the phone or
     * another linked device are delivered too (payload.fromMe = true). Our own
     * API sends come back with payload.source = 'api' and are skipped downstream.
     */
    public const WEBHOOK_EVENTS = [
        'message.any', 'session.status', 'message.ack',
        'message.reaction', 'poll.vote',
        'call.received', 'call.accepted', 'call.rejected',
        'label.upsert', 'label.deleted', 'label.chat.added', 'label.chat.deleted',
    ];

    public function __construct(private readonly WahaClient $client) {}

    public function startSession(string $session, string $webhookUrl, ?string $hmacSecret = null): void
    {
        // Does the session already exist? (WAHA Core allows only one, and returns
        // 403/409/422 on a duplicate create — treat any of those as "exists".)
        $existing = $this->client->get("/api/sessions/{$session}");

        if (! $existing->successful()) {
            $create = $this->client->post('/api/sessions', [
                'name' => $session,
                'start' => true,
                'config' => $this->config($webhookUrl, $hmacSecret),
            ]);

            if (! $create->successful() && ! in_array($create->status(), [403, 409, 422], true)) {
                throw new \RuntimeException($this->createError($session, $create->status(), $create->body()));
            }
        }

        // Pre-existing session: bring its webhook config up to date and running.
        $this->resubscribe($session, $webhookUrl, $hmacSecret);
    }

    /**
     * WAHA: `PUT /api/sessions/{session}` updates the config. If the session is
     * not STOPPED, WAHA stops and starts it with the new config (the device stays
     * linked — no logout, no QR). A follow-up `start` covers the STOPPED case;
     * its 422 when already running is expected and ignored.
     */
    public function resubscribe(string $session, string $webhookUrl, ?string $hmacSecret = null): void
    {
        $put = $this->client->put("/api/sessions/{$session}", [
            'name' => $session,
            'config' => $this->config($webhookUrl, $hmacSecret),
        ]);

        if (! $put->successful()) {
            throw new \RuntimeException("WAHA could not update session config ({$put->status()}): ".$put->body());
        }

        $this->client->post("/api/sessions/{$session}/start");
    }

    /** @return array{webhooks: list<array<string,mixed>>} */
    private function config(string $webhookUrl, ?string $hmacSecret): array
    {
        $webhook = [
            'url' => $webhookUrl,
            'events' => self::WEBHOOK_EVENTS,
        ];
        if ($hmacSecret !== null && $hmacSecret !== '') {
            // WAHA signs each webhook body: X-Webhook-Hmac = hmac_sha256(body, key)
            $webhook['hmac'] = ['key' => $hmacSecret];
        }

        return ['webhooks' => [$webhook]];
    }

    /** @return list<array<string,mixed>> */
    public function listChats(string $session, int $limit): array
    {
        $resp = $this->client->get("/api/{$session}/chats/overview", [
            'limit' => $limit,
            'offset' => 0,
        ]);
        if (! $resp->successful()) {
            throw new \RuntimeException("WAHA chats overview failed ({$resp->status()}): ".$resp->body());
        }

        $rows = $resp->json();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string,mixed>> */
    public function chatMessages(string $session, string $chatId, int $limit): array
    {
        $resp = $this->client->get('/api/'.$session.'/chats/'.rawurlencode($chatId).'/messages', [
            'limit' => $limit,
            'offset' => 0,
            'downloadMedia' => 'false',
            'sortBy' => 'timestamp',
            'sortOrder' => 'desc',
        ]);
        if (! $resp->successful()) {
            throw new \RuntimeException("WAHA chat messages failed ({$resp->status()}): ".$resp->body());
        }

        $rows = $resp->json();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function createError(string $session, int $status, string $body): string
    {
        if ($status === 403) {
            // On Core this usually means "another session already exists".
            $sessions = $this->client->get('/api/sessions');
            $names = collect($sessions->json() ?: [])->pluck('name')->filter()->values();
            if ($sessions->successful() && $names->isNotEmpty() && ! $names->contains($session)) {
                return 'This WAHA server already has a linked number ('.$names->implode(', ')
                    .') and is running the free Core edition, which allows only one. '
                    .'Disconnect the other number, or run WAHA Plus for multiple numbers.';
            }
        }

        return "WAHA: could not create session ({$status}): {$body}";
    }

    public function getQr(string $session): ?string
    {
        // format=image → raw PNG bytes (Content-Type: image/png). We base64 it
        // ourselves into a data URI the <img> can render. (format=raw returns the
        // QR *string* content, which is not an image.)
        $resp = $this->client->getBinary("/api/{$session}/auth/qr", ['format' => 'image']);
        if (! $resp->successful()) {
            return null;
        }

        $contentType = strtolower((string) $resp->header('Content-Type'));

        // Some WAHA builds/engines answer with JSON even for format=image.
        if (str_contains($contentType, 'json')) {
            $b64 = $resp->json('data') ?? $resp->json('value') ?? null;
            if (is_string($b64) && $b64 !== '') {
                return str_starts_with($b64, 'data:') ? $b64 : 'data:image/png;base64,'.$b64;
            }

            return null;
        }

        $body = $resp->body();
        if ($body === '') {
            return null;
        }

        $mime = str_contains($contentType, 'image/') ? $contentType : 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($body);
    }

    public function getStatus(string $session): string
    {
        $resp = $this->client->get("/api/sessions/{$session}");
        if (! $resp->successful()) {
            return $resp->status() === 404 ? 'stopped' : 'failed';
        }

        return $this->normaliseStatus((string) ($resp->json('status') ?? ''));
    }

    public function getMe(string $session): ?array
    {
        $resp = $this->client->get("/api/sessions/{$session}/me");
        if (! $resp->successful()) {
            return null;
        }

        $id = (string) ($resp->json('id') ?? '');
        $phone = $id !== '' ? '+'.preg_replace('/\D+/', '', explode('@', $id)[0]) : null;

        return [
            'phone_e164' => $phone && $phone !== '+' ? $phone : null,
            'push_name' => $resp->json('pushName') ?? $resp->json('name') ?? null,
        ];
    }

    /**
     * Resolve a contact id (which may be a LID like `123@lid` that hides the
     * phone number) to the real phone-number JID and display name.
     *
     * @return array{phone_e164: ?string, name: ?string}|null
     */
    public function resolveContact(string $session, string $contactId): ?array
    {
        $resp = $this->client->get('/api/contacts', [
            'session' => $session,
            'contactId' => $contactId,
        ]);
        if (! $resp->successful()) {
            return null;
        }

        // WAHA returns {id: "923...@c.us", number: "<lid digits>", name: "..."}.
        $jid = (string) ($resp->json('id') ?? '');
        $digits = str_ends_with($jid, '@c.us') ? preg_replace('/\D+/', '', explode('@', $jid)[0]) : '';

        return [
            'phone_e164' => $digits !== '' ? '+'.$digits : null,
            'name' => $resp->json('name') ?? $resp->json('pushname') ?? $resp->json('shortName') ?? null,
        ];
    }

    public function logout(string $session): void
    {
        try {
            $this->client->post("/api/sessions/{$session}/logout");
            $this->client->delete("/api/sessions/{$session}");
        } catch (\Throwable $e) {
            Log::warning('whatsapp_web.waha.logout_failed', ['session' => $session, 'error' => $e->getMessage()]);
        }
    }

    public function sendText(string $session, string $toE164, string $body): string
    {
        return $this->send('/api/sendText', [
            'session' => $session,
            'chatId' => $this->chatId($toE164),
            'text' => $body,
        ]);
    }

    public function sendMedia(string $session, string $toE164, string $type, string $url, ?string $caption, ?string $filename): string
    {
        $endpoint = match ($type) {
            'image' => '/api/sendImage',
            'video' => '/api/sendVideo',
            'audio' => '/api/sendVoice',
            default => '/api/sendFile',
        };

        $file = ['url' => $url];
        if ($filename) {
            $file['filename'] = $filename;
        }

        $payload = [
            'session' => $session,
            'chatId' => $this->chatId($toE164),
            'file' => $file,
        ];
        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }

        return $this->send($endpoint, $payload);
    }

    public function sendLocation(string $session, string $toE164, float $latitude, float $longitude, ?string $name, ?string $address): string
    {
        return $this->send('/api/sendLocation', array_filter([
            'session' => $session,
            'chatId' => $this->chatId($toE164),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'title' => trim(implode(' — ', array_filter([$name, $address]))) ?: null,
        ], fn ($v) => $v !== null));
    }

    public function sendPoll(string $session, string $toE164, string $question, array $options, bool $multipleAnswers): string
    {
        return $this->send('/api/sendPoll', [
            'session' => $session,
            'chatId' => $this->chatId($toE164),
            'poll' => [
                'name' => $question,
                'options' => array_values($options),
                'multipleAnswers' => $multipleAnswers,
            ],
        ]);
    }

    public function sendReaction(string $session, string $messageId, string $emoji): void
    {
        $resp = $this->client->put('/api/reaction', [
            'session' => $session,
            'messageId' => $messageId,
            'reaction' => $emoji,
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('WAHA reaction failed ('.$resp->status().'): '.$resp->body());
        }
    }

    public function sendSeen(string $session, string $chatId, ?string $messageId = null): void
    {
        $payload = ['session' => $session, 'chatId' => $chatId];
        if ($messageId !== null && $messageId !== '') {
            $payload['messageId'] = $messageId;
        }
        $this->client->post('/api/sendSeen', $payload);
    }

    public function sendTyping(string $session, string $toE164, bool $on): void
    {
        $this->client->post($on ? '/api/startTyping' : '/api/stopTyping', [
            'session' => $session,
            'chatId' => $this->chatId($toE164),
        ]);
    }

    public function rejectCall(string $session, string $callId): void
    {
        $this->client->post("/api/{$session}/calls/reject", ['callId' => $callId]);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $payload */
    private function send(string $endpoint, array $payload): string
    {
        $resp = $this->client->post($endpoint, $payload);

        if (! $resp->successful()) {
            throw new \RuntimeException('WAHA send failed ('.$resp->status().'): '.$resp->body());
        }

        // WAHA returns {id:{_serialized:"..."}} or {id:"..."} depending on engine.
        return (string) ($resp->json('id._serialized')
            ?? $resp->json('id')
            ?? $resp->json('_data.id._serialized')
            ?? '');
    }

    private function chatId(string $toE164): string
    {
        return preg_replace('/\D+/', '', $toE164).'@c.us';
    }

    private function normaliseStatus(string $wahaStatus): string
    {
        return match (strtoupper($wahaStatus)) {
            'STARTING' => 'connecting',
            'SCAN_QR_CODE' => 'scan_qr',
            'WORKING' => 'active',
            'FAILED' => 'failed',
            'STOPPED' => 'stopped',
            default => 'pending',
        };
    }
}
