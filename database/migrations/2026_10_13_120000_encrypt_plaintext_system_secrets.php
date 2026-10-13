<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Secrets saved through SystemSetting::set() on their first save were stored as plain text
 * (the encrypt-on-write check ran before is_secret was set). Encrypt those rows so the saved
 * value reads back instead of coming back empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')
            ->where('is_secret', true)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        Crypt::decryptString($row->value);

                        continue; // already encrypted
                    } catch (Throwable) {
                        // plain text: encrypt it below
                    }

                    DB::table('system_settings')
                        ->where('id', $row->id)
                        ->update(['value' => Crypt::encryptString($row->value)]);
                }
            });
    }

    public function down(): void
    {
        // Not reversible on purpose: the encrypted values are kept.
    }
};
