<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Events\MessageMediaReady;
use App\Modules\Shared\Models\Message;
use App\Services\StorageManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp Web (WAHA) media: fetch a link through the configured engine, store it
 * under message-media/{id}.{ext}, and record where it is on the message payload.
 */
class WahaMediaStore
{
    /** Message types whose payload carries a downloadable WAHA link. */
    private const MEDIA_TYPES = ['image', 'video', 'audio', 'document'];

    public function __construct(
        private readonly EngineManager $engines,
        private readonly StorageManager $storage,
    ) {}

    /**
     * Called right after an inbound WhatsApp Web message is saved and announced.
     * Stores the file so the bubble has it straight away, then tells an open chat.
     * Soft-fail: a failure is logged and the message stays as it is; the lazy fetch
     * on view still works.
     */
    public function storeInbound(Message $message): void
    {
        $type = (string) $message->getAttribute('type');
        $payload = $message->getAttribute('payload');
        if (! is_array($payload) || ! in_array($type, self::MEDIA_TYPES, true) || ! empty($payload['preview_url'])) {
            return;
        }

        $link = $payload[$type]['link'] ?? null;
        if (! is_string($link) || $link === '') {
            return;
        }

        try {
            $this->store($message, $payload, $link);
            MessageMediaReady::dispatch($message->fresh());
        } catch (Throwable $e) {
            Log::warning('whatsapp_web.media.arrival_failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Download a WAHA media file through the configured engine, store it and
     * record preview_url, mime_type and media_path on the message payload.
     *
     * The request always goes to the configured engine origin. The link's path and
     * query are kept but its host is replaced by the engine base URL, so the engine
     * API key never leaves for another host and no arbitrary URL from a webhook
     * payload is fetched. Error messages never include the key.
     *
     * @param  array<string, mixed>  $payload
     * @return array{preview_url: string, mime_type: string, media_path: string}
     *
     * @throws WahaMediaException
     */
    public function store(Message $message, array $payload, string $link): array
    {
        $creds = $this->engines->credentials();
        $base = $creds?->baseUrl();
        $baseParts = $base ? parse_url($base) : false;
        if (! is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            throw new WahaMediaException(503, 'WhatsApp Web engine is not configured.');
        }

        $linkParts = parse_url($link);
        $path = is_array($linkParts) ? (string) ($linkParts['path'] ?? '') : '';
        if ($path === '') {
            throw new WahaMediaException(404, 'No media available.');
        }

        $origin = $baseParts['scheme'].'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');
        $url = $origin.'/'.ltrim($path, '/').(isset($linkParts['query']) ? '?'.$linkParts['query'] : '');

        $apiKey = $creds->apiKey();
        try {
            $req = Http::timeout(30)->withOptions(['allow_redirects' => ['max' => 3]]);
            if ($apiKey) {
                $req = $req->withHeaders(['X-Api-Key' => $apiKey]);
            }
            $response = $req->get($url);
        } catch (\Throwable $e) {
            Log::warning('WAHA media fetch failed (connection)', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            throw new WahaMediaException(502, 'Could not reach the WhatsApp Web engine for this media.');
        }

        if ($response->status() === 404) {
            throw new WahaMediaException(404, 'This media is no longer available on the WhatsApp Web engine.');
        }
        if (! $response->successful()) {
            Log::warning('WAHA media fetch failed (status)', ['message_id' => $message->id, 'status' => $response->status()]);
            throw new WahaMediaException(502, 'The WhatsApp Web engine could not provide this media (HTTP '.$response->status().').');
        }

        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) > 64 * 1024 * 1024) {
            throw new WahaMediaException(502, 'The WhatsApp Web engine returned an empty or oversized media file.');
        }

        $rawType = trim(explode(';', (string) $response->header('Content-Type'))[0]);
        $mimeType = ($rawType === '' || $rawType === 'application/octet-stream')
            ? (string) ($payload['mime_type'] ?? 'application/octet-stream')
            : $rawType;

        $mediaPath = $this->storage->prefixedPath('message-media/'.$message->id.'.'.$this->extensionForMime($mimeType));
        try {
            $this->storage->disk()->put($mediaPath, $bytes);
        } catch (\Throwable $e) {
            Log::warning('WAHA media store failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            throw new WahaMediaException(502, 'Could not store media.');
        }

        $stored = [
            'preview_url' => $this->storage->disk()->url($mediaPath),
            'mime_type' => $mimeType,
            'media_path' => $mediaPath,
        ];
        $message->update(['payload' => array_merge($payload, $stored)]);

        return $stored;
    }

    /**
     * The stored copy of a message's media, found by its recorded path (or, for copies
     * made before media_path existed, by the name derived from mime_type). Never lists
     * the folder. Null when nothing is stored.
     *
     * @param  array<string, mixed>  $payload
     */
    public function existingPath(Message $message, array $payload): ?string
    {
        // Only this message's own file name is accepted. media_path can come from a
        // request body (outbound payloads), so anything that leaves the folder, or
        // names another message, is ignored and the derived name is used instead.
        $own = $this->storage->prefixedPath('message-media/'.$message->id.'.');
        $candidate = $payload['media_path'] ?? null;
        $tail = is_string($candidate) && str_starts_with($candidate, $own) ? substr($candidate, strlen($own)) : null;
        $trusted = $tail !== null && $tail !== ''
            && ! str_contains($tail, '/') && ! str_contains($tail, '\\') && ! str_contains($tail, '..');

        if (! $trusted) {
            $mime = $payload['mime_type'] ?? null;
            if (! is_string($mime) || $mime === '') {
                return null;
            }
            $candidate = $own.$this->extensionForMime($mime);
        }

        return $this->storage->disk()->exists($candidate) ? $candidate : null;
    }

    /** File extension for a MIME type, e.g. image/jpeg → jpg, audio/mpeg → mp3. */
    private function extensionForMime(string $mimeType): string
    {
        [$major, $minor] = array_pad(explode('/', strtolower($mimeType), 2), 2, 'bin');
        $sub = preg_replace('/[^a-z0-9]/', '', explode('+', $minor)[0]) ?: 'bin';

        return match (true) {
            $sub === 'jpeg' => 'jpg',
            $sub === 'octetstream' => 'bin',
            $sub === 'plain' => 'txt',
            $sub === 'quicktime' => 'mov',
            $sub === 'mpeg' && $major === 'audio' => 'mp3',
            default => $sub,
        };
    }
}
