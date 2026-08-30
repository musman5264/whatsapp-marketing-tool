<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BillingSyncCommand extends Command
{
    protected $signature   = 'billing:sync {--gateway= : Only sync a specific gateway (stripe|paypal|paddle|razorpay|cashfree)} {--dry-run : Preview changes without saving}';
    protected $description = 'Sync active subscriptions with payment gateways to reconcile status.';

    public function handle(BillingGatewayRegistry $registry): int
    {
        $gatewayFilter = $this->option('gateway');
        $dryRun        = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN mode — no changes will be saved.');
        }

        $query = Subscription::with('plan')
            ->whereNotIn('status', ['canceled'])
            ->when($gatewayFilter, fn ($q) => $q->where('gateway', $gatewayFilter));

        $total     = $query->count();
        $updated   = 0;
        $unchanged = 0;
        $errors    = 0;

        $this->info("Syncing {$total} subscription(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(50, function ($subscriptions) use ($registry, $dryRun, &$updated, &$unchanged, &$errors, $bar) {
            foreach ($subscriptions as $sub) {
                $bar->advance();
                try {
                    if (! $sub->gateway || ! $sub->gateway_subscription_id) {
                        $unchanged++;
                        continue;
                    }

                    $gateway = $registry->get($sub->gateway);
                    if (! $gateway) {
                        $unchanged++;
                        continue;
                    }
                    $previousStatus = $sub->status;

                    if ($dryRun) {
                        // In dry-run, peek at what sync would do without persisting
                        $unchanged++;
                        continue;
                    }

                    $changed = $gateway->sync($sub);
                    $sub->refresh();

                    if ($changed || $sub->status !== $previousStatus) {
                        $this->newLine();
                        $this->line("  Sub #{$sub->id}: {$previousStatus} → {$sub->status}");
                        $updated++;
                    } else {
                        $unchanged++;
                    }
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->error("  Sub #{$sub->id} error: ".$e->getMessage());
                    Log::error('billing:sync error', ['sub_id' => $sub->id, 'error' => $e->getMessage()]);
                    $errors++;
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total checked', $total],
                ['Updated',       $updated],
                ['Unchanged',     $unchanged],
                ['Errors',        $errors],
            ]
        );

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
