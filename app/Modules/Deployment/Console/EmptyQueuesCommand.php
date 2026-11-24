<?php

namespace App\Modules\Deployment\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Queue;

/**
 * Empties the queues: waiting jobs on every queue the app uses, and the failed-jobs list.
 *
 *   php artisan queue:empty-all                 dry run: counts, deletes nothing
 *   php artisan queue:empty-all --apply         deletes waiting jobs and failed jobs
 *   php artisan queue:empty-all --failed-only --apply   deletes only the failed-jobs list
 *
 * Deleted jobs are gone for good (queued sends, automation runs, outbound webhooks). It covers the
 * default connection and the old `database` one, which can still hold jobs from before a switch.
 * See app/logic/deployment-commands.md.
 */
class EmptyQueuesCommand extends Command
{
    /** Every name passed to onQueue() in app/, plus Laravel's own default. */
    public const QUEUES = ['default', 'whatsapp', 'broadcast', 'automation', 'social', 'leads', 'ai'];

    protected $signature = 'queue:empty-all
        {--apply : Delete the jobs. Without it, nothing changes.}
        {--failed-only : Only the failed-jobs list, leave waiting jobs alone.}';

    protected $description = 'Delete waiting and failed queue jobs (dry run unless --apply)';

    public function handle(FailedJobProviderInterface $failer): int
    {
        $apply = (bool) $this->option('apply');
        $exit = self::SUCCESS;

        $failed = count($failer->all());
        $this->line(sprintf('%d failed job(s).', $failed));
        if ($apply) {
            $failer->flush();
            $this->info('Failed jobs deleted.');
        }

        if (! $this->option('failed-only')) {
            foreach ($this->connections() as $connection) {
                try {
                    $queue = Queue::connection($connection);
                    $waiting = 0;
                    foreach (self::QUEUES as $name) {
                        $waiting += $apply && $queue instanceof ClearableQueue
                            ? $queue->clear($name)
                            : $queue->size($name);
                    }
                    $this->line(sprintf('%s: %d waiting job(s)%s.', $connection, $waiting, $apply ? ' deleted' : ''));
                } catch (\Throwable $e) {
                    // One unreachable connection (for example Redis down) must not hide the others.
                    $this->error(sprintf('%s: skipped (%s)', $connection, $e->getMessage()));
                    $exit = self::FAILURE;
                }
            }
        }

        if (! $apply) {
            $this->warn('Nothing was deleted. Run with --apply to delete.');
        }

        return $exit;
    }

    /** @return list<string> */
    private function connections(): array
    {
        $names = array_unique([(string) config('queue.default'), 'database']);

        return array_values(array_filter($names, fn (string $n) => in_array(config("queue.connections.$n.driver"), ['database', 'redis'], true)));
    }
}
