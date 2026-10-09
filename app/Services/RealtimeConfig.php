<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for live-update (realtime) configuration.
 *
 * Three consumers must agree on the same answer:
 *  - the server broadcaster (config('broadcasting.default') + connection creds),
 *  - the frontend Echo client (pusherPublicConfig shared to Inertia),
 *  - the Content-Security-Policy connect-src (SecureHeaders).
 *
 * Credentials can come from the admin panel (SystemSetting) or from .env. The
 * admin values win only when the server-side set is complete (key + secret +
 * app_id), mirroring PusherSettingsServiceProvider. The frontend key/cluster
 * fall back to the env values when the admin value is blank.
 */
final class RealtimeConfig
{
    /** Frontend transport: 'reverb' (self-hosted), 'pusher' (hosted), or 'none'. */
    public readonly string $driver;

    /** Whether the frontend Echo client should connect. */
    public readonly bool $enabled;

    /** Whether the server has everything needed to actually broadcast events. */
    public readonly bool $canBroadcast;

    /** Effective server broadcaster after applying admin overrides ('pusher', 'reverb', 'log', 'null', ...). */
    public readonly string $broadcaster;

    public function __construct(
        string $driver,
        public readonly string $key,
        public readonly string $cluster,
        bool $enabled,
        bool $canBroadcast,
        string $broadcaster,
        public readonly string $wsHost = '',
        public readonly int $wsPort = 443,
        public readonly bool $forceTLS = true,
    ) {
        $this->driver = $driver;
        $this->enabled = $enabled;
        $this->canBroadcast = $canBroadcast;
        $this->broadcaster = $broadcaster;
    }

    /**
     * Resolve the effective realtime config from DB settings and config().
     * Reads config() (not env()) so it is safe under `config:cache`.
     */
    public static function resolve(): self
    {
        $adminPusher = self::adminPusherCredentials();
        $broadcaster = $adminPusher !== null ? 'pusher' : (string) config('broadcasting.default', 'null');

        // Frontend: admin key wins over env key, same for cluster.
        $dbKey = self::setting('pusher_app_key');
        $key = $dbKey ?: (string) config('broadcasting.connections.pusher.key', '');
        $cluster = self::setting('pusher_app_cluster')
            ?: (string) config('broadcasting.connections.pusher.options.cluster', '')
            ?: 'mt1';
        $dbFlag = self::setting('pusher_enabled');

        // Explicit admin "off" wins; otherwise a non-empty key means enabled.
        $pusherEnabled = $dbFlag === 'false' ? false : $key !== '';

        if ($broadcaster === 'reverb') {
            $reverbKey = (string) config('broadcasting.connections.reverb.key', '');

            return new self(
                driver: 'reverb',
                key: $reverbKey,
                cluster: '',
                enabled: $reverbKey !== '',
                canBroadcast: $reverbKey !== ''
                    && filled(config('broadcasting.connections.reverb.secret'))
                    && filled(config('broadcasting.connections.reverb.app_id')),
                broadcaster: $broadcaster,
                wsHost: (string) config('broadcasting.connections.reverb.options.host', ''),
                wsPort: (int) config('broadcasting.connections.reverb.options.port', 443),
                forceTLS: config('broadcasting.connections.reverb.options.scheme', 'https') === 'https',
            );
        }

        // Server creds: admin set (when complete) or the configured pusher connection.
        $serverKey = $adminPusher['key'] ?? (string) config('broadcasting.connections.pusher.key', '');
        $serverSecret = $adminPusher['secret'] ?? config('broadcasting.connections.pusher.secret');
        $serverAppId = $adminPusher['app_id'] ?? config('broadcasting.connections.pusher.app_id');
        $canBroadcast = in_array($broadcaster, ['pusher', 'reverb'], true)
            && filled($serverKey) && filled($serverSecret) && filled($serverAppId);

        return new self(
            driver: $pusherEnabled ? 'pusher' : 'none',
            key: $key,
            cluster: $cluster,
            enabled: $pusherEnabled,
            canBroadcast: $canBroadcast,
            broadcaster: $broadcaster,
        );
    }

    /**
     * Admin-panel Pusher credentials, only when the server-side set is complete.
     * Returns null otherwise. Shared with PusherSettingsServiceProvider.
     *
     * @return array{key: string, secret: string, app_id: string, cluster: string}|null
     */
    public static function adminPusherCredentials(): ?array
    {
        $key = self::setting('pusher_app_key');
        $secret = self::setting('pusher_app_secret');
        $appId = self::setting('pusher_app_id');

        if ($key === '' || $secret === '' || $appId === '') {
            return null;
        }

        return [
            'key' => $key,
            'secret' => $secret,
            'app_id' => $appId,
            'cluster' => self::setting('pusher_app_cluster') ?: 'mt1',
        ];
    }

    /**
     * Shape consumed by resources/js/echo.js via the Inertia `pusher` prop.
     *
     * @return array{key: string, cluster: string, enabled: bool, wsHost?: string, wsPort?: int, forceTLS?: bool}
     */
    public function toFrontendArray(): array
    {
        if ($this->driver === 'reverb') {
            return [
                'key' => $this->key,
                'cluster' => '',
                'enabled' => $this->enabled,
                'wsHost' => $this->wsHost,
                'wsPort' => $this->wsPort,
                'forceTLS' => $this->forceTLS,
            ];
        }

        return [
            'key' => $this->key,
            'cluster' => $this->cluster,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * Websocket/XHR origins the frontend may open (for CSP connect-src).
     * Empty when the frontend will not connect.
     *
     * @return list<string>  origins such as "wss://ws-ap2.pusher.com"
     */
    public function connectOrigins(): array
    {
        if (! $this->enabled) {
            return [];
        }

        if ($this->driver === 'reverb') {
            if ($this->wsHost === '') {
                return [];
            }
            $port = $this->wsPort;
            $defaultPort = $this->forceTLS ? 443 : 80;
            $hostPort = $port && $port !== $defaultPort ? "{$this->wsHost}:{$port}" : $this->wsHost;

            return $this->forceTLS
                ? ["wss://{$hostPort}", "https://{$hostPort}"]
                : ["ws://{$hostPort}", "http://{$hostPort}"];
        }

        if ($this->driver === 'pusher' && $this->cluster !== '') {
            $origins = [];
            foreach (["ws-{$this->cluster}.pusher.com", "sockjs-{$this->cluster}.pusher.com"] as $host) {
                $origins[] = "wss://{$host}";
                $origins[] = "https://{$host}";
            }

            return $origins;
        }

        return [];
    }

    /**
     * Log a warning (at most once per hour) when the frontend will connect but the
     * server cannot broadcast, so events silently never arrive.
     */
    public function warnIfMisconfigured(): void
    {
        if (! $this->enabled || $this->canBroadcast) {
            return;
        }

        try {
            if (! Cache::add('realtime.misconfigured.warned', true, 3600)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        Log::warning(
            'Realtime: the frontend will connect (key present) but the server cannot broadcast. '.
            'Set BROADCAST_CONNECTION=pusher (or reverb) and complete PUSHER_APP_ID/PUSHER_APP_KEY/PUSHER_APP_SECRET '
            .'(or the admin Pusher settings), then run php artisan config:clear.',
            ['broadcaster' => $this->broadcaster, 'driver' => $this->driver]
        );
    }

    private static function setting(string $key): string
    {
        try {
            return trim((string) (SystemSetting::get($key) ?? ''));
        } catch (\Throwable) {
            // DB not ready (install/migrations) — fall back to env/config only.
            return '';
        }
    }
}
