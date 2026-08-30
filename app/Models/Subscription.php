<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'billing_cycle',
        'starts_at',
        'ends_at',
        'gateway',
        'gateway_subscription_id',
        'gateway_metadata',
        'renews_at',
        'trial_ends_at',
        'trial_reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'renews_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'trial_reminder_sent_at' => 'datetime',
            'gateway_metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function isActive(): bool
    {
        if (! in_array($this->status, ['active', 'trialing'], true)) {
            return false;
        }

        // A subscription left 'active' past its ends_at (e.g. a missed cancellation/expiry
        // webhook) must not keep granting access.
        return $this->ends_at === null || $this->ends_at->isFuture();
    }
}
