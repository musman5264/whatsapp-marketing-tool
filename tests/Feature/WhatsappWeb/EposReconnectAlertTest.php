<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\External\HostResolver;
use App\Modules\WhatsappWeb\Jobs\NotifyEposReconnectJob;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\SessionProvisioner;
use App\Modules\WhatsappWeb\Services\WahaEventProcessor;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EposReconnectAlertTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private WhatsappWebSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function ips(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $this->ctx = $this->createWorkspaceContext();
        IntegrationConfig::create([
            'provider' => 'whatsapp_web', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test', 'api_key' => 'k'],
            'webhook_secret' => 'secret',
        ]);
        $this->session = app(SessionProvisioner::class)->ensure($this->ctx['workspace']->id);
    }

    private function link(array $caps): void
    {
        $i = new ExternalIntegration(['workspace_id' => $this->ctx['workspace']->id, 'type' => 'epos', 'name' => 'Shop',
            'base_url' => 'https://epos.example.com', 'manifest_url' => 'https://epos.example.com/m', 'key' => 'wak_x']);
        $i->manifest = ['capabilities' => $caps];
        $i->save();
    }

    private function statusEvent(string $status): void
    {
        app(WahaEventProcessor::class)->process(['event' => 'session.status', 'payload' => ['status' => $status]], $this->session);
    }

    #[Test]
    public function dropping_from_working_alerts_once_and_a_first_pairing_does_not(): void
    {
        Bus::fake();

        $this->statusEvent('SCAN_QR_CODE'); // first pairing: was 'pending'
        Bus::assertNotDispatched(NotifyEposReconnectJob::class);

        $this->session->update(['status' => 'active']);
        $this->statusEvent('FAILED');
        $this->statusEvent('SCAN_QR_CODE'); // QR refresh after the drop
        Bus::assertDispatchedTimes(NotifyEposReconnectJob::class, 1);
    }

    #[Test]
    public function every_drop_through_the_webhook_alerts_not_only_the_first_of_each_status(): void
    {
        Bus::fake([NotifyEposReconnectJob::class]); // the webhook's afterResponse closure must still run
        $this->session->update(['webhook_token' => 'tok-'.str_repeat('a', 44)]);
        $url = '/webhooks/whatsapp-web/'.$this->session->webhook_token;
        $post = fn (string $status) => $this->postJson($url, ['event' => 'session.status', 'session' => $this->session->session_name, 'payload' => ['status' => $status]])->assertOk();

        foreach ([1, 2] as $round) {
            $post('WORKING');
            $this->assertSame('active', $this->session->fresh()->status, "round {$round}: WORKING was ignored");
            $post('FAILED');
        }

        Bus::assertDispatchedTimes(NotifyEposReconnectJob::class, 2);
    }

    #[Test]
    public function job_posts_the_alert_to_epos_through_its_manifest_action(): void
    {
        $this->link([[
            'key' => NotifyEposReconnectJob::CAPABILITY, 'kind' => 'action', 'method' => 'POST', 'path' => '/api/whatsapp/v1/connection-alerts',
            'params' => [['name' => 'status', 'type' => 'text', 'from' => 'fixed'], ['name' => 'message', 'type' => 'text', 'from' => 'fixed', 'max_length' => 500]],
        ]]);
        Http::fake(['epos.example.com/*' => Http::response(['found' => true, 'ok' => true])]);

        (new NotifyEposReconnectJob($this->session->id, 'scan_qr'))->handle(app(\App\Modules\Integrations\Services\External\EposExecutor::class));

        Http::assertSent(fn ($r) => $r->url() === 'https://epos.example.com/api/whatsapp/v1/connection-alerts'
            && $r['status'] === 'scan_qr' && str_contains($r['message'], 'scan the QR'));
    }

    #[Test]
    public function job_does_nothing_when_epos_does_not_publish_the_action(): void
    {
        $this->link([]);
        Http::fake();

        (new NotifyEposReconnectJob($this->session->id, 'failed'))->handle(app(\App\Modules\Integrations\Services\External\EposExecutor::class));

        Http::assertNothingSent();
    }

    #[Test]
    public function qr_api_needs_the_whatsapp_connect_ability(): void
    {
        Http::fake(['waha.test/api/sessions/ws-*' => Http::response(['status' => 'STOPPED'], 200)]);
        $without = $this->ctx['user']->createToken('t', [ApiAbilities::INTEGRATIONS_WRITE])->plainTextToken;
        $with = $this->ctx['user']->createToken('t', [ApiAbilities::WHATSAPP_CONNECT])->plainTextToken;

        $this->withToken($without)->getJson('/api/v1/whatsapp-web/qr')->assertStatus(403);
        $this->app['auth']->forgetGuards(); // the guard caches the first token within one test
        $this->withToken($with)->getJson('/api/v1/whatsapp-web/qr')->assertOk()->assertJsonStructure(['qr', 'status']);
    }
}
