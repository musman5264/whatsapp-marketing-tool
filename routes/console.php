<?php

use App\Modules\Broadcasting\Jobs\LaunchScheduledCampaignsJob;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Social\Jobs\DispatchScheduledPostsJob;
use App\Modules\Social\Jobs\RefreshSocialTokensJob;
use App\Modules\Whatsapp\Jobs\TemplateSyncJob;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Http\Controllers\Admin\CronSetupController;
use App\Services\WebhookIdempotencyService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat: records the last time the scheduler ran so the admin "Cron Setup"
// guide can confirm the server's cron entry is actually firing.
Schedule::call(function () {
    Cache::put(CronSetupController::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());
})->everyMinute()->name('scheduler-heartbeat');

// ─── Marketing Suite Scheduled Tasks ────────────────────────────────────────

// Dispatch any campaigns scheduled for now
Schedule::job(new LaunchScheduledCampaignsJob, 'broadcast')
    ->everyMinute()
    ->name('launch-scheduled-campaigns')
    ->withoutOverlapping();

// Sync WhatsApp templates from Meta (once per day)
Schedule::call(function () {
    WhatsappBusinessAccount::all()->each(function ($waba) {
        TemplateSyncJob::dispatch($waba->id)->onQueue('whatsapp');
    });
})->daily()->name('sync-whatsapp-templates');

// Dispatch scheduled social posts (every minute)
Schedule::job(new DispatchScheduledPostsJob, 'social')
    ->everyMinute()
    ->name('dispatch-social-posts')
    ->withoutOverlapping();

// Refresh expiring social OAuth tokens. Twitter/Google access tokens live only
// 1-2 hours, so this must run frequently — a daily pass leaves them expired
// (and the UI showing "Token expired") most of the day.
Schedule::job(new RefreshSocialTokensJob, 'social')
    ->everyFifteenMinutes()
    ->name('refresh-social-tokens')
    ->withoutOverlapping();

// Reset monthly usage meters on the 1st of each month
Schedule::call(function () {
    // Meters older than 2 months are pruned; current month is always kept
    UsageMeter::where('period', '<', (int) now()->subMonths(2)->format('Ym'))->delete();
})->monthlyOn(1, '00:05')->name('reset-usage-meters');

// Prune inbound webhook idempotency records older than 30 days
Schedule::call(function () {
    app(WebhookIdempotencyService::class)->prune(30);
})->weekly()->name('prune-inbound-webhook-events');

// Drain the queues every minute. On hosts without a long-running worker
// (shared / cPanel), this is what actually processes queued jobs — inbound
// WhatsApp webhooks, campaign sends, auto-replies, etc. `--stop-when-empty`
// keeps it short-lived so the cron slot is never blocked; `withoutOverlapping`
// prevents pile-ups if a batch runs long.
Schedule::command('queue:work --queue=whatsapp,broadcast,social,ai,leads,automation,default --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->name('drain-queues')
    ->withoutOverlapping(10);

// Sync subscription statuses with payment gateways (hourly)
Schedule::command('billing:sync')
    ->hourly()
    ->name('billing-sync')
    ->withoutOverlapping()
    ->onOneServer();

// Expire trials that have passed their trial_ends_at and not yet converted
Schedule::command('billing:expire-trials')
    ->hourly()
    ->name('billing-expire-trials')
    ->withoutOverlapping()
    ->onOneServer();

// Bill Tap subscriptions due for renewal (Tap has no hosted auto-renew; merchant-initiated)
Schedule::command('billing:charge-recurring')
    ->hourly()
    ->name('billing-charge-recurring-tap')
    ->withoutOverlapping()
    ->onOneServer();

// Bill Paymob subscriptions due for renewal (MIT save-card pattern)
Schedule::command('billing:charge-recurring-paymob')
    ->hourly()
    ->name('billing-charge-recurring-paymob')
    ->withoutOverlapping()
    ->onOneServer();

// Bill MyFatoorah subscriptions due for renewal (MIT save-token pattern)
Schedule::command('billing:charge-recurring-myfatoorah')
    ->hourly()
    ->name('billing-charge-recurring-myfatoorah')
    ->withoutOverlapping()
    ->onOneServer();

// Notify users whose trial ends in 3 days (daily at 09:00)
Schedule::command('notifications:trial-ending --days=3')
    ->dailyAt('09:00')
    ->name('notify-trial-ending-3d')
    ->withoutOverlapping()
    ->onOneServer();

// Send weekly performance digest to all workspace owners (Monday 09:00)
Schedule::command('reports:weekly-digest')
    ->mondays()
    ->at('09:00')
    ->name('weekly-digest-emails')
    ->withoutOverlapping()
    ->onOneServer();
