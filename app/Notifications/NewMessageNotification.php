<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Message $message,
        public readonly Conversation $conversation,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        // Email is intentionally not offered for new-message notifications: an
        // email per inbound message is too noisy. Use web push / OneSignal instead.

        if ($this->isEnabled($notifiable, 'web_push')) {
            $channels[] = WebPushChannel::class;
        }

        // OneSignal push: always send when configured so the user gets notified
        // even when the browser is closed. Client-side foregroundWillDisplay
        // suppresses the notification when the inbox is already open.
        if (app(OneSignalChannel::class)->isConfigured()) {
            $channels[] = OneSignalChannel::class;
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_message',
            'message_id' => $this->message->id,
            'conversation_id' => $this->conversation->id,
            'contact_name' => $this->conversation->contact?->name ?? 'Unknown',
            'snippet' => mb_substr((string) $this->message->body, 0, 120),
            'url' => route('client.inbox.show', $this->conversation),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /**
     * Safety net only — via() never returns the 'mail' channel (new-message
     * emails are intentionally disabled as too noisy). This method exists so a
     * stale 'mail' job left in the queue from before that change drains without
     * fataling the worker (queued notifications bake the channel in at dispatch
     * time). It can be removed once no legacy 'mail' jobs remain.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New message from '.($this->conversation->contact?->name ?? 'a contact'))
            ->line('You have a new message in your inbox.')
            ->action('View Conversation', route('client.inbox.show', $this->conversation));
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'New message',
            'body' => $this->conversation->contact?->name ?? 'A contact sent a message',
            'url' => route('client.inbox.show', $this->conversation),
        ];
    }

    public function toOneSignal(object $notifiable): array
    {
        $contact = $this->conversation->contact;
        $name    = trim(implode(' ', array_filter([$contact?->first_name, $contact?->last_name])));
        $channel = ucfirst($this->conversation->channel_account?->channel ?? 'message');
        $snippet = mb_substr((string) $this->message->body, 0, 100);

        return [
            'title' => $name ?: 'New message',
            'body'  => $snippet ?: "New {$channel} message",
            'url'   => route('client.inbox.show', $this->conversation),
            // Extra data so the service worker can collapse duplicate notifications
            // for the same conversation.
            'conversation_id' => $this->conversation->id,
        ];
    }

    private function isEnabled(object $notifiable, string $channel): bool
    {
        $pref = NotificationPreference::where('user_id', $notifiable->id)
            ->where('event', 'new_message')
            ->where('channel', $channel)
            ->first();

        return $pref === null || $pref->enabled;
    }
}
