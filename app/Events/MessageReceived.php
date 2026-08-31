<?php

namespace App\Events;

use App\Modules\Shared\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts synchronously (ShouldBroadcastNow) so the inbox updates the instant
 * a message lands — shared hosting has no persistent queue worker to drain a
 * queued broadcast. The extra ~100-300ms HTTP call to the socket server happens
 * inside the request that created the message.
 */
class MessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): array
    {
        $wsId = $this->message->conversation->workspace_id ?? null;
        $convId = $this->message->conversation_id;

        $channels = [new PrivateChannel("conversation.{$convId}")];

        if ($wsId) {
            $channels[] = new PrivateChannel("workspace.{$wsId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'MessageReceived';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'direction' => $this->message->direction,
            'channel' => $this->message->channel,
            'type' => $this->message->type,
            'body' => $this->message->body,
            'payload' => $this->message->payload,
            'status' => $this->message->status,
            'sent_at' => $this->message->sent_at?->toIso8601String(),
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
