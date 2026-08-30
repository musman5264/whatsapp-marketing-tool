<?php

namespace App\Modules\Ecommerce\Models;

use App\Modules\Shared\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $store_id
 * @property int|null $contact_id
 * @property string $external_id
 * @property float $total
 * @property string|null $recovery_url
 * @property \Illuminate\Support\Carbon|null $recovered_at
 * @property \Illuminate\Support\Carbon|null $recovery_triggered_at
 */
class EcommerceCart extends Model
{
    protected $table = 'ecommerce_carts';

    protected $fillable = [
        'workspace_id', 'store_id', 'contact_id', 'external_id', 'total', 'currency',
        'line_items', 'recovery_url', 'abandoned_at', 'recovered_at', 'recovery_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'line_items' => 'array',
            'total' => 'decimal:2',
            'abandoned_at' => 'datetime',
            'recovered_at' => 'datetime',
            'recovery_triggered_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(EcommerceStore::class, 'store_id');
    }
}
