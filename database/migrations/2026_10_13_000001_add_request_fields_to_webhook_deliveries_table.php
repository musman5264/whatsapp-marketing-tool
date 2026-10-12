<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the exact body and headers each delivery was sent with, so the Deliveries page can show them.
 * The stored `payload` is the caller's data only; the body sent also carries event and timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->longText('request_body')->nullable()->after('payload');
            $table->json('request_headers')->nullable()->after('request_body');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn(['request_body', 'request_headers']);
        });
    }
};
