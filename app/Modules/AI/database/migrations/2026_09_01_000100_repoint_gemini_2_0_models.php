<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google retired the gemini-2.0-* family on v1beta (after 1.5-*). Repoint any
 * saved workspace config to the 2.5 equivalent. Runs after the earlier
 * 2026_08_31_000200 migration which had already moved 1.5-* → 2.0-*.
 */
return new class extends Migration
{
    private const MAP = [
        'gemini-2.0-flash' => 'gemini-2.5-flash',
        'gemini-2.0-flash-lite' => 'gemini-2.5-flash-lite',
        'gemini-2.0-flash-exp' => 'gemini-2.5-flash',
        'gemini-2.0-pro' => 'gemini-2.5-pro',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ai_provider_configs')) {
            return;
        }

        foreach (self::MAP as $old => $new) {
            DB::table('ai_provider_configs')
                ->where('provider', 'gemini')
                ->where('default_model_chat', $old)
                ->update(['default_model_chat' => $new]);
        }
    }

    public function down(): void
    {
        // no-op
    }
};
