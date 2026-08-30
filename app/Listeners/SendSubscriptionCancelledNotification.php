<?php

namespace App\Listeners;

use App\Events\SubscriptionCancelled;
use App\Notifications\SubscriptionCancelledNotification;
use App\Services\Mail\MailService;
use Illuminate\Support\Facades\Log;

class SendSubscriptionCancelledNotification
{
    public function handle(SubscriptionCancelled $event): void
    {
        $user = $event->user;
        $plan = $event->plan;
        $subscription = $event->subscription;
        $endsAt = $subscription->ends_at?->format('M j, Y');

        try {
            app(MailService::class)->sendWithTemplate('subscription_cancelled', $user->email, [
                'app_name'  => config('app.name'),
                'user_name' => $user->name,
                'plan_name' => $plan->name,
                'ends_at'   => $endsAt ?? 'immediately',
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionCancelledNotification: mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        try {
            $user->notify(new SubscriptionCancelledNotification($plan->name, $endsAt));
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionCancelledNotification: in-app notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
