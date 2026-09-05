<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Array of message objects for multi-message campaigns
            $table->json('campaign_messages')->nullable()->after('payload_json');
            // Seconds to sleep between messages within one contact send (WAHA rate-limiting)
            $table->unsignedTinyInteger('message_delay_min')->default(5)->after('campaign_messages');
            $table->unsignedTinyInteger('message_delay_max')->default(8)->after('message_delay_min');
            // cloud_api = Meta Cloud API (templates), whatsapp_web = WAHA personal number
            $table->string('whatsapp_channel_type', 20)->nullable()->after('message_delay_max');
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->string('channel_type', 30)->nullable()->after('provider_message_id');
            $table->json('provider_response')->nullable()->after('channel_type');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['campaign_messages', 'message_delay_min', 'message_delay_max', 'whatsapp_channel_type']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropColumn(['channel_type', 'provider_response']);
        });
    }
};
