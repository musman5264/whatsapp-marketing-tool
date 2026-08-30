<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marketing copy used to be seeded with the vendor brand name baked in as a
 * literal, so a white-label install that renamed the app still rendered
 * "WhatsMine" across the landing pages. Rewrite those stored values to the
 * `:brand` token, which LandingPageController::brandify() resolves on read.
 *
 * Safe for installs that kept the original name: `:brand` falls back to
 * config('app.name'), which is still "WhatsMine" unless an admin changed it.
 */
return new class extends Migration
{
    private const LEGACY_BRAND = 'WhatsMine';

    public function up(): void
    {
        $rows = DB::table('system_settings')
            ->where('key', 'like', 'landing.%')
            ->where('is_secret', false)
            ->where('value', 'like', '%'.self::LEGACY_BRAND.'%')
            ->get(['id', 'value']);

        foreach ($rows as $row) {
            DB::table('system_settings')
                ->where('id', $row->id)
                ->update(['value' => str_replace(self::LEGACY_BRAND, ':brand', (string) $row->value)]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('system_settings')
            ->where('key', 'like', 'landing.%')
            ->where('is_secret', false)
            ->where('value', 'like', '%:brand%')
            ->get(['id', 'value']);

        foreach ($rows as $row) {
            DB::table('system_settings')
                ->where('id', $row->id)
                ->update(['value' => str_replace(':brand', self::LEGACY_BRAND, (string) $row->value)]);
        }
    }
};
