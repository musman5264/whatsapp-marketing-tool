<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-express exchange_rate as "reference units per 1 unit of this currency", the
     * convention CurrencyService::convert() has always implemented.
     *
     * The seeded rates were entered the other way round ("units of this currency per
     * 1 USD" — BDT 110.0), so convert() divided where the data expected multiply and
     * $10.00 USD rendered as ৳0.09 instead of ৳1100.
     */
    public function up(): void
    {
        $this->invertRates();
    }

    public function down(): void
    {
        // The transform is its own inverse (r -> 1/r).
        $this->invertRates();
    }

    private function invertRates(): void
    {
        $rows = DB::table('currencies')
            ->where('exchange_rate', '>', 0)
            ->get(['code', 'exchange_rate']);

        foreach ($rows as $row) {
            $rate = (float) $row->exchange_rate;

            // The anchor currency (rate 1) is identical under both conventions.
            if ($rate === 1.0) {
                continue;
            }

            DB::table('currencies')
                ->where('code', $row->code)
                ->update(['exchange_rate' => round(1 / $rate, 8)]);
        }
    }
};
