<?php

namespace Database\Seeders;

use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SubscriptionPaymentSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', env('CLIENT_SEED_EMAIL', 'client@client.com'))->first();
        if (! $user) {
            return;
        }

        $plans = Plan::where('enabled', true)->where('slug', '!=', 'free')->get();
        if ($plans->isEmpty()) {
            return;
        }

        $gateways = ['stripe', 'paypal', 'paddle'];
        $statuses = ['active', 'trialing', 'canceled', 'past_due'];

        foreach ($plans->take(2) as $index => $plan) {
            $gateway = $gateways[$index % count($gateways)];
            $status = $statuses[$index % count($statuses)];

            $startsAt = Carbon::now()->subMonths(rand(1, 6));
            $renewsAt = $status === 'active' || $status === 'trialing' ? Carbon::now()->addMonth() : null;
            $endsAt = in_array($status, ['canceled', 'ended']) ? Carbon::now()->addDays(rand(1, 30)) : null;

            $sub = Subscription::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'gateway' => $gateway,
                ],
                [
                    'status' => $status,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'gateway_subscription_id' => 'sub_' . $gateway . '_' . uniqid(),
                    'renews_at' => $renewsAt,
                ]
            );

            $amountCents = $plan->monthly_price_cents ?? $plan->price_cents ?? 0;
            if ($amountCents > 0) {
                PaymentTransaction::firstOrCreate(
                    [
                        'subscription_id' => $sub->id,
                        'gateway_transaction_id' => 'tx_seed_' . $sub->id . '_1',
                    ],
                    [
                        'user_id' => $user->id,
                        'gateway' => $gateway,
                        'amount_cents' => $amountCents,
                        'currency_code' => $plan->currency_code ?? 'USD',
                        'status' => 'succeeded',
                        'payload' => [],
                    ]
                );
                PaymentTransaction::firstOrCreate(
                    [
                        'subscription_id' => $sub->id,
                        'gateway_transaction_id' => 'tx_seed_' . $sub->id . '_2',
                    ],
                    [
                        'user_id' => $user->id,
                        'gateway' => $gateway,
                        'amount_cents' => $amountCents,
                        'currency_code' => $plan->currency_code ?? 'USD',
                        'status' => 'succeeded',
                        'payload' => [],
                        'created_at' => Carbon::now()->subMonth(),
                    ]
                );
            }
        }

        $pro = Plan::where('slug', 'pro')->first();
        if ($pro && $user) {
            Subscription::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'plan_id' => $pro->id,
                ],
                [
                    'status' => 'active',
                    'gateway' => 'stripe',
                    'starts_at' => Carbon::now()->subMonths(2),
                    'renews_at' => Carbon::now()->addMonth(),
                    'gateway_subscription_id' => 'sub_stripe_pro_' . uniqid(),
                ]
            );
        }
    }
}
