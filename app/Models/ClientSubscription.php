<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientSubscription extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const BILLING_MONTHLY = 'monthly';
    public const BILLING_YEARLY = 'yearly';

    protected $fillable = [
        'client_id',
        'plan_id',
        'billing_cycle',
        'starts_at',
        'ends_at',
        'status',
        'assigned_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function assignedByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_by_admin_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
