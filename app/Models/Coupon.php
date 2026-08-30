<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'kind',
        'amount',
        'duration',
        'duration_in_months',
        'applies_to_plan_ids',
        'max_redemptions',
        'times_redeemed',
        'enabled',
        'expires_at',
        'stripe_coupon_id',
    ];

    protected $casts = [
        'applies_to_plan_ids' => 'array',
        'enabled' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function isValid(): bool
    {
        if (! $this->enabled) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        if ($this->max_redemptions && $this->times_redeemed >= $this->max_redemptions) {
            return false;
        }

        return true;
    }

    /**
     * Calculate discount amount in cents for a given price in cents.
     */
    public function discountCents(int $priceCents): int
    {
        if ($this->kind === 'percent') {
            return (int) round($priceCents * ($this->amount / 100));
        }

        // Fixed — amount is in the plan's currency units * 100
        return min($this->amount, $priceCents);
    }

    public function transactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}
