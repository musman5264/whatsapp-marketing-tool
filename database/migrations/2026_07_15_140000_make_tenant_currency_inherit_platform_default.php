<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant currency columns become "null = inherit the platform default currency".
     *
     * Previously these were seeded with a hardcoded 'USD'/'$' at signup, which sat
     * ahead of Currency::defaultCode() in the resolution chain and shadowed it — so
     * changing the platform default currency in the admin panel had no visible effect.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('base_currency', 10)->nullable()->default(null)->change();
            $table->string('currency_symbol', 16)->nullable()->default(null)->change();
            $table->string('currency_position', 32)->nullable()->default(null)->change();
        });

        // Every existing row holds the hardcoded signup default, not a deliberate
        // choice: the platform default was USD for the whole life of these rows, so
        // no tenant can have meaningfully opted into it. Null them so they inherit.
        DB::table('clients')
            ->where('base_currency', 'USD')
            ->where('currency_symbol', '$')
            ->update([
                'base_currency' => null,
                'currency_symbol' => null,
                'currency_position' => null,
            ]);

        DB::table('workspaces')
            ->where('currency_code', 'USD')
            ->update(['currency_code' => null]);
    }

    public function down(): void
    {
        DB::table('clients')->whereNull('base_currency')->update([
            'base_currency' => 'USD',
            'currency_symbol' => '$',
            'currency_position' => 'before',
        ]);

        DB::table('workspaces')->whereNull('currency_code')->update(['currency_code' => 'USD']);

        Schema::table('clients', function (Blueprint $table) {
            $table->string('base_currency', 10)->default('USD')->nullable(false)->change();
            $table->string('currency_symbol', 16)->default('$')->nullable(false)->change();
            $table->string('currency_position', 32)->default('before')->nullable(false)->change();
        });
    }
};
