<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_web_sessions', function (Blueprint $table) {
            $table->boolean('auto_reject_calls')->default(false)->after('meta_json');
            $table->text('call_reject_message')->nullable()->after('auto_reject_calls');
            $table->boolean('send_receipts')->default(true)->after('call_reject_message');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_web_sessions', function (Blueprint $table) {
            $table->dropColumn(['auto_reject_calls', 'call_reject_message', 'send_receipts']);
        });
    }
};
