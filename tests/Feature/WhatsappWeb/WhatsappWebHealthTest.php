<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\Waha\WahaAdapter;
use App\Modules\WhatsappWeb\Services\Waha\WahaClient;
use App\Modules\WhatsappWeb\Services\WhatsappWebHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappWebHealthTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(): WhatsappWebSession
    {
        $s = new WhatsappWebSession([
            'workspace_id' => 1, 'session_name' => 'ws-1', 'engine' => 'waha', 'status' => 'active',
        ]);
        $s->assignWebhookToken();
        $s->save();

        return $s->fresh();
    }

    private function health(): WhatsappWebHealth
    {
        return new WhatsappWebHealth(new WahaClient('http://waha.test', 'k'));
    }

    private function byKey(array $checks, string $key): array
    {
        return collect($checks)->firstWhere('key', $key);
    }

    public function test_webhook_url_mismatch_is_a_failure_and_names_both_urls(): void
    {
        $session = $this->makeSession();
        Http::fake(['waha.test/api/sessions/ws-1' => Http::response([
            'status' => 'WORKING',
            'config' => ['webhooks' => [['url' => 'https://old.example.com/webhooks/whatsapp-web/abc', 'events' => ['message']]]],
        ])]);

        $check = $this->byKey($this->health()->run($session), 'webhook');

        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('old.example.com', $check['detail']);
        // the secret token is masked in the output
        $this->assertStringNotContainsString($session->webhook_token, $check['detail']);
        $this->assertStringContainsString(substr($session->webhook_token, 0, 6).'…', $check['expected']);
    }

    public function test_webhook_matching_url_but_missing_events_is_a_warning(): void
    {
        $session = $this->makeSession();
        $url = route('webhooks.whatsapp-web.receive', ['token' => $session->webhook_token]);
        Http::fake(['waha.test/api/sessions/ws-1' => Http::response([
            'status' => 'WORKING',
            'config' => ['webhooks' => [['url' => $url, 'events' => ['message', 'message.ack']]]],
        ])]);

        $check = $this->byKey($this->health()->run($session), 'webhook');

        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('message.any', $check['detail']);
    }

    public function test_webhook_fully_registered_passes(): void
    {
        $session = $this->makeSession();
        $url = route('webhooks.whatsapp-web.receive', ['token' => $session->webhook_token]);
        Http::fake(['waha.test/api/sessions/ws-1' => Http::response([
            'status' => 'WORKING',
            'config' => ['webhooks' => [['url' => $url, 'events' => WahaAdapter::WEBHOOK_EVENTS]]],
        ])]);

        $this->assertSame('ok', $this->byKey($this->health()->run($session), 'webhook')['status']);
    }

    public function test_engine_unreachable_is_reported_not_thrown(): void
    {
        $session = $this->makeSession();
        Http::fake(['waha.test/*' => Http::response('boom', 500)]);

        $checks = $this->health()->run($session);

        $this->assertSame('fail', $this->byKey($checks, 'engine')['status']);
    }

    public function test_last_webhook_never_received_is_a_failure_then_ok_after_recording(): void
    {
        $session = $this->makeSession();
        Http::fake(['waha.test/*' => Http::response(['status' => 'WORKING', 'config' => ['webhooks' => []]])]);
        Cache::forget(WhatsappWebHealth::lastWebhookKey($session->session_name));

        $this->assertSame('fail', $this->byKey($this->health()->run($session), 'last_webhook')['status']);

        WhatsappWebHealth::recordWebhook($session->session_name, 'message.any');

        $this->assertSame('ok', $this->byKey($this->health()->run($session), 'last_webhook')['status']);
    }

    public function test_queue_check_flags_database_queue_when_workers_use_redis(): void
    {
        $session = $this->makeSession();
        Http::fake(['waha.test/*' => Http::response(['status' => 'WORKING', 'config' => ['webhooks' => []]])]);
        config(['queue.default' => 'database']);

        $check = $this->byKey($this->health()->run($session), 'queue');

        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('redis', $check['detail']);
    }
}
