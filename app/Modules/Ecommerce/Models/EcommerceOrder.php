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
 * @property string $external_order_id
 * @property string $platform
 * @property string|null $number
 * @property string|null $fulfillment_status
 * @property float $total
 * @property array<int, mixed>|null $line_items
 */
class EcommerceOrder extends Model
{
    protected $table = 'ecommerce_orders';

    protected $fillable = [
        'workspace_id', 'store_id', 'contact_id', 'external_order_id', 'platform',
        'number', 'status', 'financial_status', 'fulfillment_status', 'currency',
        'total', 'line_items', 'tracking_url', 'tracking_number', 'placed_at', 'raw',
    ];

    /** `raw` holds the full platform payload incl. customer PII — never serialize it. */
    protected $hidden = ['raw'];

    protected function casts(): array
    {
        return [
            'line_items' => 'array',
            'raw' => 'array',
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
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
