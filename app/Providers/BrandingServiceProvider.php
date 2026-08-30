<?php

namespace App\Providers;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

/**
 * Applies the admin-configured brand name (and related general settings) to the
 * framework config at boot, so every existing `config('app.name')` /
 * `config('saas.*')` call site — mail templates, invoices, PDFs, webhook
 * user-agents, 2FA issuer, payment gateway brand names — resolves to the
 * white-label value without each one having to know about SystemSetting.
 *
 * Values are cached; SystemSetting::set() busts the cache on write.
 */
class BrandingServiceProvider extends ServiceProvider
{
    public const CACHE_KEY = 'branding.config';

    /**
     * The admin-configured branding values, or an empty array when the DB/cache is
     * unavailable. Unlike `config('saas.branding.*')`, an unset value stays null
     * here rather than falling back to the build default — callers that need to
     * tell "admin chose this" apart from "nobody set it" (the theme override in
     * app.blade.php) depend on that distinction.
     *
     * Reads the same cache boot() populates, so this costs no extra queries.
     */
    public static function cached(): array
    {
        try {
            return Cache::get(self::CACHE_KEY) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            try {
                $branding = Cache::rememberForever(self::CACHE_KEY, fn () => [
                    'app_name'      => SystemSetting::get('app_name'),
                    'app_tagline'   => SystemSetting::get('app_tagline'),
                    'support_email' => SystemSetting::get('support_email'),
                    'docs_url'      => SystemSetting::get('docs_url'),
                    'primary_color' => SystemSetting::get('primary_color'),
                    'secondary_color' => SystemSetting::get('secondary_color'),
                    'font_family' => SystemSetting::get('font_family'),
                ]);
            } catch (\Throwable) {
                // DB/cache not ready (first-run install, migrations) — keep .env defaults.
                return;
            }

            if (! empty($branding['app_name'])) {
                config([
                    'app.name'      => $branding['app_name'],
                    'saas.app_name' => $branding['app_name'],
                ]);

                // MAIL_FROM_NAME defaults to "${APP_NAME}" in .env, which Laravel
                // resolves at env-parse time — before this override. Re-apply it so
                // outgoing mail shows the white-label name rather than the build default.
                if (config('mail.from.name') === env('APP_NAME')) {
                    config(['mail.from.name' => $branding['app_name']]);
                }
            }

            if (! empty($branding['app_tagline'])) {
                config(['saas.tagline' => $branding['app_tagline']]);
            }

            if (! empty($branding['support_email'])) {
                config(['saas.support_email' => $branding['support_email']]);
            }

            if (! empty($branding['docs_url'])) {
                config(['saas.docs_url' => $branding['docs_url']]);
            }

            if (! empty($branding['primary_color'])) {
                config(['saas.branding.primary_color' => $branding['primary_color']]);
            }

            if (! empty($branding['secondary_color'])) {
                config(['saas.branding.secondary_color' => $branding['secondary_color']]);
            }

            if (! empty($branding['font_family'])) {
                config(['saas.branding.font_family' => $branding['font_family']]);
            }
        });
    }
}
