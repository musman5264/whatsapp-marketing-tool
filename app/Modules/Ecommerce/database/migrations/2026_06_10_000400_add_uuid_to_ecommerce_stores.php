<?php

use App\Modules\Ecommerce\Models\EcommerceStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_stores', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Backfill existing rows.
        EcommerceStore::whereNull('uuid')->get()->each(function (EcommerceStore $store) {
            $store->uuid = (string) Str::uuid();
            $store->saveQuietly();
        });

        Schema::table('ecommerce_stores', function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_stores', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
