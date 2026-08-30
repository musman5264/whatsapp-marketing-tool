<?php

namespace App\Listeners;

use App\Events\MessageReceived;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class SendNewMessageNotification
{
    public function handle(MessageReceived $event): void
    {
        $msgId = $event->message->id ?? null;
        if ($msgId && ! Cache::add("notif_new_msg:{$msgId}", 1, 60)) {
            return;
        }

        $conversation = $event->message->conversation;

        if (! $conversation) {
            return;
        }

        // Notify assigned agent, or all workspace members if unassigned
        if ($conversation->assigned_user_id) {
            $recipients = User::where('id', $conversation->assigned_user_id)->get();
        } else {
            $workspaceId = $conversation->workspace_id;
            $recipients = User::where('workspace_id', $workspaceId)->get();
        }

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send(
            $recipients,
            new NewMessageNotification($event->message, $conversation),
        );
    }
}
