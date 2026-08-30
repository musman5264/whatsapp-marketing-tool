<?php

namespace App\Listeners;

use App\Events\SubscriptionStarted;
use App\Notifications\SubscriptionStartedNotification;
use App\Services\Mail\MailService;
use Illuminate\Support\Facades\Log;

class SendSubscriptionStartedNotification
{
    public function handle(SubscriptionStarted $event): void
    {
        $user = $event->user;
        $plan = $event->plan;
        $subscription = $event->subscription;

        try {
            app(MailService::class)->sendWithTemplate('subscription_started', $user->email, [
                'app_name'     => config('app.name'),
                'user_name'    => $user->name,
                'plan_name'    => $plan->name,
                'billing_cycle' => $subscription->billing_cycle ?? 'month',
                'starts_at'    => $subscription->starts_at?->format('M j, Y') ?? now()->format('M j, Y'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionStartedNotification: mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        try {
            $user->notify(new SubscriptionStartedNotification($plan->name, $subscription->billing_cycle ?? 'month'));
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionStartedNotification: in-app notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
