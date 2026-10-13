<?php

namespace App\Services;

use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Services\WahaMediaStore;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fills in the media link of a message.received webhook body.
 *
 * A WhatsApp Web photo or file is saved after MessageReceived fires, so the listener only leaves a
 * placeholder in the payload. DispatchWebhookJob calls resolve() on every attempt, so each attempt
 * gets its own signed link. Never throws: when the lookup fails the webhook is still sent with url null.
 */
class WebhookMediaLinks
{
    /** Minutes a link stays valid (one hour). */
    private const LINK_MINUTES = 60;

    public function __construct(private readonly WahaMediaStore $media) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolve(array $payload): array
    {
        if (($payload['event'] ?? null) !== 'message.received' || ! $this->isPlaceholder($payload)) {
            return $payload;
        }

        $messageId = $payload['message']['id'] ?? null;

        try {
            $message = Message::query()->whereKey($messageId)->first();
            if (! $message) {
                return $payload;
            }

            $stored = is_array($message->getAttribute('payload')) ? $message->getAttribute('payload') : [];
            $media = $payload['message']['media'];
            $media['mime_type'] = $this->text($stored['mime_type'] ?? null);
            // WhatsApp Web files keep their name under the type key (payload.document.filename), not at the top level.
            $typeKey = $message->getAttribute('type');
            $typed = is_string($typeKey) && is_array($stored[$typeKey] ?? null) ? $stored[$typeKey] : [];
            $media['filename'] = $this->text($stored['filename'] ?? $typed['filename'] ?? null);

            $path = $this->media->existingPath($message, $stored);
            if ($path !== null) {
                $media['url'] = Storage::disk('local')->temporaryUrl($path, now()->addMinutes(self::LINK_MINUTES));
            }

            $payload['message']['media'] = $media;

            return $payload;
        } catch (\Throwable $e) {
            // $payload is only changed on the last line above, so this returns it with url null.
            Log::warning('webhook.media_link_failed', ['message_id' => $messageId, 'error' => $e->getMessage()]);

            return $payload;
        }
    }

    /**
     * A media object with no id and no url: the listener's placeholder for a stored WhatsApp Web file.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isPlaceholder(array $payload): bool
    {
        $media = $payload['message']['media'] ?? null;

        return is_array($media) && ($media['id'] ?? null) === null && ($media['url'] ?? null) === null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
