<?php

namespace App\Events;

use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationAssigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly ?User $assignedTo,
    ) {}

    public function broadcastOn(): array
    {
        $wsId = $this->conversation->workspace_id;
        $convId = $this->conversation->id;

        return [
            new PrivateChannel("workspace.{$wsId}"),
            new PrivateChannel("conversation.{$convId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ConversationAssigned';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'assigned_to' => $this->assignedTo ? [
                'id' => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ] : null,
        ];
    }
}
