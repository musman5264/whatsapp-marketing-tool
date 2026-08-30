<?php

namespace App\Notifications;

use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class SubscriptionCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $planName,
        public readonly ?string $endsAt,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];
        if ($this->isEnabled($notifiable, 'one_signal')) {
            $channels[] = OneSignalChannel::class;
        }
        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'      => 'subscription_cancelled',
            'plan_name' => $this->planName,
            'ends_at'   => $this->endsAt,
            'message'   => "Your {$this->planName} subscription has been cancelled.",
            'url'       => route('client.billing.index'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toOneSignal(object $notifiable): array
    {
        return [
            'title' => 'Subscription cancelled',
            'body'  => "Your {$this->planName} subscription has been cancelled.",
            'url'   => route('client.billing.index'),
        ];
    }

    private function isEnabled(object $notifiable, string $channel): bool
    {
        $pref = \App\Models\NotificationPreference::where('user_id', $notifiable->id)
            ->where('event', 'subscription_cancelled')
            ->where('channel', $channel)
            ->first();
        return $pref === null || $pref->enabled;
    }
}
