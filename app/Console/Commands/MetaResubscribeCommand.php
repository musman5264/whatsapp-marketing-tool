<?php

namespace App\Console\Commands;

use App\Modules\Inbox\Services\MetaWebhookSubscriptionService;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Console\Command;

/**
 * Re-runs the Meta app-level and page-level webhook subscriptions for every
 * connected Instagram/Messenger channel account. Run once after upgrading —
 * accounts connected by older versions were never subscribed at the page
 * level, so Meta silently delivered no inbound messages for them.
 */
class MetaResubscribeCommand extends Command
{
    protected $signature = 'meta:resubscribe
        {--workspace= : Only re-subscribe accounts of this workspace id}';

    protected $description = 'Re-register Meta webhook subscriptions for all connected Instagram/Messenger accounts';

    public function handle(MetaWebhookSubscriptionService $webhooks): int
    {
        $accounts = ChannelAccount::whereIn('channel', ['instagram', 'messenger'])
            ->when($this->option('workspace'), fn ($q, $ws) => $q->where('workspace_id', $ws))
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No Instagram/Messenger channel accounts found.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($accounts as $account) {
            $result = $webhooks->resubscribe($account);

            $this->line(sprintf(
                '[%s] #%d %s (workspace %d): %s',
                $account->channel,
                $account->id,
                $account->display_name,
                $account->workspace_id,
                $result['ok'] ? '<info>OK</info>' : '<error>FAILED</error>',
            ));

            foreach ($result['steps'] as $step) {
                $this->line(sprintf('    - %s: %s', $step['key'], $step['detail']));
            }

            if (! $result['ok']) {
                $failed++;
            }
        }

        $this->newLine();
        $this->info(sprintf('%d account(s) processed, %d failed.', $accounts->count(), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
