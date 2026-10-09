<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_web_sessions', function (Blueprint $table) {
            $table->boolean('auto_label_enabled')->default(true)->after('send_receipts');
            $table->boolean('mirror_wa_labels')->default(true)->after('auto_label_enabled');
            $table->boolean('auto_save_contacts')->default(true)->after('mirror_wa_labels');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_web_sessions', function (Blueprint $table) {
            $table->dropColumn(['auto_label_enabled', 'mirror_wa_labels', 'auto_save_contacts']);
        });
    }
};
