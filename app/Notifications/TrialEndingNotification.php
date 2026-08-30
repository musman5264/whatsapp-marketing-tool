<?php

namespace App\Notifications;

use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class TrialEndingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $planName,
        public readonly int $daysRemaining,
        public readonly string $trialEndsAt,
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
            'type'           => 'trial_ending',
            'plan_name'      => $this->planName,
            'days_remaining' => $this->daysRemaining,
            'trial_ends_at'  => $this->trialEndsAt,
            'message'        => "Your {$this->planName} trial ends in {$this->daysRemaining} day(s).",
            'url'            => route('client.billing.index'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toOneSignal(object $notifiable): array
    {
        return [
            'title' => 'Trial ending soon',
            'body'  => "Your {$this->planName} trial ends in {$this->daysRemaining} day(s).",
            'url'   => route('client.billing.index'),
        ];
    }

    private function isEnabled(object $notifiable, string $channel): bool
    {
        $pref = \App\Models\NotificationPreference::where('user_id', $notifiable->id)
            ->where('event', 'trial_ending')
            ->where('channel', $channel)
            ->first();
        return $pref === null || $pref->enabled;
    }
}
