<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingEvent extends Model
{
    protected $fillable = [
        'gateway',
        'event_id',
        'event_type',
        'payload',
        'processed_at',
        'error',
        'attempts',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function isProcessed(): bool
    {
        return ! is_null($this->processed_at);
    }

    public function hasFailed(): bool
    {
        return ! is_null($this->error);
    }
}
