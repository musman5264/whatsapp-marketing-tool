<?php

namespace App\Console\Commands;

use App\Events\TrialEnding;
use App\Models\Subscription;
use Illuminate\Console\Command;

class NotifyTrialEndingCommand extends Command
{
    protected $signature = 'notifications:trial-ending {--days=3 : Days before trial ends to send reminder}';

    protected $description = 'Notify users whose trial period ends in the given number of days.';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));

        // Window covering the whole target calendar day (avoids whereDate timezone off-by-one
        // at day boundaries). trial_reminder_sent_at guards against duplicate sends across runs.
        $target = now()->addDays($days);

        $subscriptions = Subscription::query()
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->whereNull('trial_reminder_sent_at')
            ->whereBetween('trial_ends_at', [$target->copy()->startOfDay(), $target->copy()->endOfDay()])
            ->with('user', 'plan')
            ->get();

        $count = 0;
        foreach ($subscriptions as $subscription) {
            if (! $subscription->user || ! $subscription->plan) {
                continue;
            }

            TrialEnding::dispatch($subscription->user, $subscription, $subscription->plan, $days);
            $subscription->forceFill(['trial_reminder_sent_at' => now()])->save();
            $count++;
        }

        $this->info("Dispatched trial-ending notifications for {$count} subscription(s) ending in {$days} day(s).");

        return self::SUCCESS;
    }
}
