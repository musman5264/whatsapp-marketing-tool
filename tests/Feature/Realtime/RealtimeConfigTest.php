<?php

namespace Tests\Feature\Realtime;

use App\Models\SystemSetting;
use App\Services\RealtimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RealtimeConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Start from a clean env-only state; each test opts in to what it needs.
        config([
            'broadcasting.default' => 'log',
            'broadcasting.connections.pusher.key' => '',
            'broadcasting.connections.pusher.secret' => '',
            'broadcasting.connections.pusher.app_id' => '',
            'broadcasting.connections.pusher.options.cluster' => 'mt1',
            'broadcasting.connections.reverb.key' => '',
            'broadcasting.connections.reverb.secret' => '',
            'broadcasting.connections.reverb.app_id' => '',
        ]);
    }

    public function test_env_pusher_key_enables_frontend_on_the_configured_cluster(): void
    {
        config([
            'broadcasting.connections.pusher.key' => 'envkey',
            'broadcasting.connections.pusher.options.cluster' => 'ap2',
        ]);

        $rt = RealtimeConfig::resolve();

        $this->assertTrue($rt->enabled);
        $this->assertSame('pusher', $rt->driver);
        $this->assertSame(['key' => 'envkey', 'cluster' => 'ap2', 'enabled' => true], $rt->toFrontendArray());
        $this->assertContains('wss://ws-ap2.pusher.com', $rt->connectOrigins());
        $this->assertContains('wss://sockjs-ap2.pusher.com', $rt->connectOrigins());
        $this->assertContains('https://sockjs-ap2.pusher.com', $rt->connectOrigins());
    }

    public function test_frontend_connects_but_server_cannot_broadcast_without_secret_and_app_id(): void
    {
        config(['broadcasting.connections.pusher.key' => 'envkey']);

        $rt = RealtimeConfig::resolve();

        $this->assertTrue($rt->enabled);
        $this->assertFalse($rt->canBroadcast);
        $this->assertSame('log', $rt->broadcaster);
    }

    public function test_full_server_credentials_make_the_server_broadcast_over_pusher(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'envkey',
            'broadcasting.connections.pusher.secret' => 'envsecret',
            'broadcasting.connections.pusher.app_id' => '123',
        ]);

        $rt = RealtimeConfig::resolve();

        $this->assertTrue($rt->canBroadcast);
        $this->assertSame('pusher', $rt->broadcaster);
    }

    public function test_admin_settings_with_full_credentials_override_broadcaster_and_cluster(): void
    {
        SystemSetting::set('pusher_app_key', 'dbkey');
        SystemSetting::set('pusher_app_secret', 'dbsecret');
        SystemSetting::set('pusher_app_id', '999');
        SystemSetting::set('pusher_app_cluster', 'eu');

        $rt = RealtimeConfig::resolve();

        $this->assertTrue($rt->canBroadcast);
        $this->assertSame('pusher', $rt->broadcaster);
        $this->assertSame('dbkey', $rt->toFrontendArray()['key']);
        $this->assertSame('eu', $rt->toFrontendArray()['cluster']);
        $this->assertContains('wss://ws-eu.pusher.com', $rt->connectOrigins());
    }

    public function test_partial_admin_key_is_used_by_frontend_but_server_cannot_broadcast(): void
    {
        SystemSetting::set('pusher_app_key', 'dbkey');
        SystemSetting::set('pusher_app_cluster', 'ap2');

        $rt = RealtimeConfig::resolve();

        $this->assertTrue($rt->enabled);
        $this->assertFalse($rt->canBroadcast);
        $this->assertSame('dbkey', $rt->key);
        $this->assertContains('wss://ws-ap2.pusher.com', $rt->connectOrigins());
    }

    public function test_admin_can_switch_pusher_off(): void
    {
        config(['broadcasting.connections.pusher.key' => 'envkey']);
        SystemSetting::set('pusher_enabled', 'false');

        $rt = RealtimeConfig::resolve();

        $this->assertFalse($rt->enabled);
        $this->assertSame('none', $rt->driver);
        $this->assertSame([], $rt->connectOrigins());
    }

    public function test_reverb_exposes_its_host_and_port_to_the_frontend_and_csp(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'rkey',
            'broadcasting.connections.reverb.secret' => 'rsecret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => 'ws.example.test',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        $rt = RealtimeConfig::resolve();

        $this->assertTrue($rt->canBroadcast);
        $this->assertSame([
            'key' => 'rkey',
            'cluster' => '',
            'enabled' => true,
            'wsHost' => 'ws.example.test',
            'wsPort' => 8080,
            'forceTLS' => false,
        ], $rt->toFrontendArray());
        $this->assertSame(['ws://ws.example.test:8080', 'http://ws.example.test:8080'], $rt->connectOrigins());
    }

    public function test_misconfiguration_warning_is_logged_at_most_once_per_hour(): void
    {
        config(['broadcasting.connections.pusher.key' => 'envkey']);
        Log::spy();

        $rt = RealtimeConfig::resolve();
        $rt->warnIfMisconfigured();
        $rt->warnIfMisconfigured();

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_no_warning_when_server_can_broadcast(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'envkey',
            'broadcasting.connections.pusher.secret' => 'envsecret',
            'broadcasting.connections.pusher.app_id' => '123',
        ]);
        Log::spy();

        RealtimeConfig::resolve()->warnIfMisconfigured();

        Log::shouldNotHaveReceived('warning');
    }
}
