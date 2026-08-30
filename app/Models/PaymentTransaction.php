<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'subscription_id',
        'user_id',
        'gateway',
        'gateway_transaction_id',
        'amount_cents',
        'currency_code',
        'currency',
        'status',
        'payload',
        'coupon_id',
        'refunded_at',
        'refund_reason',
        'refunded_cents',
        'invoice_path',
        'tax_amount_cents',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'refunded_cents' => 'integer',
            'tax_amount_cents' => 'integer',
            'payload' => 'array',
            'refunded_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
