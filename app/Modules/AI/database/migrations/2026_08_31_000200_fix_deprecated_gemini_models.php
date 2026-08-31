<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google retired gemini-1.5-flash / gemini-1.5-pro / gemini-1.0-pro on the v1beta
 * API — saved configs pointing at them now 404. Repoint them at current models.
 */
return new class extends Migration
{
    private const MAP = [
        'gemini-1.5-flash' => 'gemini-2.0-flash',
        'gemini-1.5-flash-latest' => 'gemini-2.0-flash',
        'gemini-1.5-pro' => 'gemini-2.5-pro',
        'gemini-1.5-pro-latest' => 'gemini-2.5-pro',
        'gemini-1.0-pro' => 'gemini-2.0-flash',
        'gemini-pro' => 'gemini-2.0-flash',
    ];

    public function up(): void
    {
        if (Schema::hasTable('ai_provider_configs')) {
            foreach (self::MAP as $old => $new) {
                DB::table('ai_provider_configs')
                    ->where('provider', 'gemini')
                    ->where('default_model_chat', $old)
                    ->update(['default_model_chat' => $new]);
            }
        }
    }

    public function down(): void
    {
        // no-op — we don't want to reintroduce dead model names
    }
};
