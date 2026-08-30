<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Billing\BillingGatewayRegistry;
use App\Services\Billing\TapGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Bills Tap subscriptions that are due for renewal.
 *
 * Tap has no hosted auto-renewing subscription product, so renewals are merchant-initiated:
 * this command finds active Tap subscriptions whose renews_at has passed and charges the
 * saved card off-session (see TapGateway::chargeRecurring). Gateways with native
 * webhook-driven renewals (Stripe/PayPal/Paddle/Razorpay/Cashfree) are untouched.
 */
class ChargeRecurringTapCommand extends Command
{
    protected $signature = 'billing:charge-recurring {--dry-run : List due subscriptions without charging}';

    protected $description = 'Charge saved cards for Tap subscriptions due for renewal (merchant-initiated recurring).';

    public function handle(BillingGatewayRegistry $registry): int
    {
        $gateway = $registry->get('tap');
        if (! $gateway instanceof TapGateway || ! $gateway->isConfigured()) {
            $this->info('Tap gateway is not configured — nothing to charge.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $due = Subscription::with('plan')
            ->where('gateway', 'tap')
            ->where('status', 'active')
            ->whereNotNull('renews_at')
            ->where('renews_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get();

        $this->info("Found {$due->count()} Tap subscription(s) due for renewal.");

        $charged = 0;
        $failed = 0;

        foreach ($due as $sub) {
            if ($dryRun) {
                $this->line("  [dry-run] Sub #{$sub->id} (user {$sub->user_id}) due {$sub->renews_at}");

                continue;
            }

            try {
                $result = $gateway->chargeRecurring($sub);
                if ($result['ok'] ?? false) {
                    $charged++;
                    $this->line("  Sub #{$sub->id}: charged.");
                } else {
                    $failed++;
                    $this->warn("  Sub #{$sub->id}: ".($result['error'] ?? 'unknown error'));
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  Sub #{$sub->id} error: ".$e->getMessage());
                Log::error('billing:charge-recurring error', ['sub_id' => $sub->id, 'error' => $e->getMessage()]);
            }
        }

        if (! $dryRun) {
            $this->newLine();
            $this->table(['Metric', 'Count'], [
                ['Due', $due->count()],
                ['Charged', $charged],
                ['Failed', $failed],
            ]);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
