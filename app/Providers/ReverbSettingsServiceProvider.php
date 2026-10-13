<?php

namespace App\Providers;

use App\Services\RealtimeConfig;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Applies the super-admin Reverb settings (saved in the admin panel) to the runtime config.
 * Runs on every request and in the Reverb server process, so both use the same app keys
 * and endpoint without editing .env on the server.
 */
class ReverbSettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function () {
            try {
                self::apply();
            } catch (Throwable) {
                // DB not ready during migrations — skip silently
            }
        });
    }

    /** Copy saved Reverb settings into the broadcasting and Reverb server config. */
    public static function apply(): void
    {
        $creds = RealtimeConfig::adminReverbCredentials();
        if ($creds === null) {
            return;
        }

        $tls = $creds['scheme'] === 'https';
        $options = ['host' => $creds['host'], 'port' => $creds['port'], 'scheme' => $creds['scheme'], 'useTLS' => $tls];

        config([
            'broadcasting.connections.reverb.key' => $creds['key'],
            'broadcasting.connections.reverb.secret' => $creds['secret'],
            'broadcasting.connections.reverb.app_id' => $creds['app_id'],
            'broadcasting.connections.reverb.options' => $options,
            // The Reverb server only accepts the app it has in its config.
            'reverb.apps.apps.0' => array_merge(config('reverb.apps.apps.0', []), [
                'key' => $creds['key'],
                'secret' => $creds['secret'],
                'app_id' => $creds['app_id'],
                'options' => $options,
            ]),
        ]);

        if (RealtimeConfig::reverbActive()) {
            config(['broadcasting.default' => 'reverb']);
        }
    }
}
