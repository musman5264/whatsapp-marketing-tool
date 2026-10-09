<?php

namespace Tests\Feature\Realtime;

use App\Http\Middleware\SecureHeaders;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RealtimeCspTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'broadcasting.default' => 'log',
            'broadcasting.connections.pusher.key' => '',
            'broadcasting.connections.pusher.options.cluster' => 'mt1',
            'broadcasting.connections.reverb.key' => '',
        ]);
    }

    private function connectSrc(): string
    {
        $response = (new SecureHeaders)->handle(Request::create('/landing'), fn () => response('ok'));
        $csp = (string) $response->headers->get('Content-Security-Policy');

        foreach (explode('; ', $csp) as $directive) {
            if (str_starts_with($directive, 'connect-src ')) {
                return $directive;
            }
        }

        $this->fail('connect-src directive missing from CSP: '.$csp);
    }

    public function test_csp_allows_pusher_websocket_when_frontend_is_enabled_from_env(): void
    {
        config(['broadcasting.connections.pusher.key' => 'envkey', 'broadcasting.connections.pusher.options.cluster' => 'ap2']);

        $connect = $this->connectSrc();

        $this->assertStringContainsString('wss://ws-ap2.pusher.com', $connect);
        $this->assertStringContainsString('wss://sockjs-ap2.pusher.com', $connect);
    }

    public function test_csp_allows_pusher_websocket_when_frontend_is_enabled_from_admin_settings(): void
    {
        SystemSetting::set('pusher_app_key', 'dbkey');
        SystemSetting::set('pusher_app_cluster', 'ap2');

        $this->assertStringContainsString('wss://ws-ap2.pusher.com', $this->connectSrc());
    }

    public function test_csp_omits_pusher_hosts_when_realtime_is_disabled(): void
    {
        $this->assertStringNotContainsString('pusher.com', $this->connectSrc());
    }
}
