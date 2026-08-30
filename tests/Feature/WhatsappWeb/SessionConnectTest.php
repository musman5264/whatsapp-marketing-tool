<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SessionConnectTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test', 'api_key' => 'k'],
            'webhook_secret' => 'secret',
        ]);
    }

    #[Test]
    public function connect_creates_session_and_channel_account(): void
    {
        Http::fake([
            'waha.test/api/sessions' => Http::response(['name' => 'ws-1', 'status' => 'STARTING'], 201),
            'waha.test/api/sessions/*/start' => Http::response([], 200),
            'waha.test/api/sessions/ws-*' => Http::response(['status' => 'SCAN_QR_CODE'], 200),
        ]);

        $res = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp-web.connect'));

        $res->assertOk();
        $this->assertDatabaseHas('whatsapp_web_sessions', [
            'workspace_id' => $this->ctx['workspace']->id,
            'session_name' => 'ws-'.$this->ctx['workspace']->id,
        ]);
        $this->assertDatabaseHas('channel_accounts', [
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-'.$this->ctx['workspace']->id,
        ]);
    }

    #[Test]
    public function qr_endpoint_returns_engine_qr_while_scanning(): void
    {
        // Minimal 1x1 PNG.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        Http::fake([
            'waha.test/api/sessions' => Http::response([], 201),
            'waha.test/api/sessions/*/start' => Http::response([], 200),
            'waha.test/api/sessions/ws-*/me' => Http::response([], 404),
            'waha.test/api/sessions/ws-*' => Http::response(['status' => 'SCAN_QR_CODE'], 200),
            'waha.test/api/*/auth/qr*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp-web.connect'))->assertOk();

        $res = $this->actingAs($this->ctx['user'])->getJson(route('client.whatsapp-web.qr'));
        $res->assertOk();
        $res->assertJsonPath('status', 'scan_qr');
        $this->assertStringStartsWith('data:image/png;base64,', $res->json('qr'));
    }

    #[Test]
    public function connect_is_blocked_when_engine_not_configured(): void
    {
        IntegrationConfig::where('provider', 'whatsapp_web')->update(['enabled' => false]);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.whatsapp-web.connect'))
            ->assertStatus(422);
    }
}
