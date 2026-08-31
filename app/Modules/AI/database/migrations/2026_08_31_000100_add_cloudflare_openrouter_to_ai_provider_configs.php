<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_provider_configs')) {
            return;
        }

        // MySQL enum widen — no data change. Other drivers store enums as strings,
        // so nothing to do there.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ai_provider_configs MODIFY provider ENUM('openai','anthropic','gemini','cloudflare','openrouter') NOT NULL");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_provider_configs')) {
            return;
        }

        DB::table('ai_provider_configs')->whereIn('provider', ['cloudflare', 'openrouter'])->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ai_provider_configs MODIFY provider ENUM('openai','anthropic','gemini') NOT NULL");
        }
    }
};
