<?php

namespace App\Providers;

use App\Services\RealtimeConfig;
use Illuminate\Support\ServiceProvider;

class PusherSettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function () {
            try {
                // Same rule RealtimeConfig uses for the frontend and CSP: admin
                // Pusher credentials apply only when key + secret + app_id are all set.
                $creds = RealtimeConfig::adminPusherCredentials();

                if ($creds !== null) {
                    config([
                        'broadcasting.default' => 'pusher',
                        'broadcasting.connections.pusher.key' => $creds['key'],
                        'broadcasting.connections.pusher.secret' => $creds['secret'],
                        'broadcasting.connections.pusher.app_id' => $creds['app_id'],
                        'broadcasting.connections.pusher.options.cluster' => $creds['cluster'],
                        'broadcasting.connections.pusher.options.host' => 'api-'.$creds['cluster'].'.pusher.com',
                    ]);
                }
            } catch (\Throwable) {
                // DB not ready during migrations — skip silently
            }
        });
    }
}
