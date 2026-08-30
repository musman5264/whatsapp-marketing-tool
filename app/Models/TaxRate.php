<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $fillable = [
        'name',
        'country',
        'region',
        'percentage',
        'inclusive',
        'enabled',
        'stripe_tax_rate_id',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
        'inclusive' => 'boolean',
        'enabled' => 'boolean',
    ];

    /**
     * Find the best matching tax rate for a country/region.
     */
    public static function findFor(string $country, ?string $region = null): ?self
    {
        $query = self::where('country', $country)->where('enabled', true);

        if ($region) {
            $regional = (clone $query)->where('region', $region)->first();
            if ($regional) {
                return $regional;
            }
        }

        return $query->whereNull('region')->first();
    }
}
