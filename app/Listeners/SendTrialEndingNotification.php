<?php

namespace App\Listeners;

use App\Events\TrialEnding;
use App\Notifications\TrialEndingNotification;
use App\Services\Mail\MailService;
use Illuminate\Support\Facades\Log;

class SendTrialEndingNotification
{
    public function handle(TrialEnding $event): void
    {
        $user = $event->user;
        $plan = $event->plan;
        $trialEndsAt = $event->subscription->trial_ends_at?->format('M j, Y') ?? '—';

        try {
            app(MailService::class)->sendWithTemplate('trial_ending', $user->email, [
                'app_name'       => config('app.name'),
                'user_name'      => $user->name,
                'plan_name'      => $plan->name,
                'days_remaining' => (string) $event->daysRemaining,
                'trial_ends_at'  => $trialEndsAt,
                'billing_url'    => route('client.billing.index'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendTrialEndingNotification: mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        try {
            $user->notify(new TrialEndingNotification($plan->name, $event->daysRemaining, $trialEndsAt));
        } catch (\Throwable $e) {
            Log::warning('SendTrialEndingNotification: in-app notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
