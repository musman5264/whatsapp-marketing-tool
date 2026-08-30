<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_web_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('session_name', 64)->unique();          // ws-{workspaceId}
            $table->string('engine', 32)->default('waha');
            $table->string('phone_e164', 32)->nullable();          // filled once paired (from engine /me)
            $table->string('push_name', 128)->nullable();
            $table->enum('status', ['pending', 'scan_qr', 'connecting', 'active', 'failed', 'disconnected'])
                ->default('pending');
            $table->text('last_qr')->nullable();                   // transient data URI, cleared once paired
            $table->string('webhook_token', 64)->nullable();       // random; the {token} in the webhook URL
            $table->string('webhook_token_hash', 64)->nullable()->unique(); // sha256 for O(1) lookup
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_web_sessions');
    }
};
