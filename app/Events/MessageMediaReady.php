<?php

namespace App\Events;

use App\Modules\Shared\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once a WhatsApp Web media file has been stored, so an open chat can show it
 * without a reload. Synchronous, like MessageReceived.
 */
class MessageMediaReady implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->message->getAttribute('conversation_id'))];
    }

    public function broadcastAs(): string
    {
        return 'MessageMediaReady';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $payload = $this->message->getAttribute('payload');
        $payload = is_array($payload) ? $payload : [];

        return [
            'id' => $this->message->getKey(),
            'conversation_id' => $this->message->getAttribute('conversation_id'),
            'preview_url' => $payload['preview_url'] ?? null,
            'mime_type' => $payload['mime_type'] ?? null,
        ];
    }
}
