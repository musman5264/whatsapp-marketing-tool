<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /app/whatsapp-web/settings — the per-number call handling + read-receipt
 * toggles that drive Task 15 (call handling) and Tasks 19-20 (receipts).
 */
class SettingsUpdateTest extends TestCase
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
        ]);
    }

    #[Test]
    public function it_persists_the_call_and_receipt_settings(): void
    {
        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.whatsapp-web.settings'), [
                'auto_reject_calls' => true,
                'call_reject_message' => 'Please message us instead',
                'send_receipts' => false,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('settings.auto_reject_calls', true)
            ->assertJsonPath('settings.send_receipts', false);

        $session = WhatsappWebSession::where('session_name', 'ws-'.$this->ctx['workspace']->id)->first();
        $this->assertNotNull($session);
        $this->assertTrue($session->auto_reject_calls);
        $this->assertFalse($session->send_receipts);
        $this->assertSame('Please message us instead', $session->call_reject_message);
    }

    #[Test]
    public function it_accepts_a_partial_update_and_leaves_other_settings_alone(): void
    {
        $session = WhatsappWebSession::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'session_name' => 'ws-'.$this->ctx['workspace']->id,
            'webhook_token' => str_repeat('a', 48),
            'send_receipts' => false,
        ]);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.whatsapp-web.settings'), ['auto_reject_calls' => true])
            ->assertOk();

        $session->refresh();
        $this->assertTrue($session->auto_reject_calls);
        $this->assertFalse($session->send_receipts, 'send_receipts was not touched by the partial update');
    }

    #[Test]
    public function it_rejects_an_over_long_reject_message(): void
    {
        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.whatsapp-web.settings'), [
                'call_reject_message' => str_repeat('x', 1001),
            ])
            ->assertStatus(422);
    }
}
