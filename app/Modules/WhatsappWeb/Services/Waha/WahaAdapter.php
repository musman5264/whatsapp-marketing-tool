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
    public function __construct(private readonly WahaClient $client) {}

    public function startSession(string $session, string $webhookUrl): void
    {
        $config = [
            'webhooks' => [[
                'url' => $webhookUrl,
                'events' => ['message', 'session.status', 'message.ack'],
            ]],
        ];

        // Does the session already exist? (WAHA Core allows only one, and returns
        // 403/409/422 on a duplicate create — treat any of those as "exists".)
        $existing = $this->client->get("/api/sessions/{$session}");

        if (! $existing->successful()) {
            $create = $this->client->post('/api/sessions', [
                'name' => $session,
                'start' => true,
                'config' => $config,
            ]);

            if (! $create->successful() && ! in_array($create->status(), [403, 409, 422], true)) {
                throw new \RuntimeException($this->createError($session, $create->status(), $create->body()));
            }
        }

        // Bring the (new or pre-existing) session up to date and running.
        $this->client->post("/api/sessions/{$session}", ['config' => $config]);
        $this->client->post("/api/sessions/{$session}/start");
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
