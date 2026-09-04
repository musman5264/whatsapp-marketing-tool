<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PollSendTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'],
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel' => 'whatsapp', 'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-'.$ctx['workspace']->id,
            'display_name' => 'WA', 'status' => 'active',
        ]);
        $contact = Contact::factory()->create([
            'workspace_id' => $ctx['workspace']->id, 'phone_e164' => '+15551230000',
        ]);
        $this->conversation = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    #[Test]
    public function a_poll_message_is_sent_natively_via_waha(): void
    {
        Http::fake(['waha.test/api/sendPoll' => Http::response(['id' => 'poll_x'], 201)]);

        $msg = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out', 'channel' => 'whatsapp', 'type' => 'poll',
            'body' => 'Favourite colour?', 'status' => 'queued',
            'sent_by' => 'automation', 'sent_at' => now(),
            'payload' => ['poll' => ['question' => 'Favourite colour?', 'options' => ['Red', 'Blue'], 'multiple' => false]],
        ]);

        $id = app(WhatsappDriver::class)->send($msg);

        $this->assertSame('poll_x', $id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendPoll')
            && $r['poll']['name'] === 'Favourite colour?'
            && $r['poll']['options'] === ['Red', 'Blue']);
    }

    #[Test]
    public function the_send_poll_node_sends_a_native_poll_on_a_personal_number(): void
    {
        Http::fake([
            'waha.test/api/sendPoll' => Http::response(['id' => 'poll_e'], 201),
            'waha.test/*' => Http::response([], 200),
        ]);

        $automation = Automation::create([
            'workspace_id' => $this->conversation->workspace_id,
            'name' => 'Poll flow',
            'status' => 'active',
            'trigger_type' => 'contact.created',
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => 'send_poll', 'position' => ['x' => 0, 'y' => 100], 'data' => [
                    'question' => 'Tea or coffee?',
                    'options' => "Tea\nCoffee",
                    'result_var' => 'drink',
                ]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1'],
            ],
        ]);

        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $this->conversation->contact_id,
            'status' => 'pending',
            'context' => [],
            'started_at' => now(),
        ]);

        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendPoll'));
        $this->assertSame('drink', $run->fresh()->context['_poll_result_var']);
    }
}
