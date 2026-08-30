<?php

namespace Database\Seeders;

use App\Models\PaymentGatewayConfig;
use Illuminate\Database\Seeder;

class PaymentGatewayConfigSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['stripe', 'paypal', 'paddle'] as $gateway) {
            PaymentGatewayConfig::firstOrCreate(
                ['gateway' => $gateway],
                [
                    'test_mode' => true,
                    'enabled' => false,
                    'credentials' => [
                        'test' => ['publishable_key' => '', 'secret_key' => '', 'webhook_secret' => ''],
                        'live' => ['publishable_key' => '', 'secret_key' => '', 'webhook_secret' => ''],
                    ],
                ]
            );
        }
    }
}
