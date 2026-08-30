<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_products', function (Blueprint $table) {
            // Stamped on every upsert, so a completed sync can drop the rows the
            // store no longer returns (deleted or unpublished since the last run).
            $table->timestamp('last_seen_at')->nullable()->after('raw');
            $table->index(['store_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
