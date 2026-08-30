<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillingPaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $amount,
        public readonly string $currency = 'USD',
    ) {}

    public function via(object $notifiable): array
    {
        // The deliverable email is sent separately through MailService (the configured
        // SMTP transport). This notification only drives the in-app / push channels,
        // so it never depends on the default `mail` mailer (which is `log` by default).
        $channels = ['database', 'broadcast'];

        if ($this->isEnabled($notifiable, 'one_signal')) {
            $channels[] = OneSignalChannel::class;
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'billing_failed',
            'invoice_id' => $this->invoiceId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'url' => route('client.billing.index'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('mail.billing.payment-failed', [
                'name' => $notifiable->name ?? 'there',
                'amount' => $this->amount,
                'currency' => $this->currency,
                'actionUrl' => route('client.billing.index'),
            ])
            ->subject('Payment failed – action required');
    }

    public function toOneSignal(object $notifiable): array
    {
        return [
            'title' => 'Payment failed — action required',
            'body' => "{$this->amount} {$this->currency} could not be charged",
            'url' => route('client.billing.index'),
        ];
    }

    private function isEnabled(object $notifiable, string $channel): bool
    {
        $pref = NotificationPreference::where('user_id', $notifiable->id)
            ->where('event', 'billing_failed')
            ->where('channel', $channel)
            ->first();

        return $pref === null || $pref->enabled;
    }
}
