<?php

namespace App\Modules\WhatsappWeb;

use App\Modules\WhatsappWeb\Console\WhatsappWebCommand;
use App\Modules\WhatsappWeb\Services\EngineManager;
use Illuminate\Support\ServiceProvider;

/**
 * WhatsApp Web (QR / linked-device) support.
 *
 * Attaches an ordinary personal WhatsApp number by QR scan, via an unofficial
 * engine (WAHA — https://waha.devlike.pro/). This is a new *provider*
 * (`whatsapp_web`) under the existing `whatsapp` channel — the existing
 * WhatsappDriver is extended with a provider branch rather than replaced, so
 * the inbox, automations and AI auto-replies work unchanged.
 *
 * The engine connection (base URL / API key / webhook secret) is configured
 * once per deployment by an admin in Admin → Integrations → WhatsApp Web
 * (IntegrationConfig provider slug `whatsapp_web`).
 */
class WhatsappWebServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EngineManager::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([WhatsappWebCommand::class]);
        }
    }
}
