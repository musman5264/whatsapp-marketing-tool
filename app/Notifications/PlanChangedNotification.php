<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class PlanChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $oldPlanName,
        public readonly string $newPlanName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'plan_changed',
            'old_plan_name' => $this->oldPlanName,
            'new_plan_name' => $this->newPlanName,
            'message'       => "Your plan has changed from {$this->oldPlanName} to {$this->newPlanName}.",
            'url'           => route('client.billing.index'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
