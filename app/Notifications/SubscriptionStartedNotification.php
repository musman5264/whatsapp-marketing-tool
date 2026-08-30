<?php

namespace App\Notifications;

use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class SubscriptionStartedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $planName,
        public readonly string $billingCycle,
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
            'type'         => 'subscription_started',
            'plan_name'    => $this->planName,
            'billing_cycle' => $this->billingCycle,
            'message'      => "Your {$this->planName} subscription is now active.",
            'url'          => route('client.billing.index'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toOneSignal(object $notifiable): array
    {
        return [
            'title' => 'Subscription activated',
            'body'  => "Your {$this->planName} plan is now active.",
            'url'   => route('client.billing.index'),
        ];
    }

    private function isEnabled(object $notifiable, string $channel): bool
    {
        $pref = \App\Models\NotificationPreference::where('user_id', $notifiable->id)
            ->where('event', 'subscription_started')
            ->where('channel', $channel)
            ->first();
        return $pref === null || $pref->enabled;
    }
}
