<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_cents',
        'currency_code',
        'interval',
        'sort_order',
        'enabled',
        'monthly_price_cents',
        'yearly_price_cents',
        'trial_days',
        'stripe_monthly_id',
        'stripe_yearly_id',
        'paddle_monthly_id',
        'paddle_yearly_id',
        'features',
        'limits',
        'featured',
        'popular',
        'white_label_enabled',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'sort_order' => 'integer',
            'enabled' => 'boolean',
            'monthly_price_cents' => 'integer',
            'yearly_price_cents' => 'integer',
            'trial_days' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'featured' => 'boolean',
            'popular' => 'boolean',
            'white_label_enabled' => 'boolean',
        ];
    }

    /**
     * Price in cents for the given billing cycle.
     */
    public function priceCentsForCycle(string $cycle): ?int
    {
        return match ($cycle) {
            'month' => $this->monthly_price_cents ?? $this->price_cents,
            'year' => $this->yearly_price_cents,
            default => null,
        };
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function hasFeature(string $feature): bool
    {
        return match ($feature) {
            'white_label' => $this->white_label_enabled,
            default => false,
        };
    }

    /**
     * Value from the plan's JSON limits column. Not named `limit` — that is the query builder.
     */
    public function limitValue(string $key): mixed
    {
        $limits = $this->limits;

        return is_array($limits) ? ($limits[$key] ?? null) : null;
    }

    public function clientSubscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
