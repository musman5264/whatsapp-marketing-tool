<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGatewayConfig extends Model
{
    protected $fillable = ['gateway', 'test_mode', 'enabled', 'credentials'];

    protected function casts(): array
    {
        return [
            'test_mode' => 'boolean',
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
        ];
    }

    /**
     * Get credentials for the current mode (test or live).
     */
    public function getActiveCredentials(): array
    {
        $creds = $this->credentials ?? [];
        $mode = $this->test_mode ? 'test' : 'live';

        return $creds[$mode] ?? [];
    }

    /**
     * Check if this gateway has valid credentials for the active mode.
     */
    public function hasActiveCredentials(): bool
    {
        $c = $this->getActiveCredentials();

        return ! empty($c['secret_key'] ?? null);
    }

    public static function getByGateway(string $gateway): ?self
    {
        return static::where('gateway', $gateway)->first();
    }
}
