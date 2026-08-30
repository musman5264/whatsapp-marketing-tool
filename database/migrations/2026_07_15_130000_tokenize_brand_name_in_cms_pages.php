<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CMS pages (privacy, terms, …) were seeded with the vendor brand name resolved
 * at seed time, so a white-label install that renamed the app kept serving
 * "WhatsMine" in its legal copy. Rewrite them to the `:brand` token, which
 * CmsPageController resolves on render (see App\Support\Brand).
 *
 * Mirrors the landing-content migration. A no-op on installs that never seeded
 * CMS pages, and safe for installs that kept the original name — `:brand`
 * resolves back to config('app.name'), still "WhatsMine" unless an admin renames.
 */
return new class extends Migration
{
    private const LEGACY_BRAND = 'WhatsMine';

    private const COLUMNS = ['title', 'content', 'meta_title', 'meta_description'];

    public function up(): void
    {
        $this->swap(self::LEGACY_BRAND, ':brand');
    }

    public function down(): void
    {
        $this->swap(':brand', self::LEGACY_BRAND);
    }

    private function swap(string $from, string $to): void
    {
        if (! Schema::hasTable('cms_pages')) {
            return;
        }

        $columns = array_values(array_filter(
            self::COLUMNS,
            fn ($c) => Schema::hasColumn('cms_pages', $c)
        ));

        if ($columns === []) {
            return;
        }

        DB::table('cms_pages')
            ->select(array_merge(['id'], $columns))
            ->orderBy('id')
            ->chunk(100, function ($rows) use ($columns, $from, $to) {
                foreach ($rows as $row) {
                    $update = [];
                    foreach ($columns as $column) {
                        $value = (string) ($row->{$column} ?? '');
                        if ($value !== '' && str_contains($value, $from)) {
                            $update[$column] = str_replace($from, $to, $value);
                        }
                    }

                    if ($update !== []) {
                        DB::table('cms_pages')->where('id', $row->id)->update($update);
                    }
                }
            });
    }
};
