<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class SubscriptionRenewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $planName,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $nextRenewal,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'subscription_renewed',
            'plan_name'    => $this->planName,
            'amount'       => $this->amount,
            'currency'     => $this->currency,
            'next_renewal' => $this->nextRenewal,
            'message'      => "Your {$this->planName} subscription has been renewed.",
            'url'          => route('client.billing.index'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
