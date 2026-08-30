<?php

namespace App\Events;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class SubscriptionStarted
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly Subscription $subscription,
        public readonly Plan $plan,
    ) {}
}
