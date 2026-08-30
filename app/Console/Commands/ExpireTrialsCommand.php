<?php

namespace App\Console\Commands;

use App\Events\SubscriptionExpired;
use App\Models\Subscription;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireTrialsCommand extends Command
{
    protected $signature = 'billing:expire-trials';

    protected $description = 'Expire trialing subscriptions whose trial period has ended without converting to paid.';

    public function handle(BillingGatewayRegistry $registry): int
    {
        $candidates = Subscription::query()
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->with('user', 'plan')
            ->get();

        $count = 0;
        foreach ($candidates as $subscription) {
            try {
                // Reconcile with the gateway first. A trial that has already converted to paid
                // (status now 'active', or trial extended) must NOT be cancelled here — otherwise
                // a delayed/missed conversion webhook would revoke access from a paying customer.
                if ($subscription->gateway && $subscription->gateway_subscription_id) {
                    $gateway = $registry->get($subscription->gateway);
                    if ($gateway) {
                        $gateway->sync($subscription);
                        $subscription->refresh();
                    }
                }

                if ($subscription->status !== 'trialing'
                    || $subscription->trial_ends_at === null
                    || $subscription->trial_ends_at->isFuture()) {
                    continue;
                }

                $subscription->update(['status' => 'canceled', 'ends_at' => $subscription->trial_ends_at]);

                if ($subscription->user && $subscription->plan) {
                    SubscriptionExpired::dispatch($subscription->user, $subscription, $subscription->plan);
                }

                $count++;
            } catch (\Throwable $e) {
                Log::error('billing:expire-trials error', ['sub_id' => $subscription->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Expired {$count} trial subscription(s).");

        return self::SUCCESS;
    }
}
