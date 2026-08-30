<?php

namespace App\Listeners;

use App\Events\SubscriptionRenewed;
use App\Notifications\SubscriptionRenewedNotification;
use App\Services\Mail\MailService;
use Illuminate\Support\Facades\Log;

class SendSubscriptionRenewedNotification
{
    public function handle(SubscriptionRenewed $event): void
    {
        $user = $event->user;
        $plan = $event->plan;
        $subscription = $event->subscription;
        $amount = number_format($event->amountCents / 100, 2);
        $nextRenewal = $subscription->renews_at?->format('M j, Y');

        try {
            app(MailService::class)->sendWithTemplate('subscription_renewed', $user->email, [
                'app_name'     => config('app.name'),
                'user_name'    => $user->name,
                'plan_name'    => $plan->name,
                'amount'       => $amount,
                'currency'     => $event->currency,
                'next_renewal' => $nextRenewal ?? '—',
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionRenewedNotification: mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        try {
            $user->notify(new SubscriptionRenewedNotification($plan->name, $amount, $event->currency, $nextRenewal));
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionRenewedNotification: in-app notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
