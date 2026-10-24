<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_integrations', function (Blueprint $table) {
            $table->timestamp('debug_until')->nullable()->after('last_ping_at');
        });

        Schema::create('external_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('external_integrations')->cascadeOnDelete();
            $table->string('capability', 64);
            $table->string('outcome', 16);
            $table->string('status', 32);
            $table->unsignedInteger('ms')->default(0);
            $table->string('message', 255)->nullable();
            $table->text('request')->nullable();
            $table->text('response')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['integration_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_call_logs');
        Schema::table('external_integrations', function (Blueprint $table) {
            $table->dropColumn('debug_until');
        });
    }
};
