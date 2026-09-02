<?php

namespace Tests\Feature\Automation;

use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Models\AutomationRunLog;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReactMessageNodeTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'],
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-'.$this->ctx['workspace']->id,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);

        $contact = Contact::factory()->create([
            'workspace_id' => $this->ctx['workspace']->id,
            'phone_e164' => '+15559998888',
        ]);

        $this->conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    private function inbound(): Message
    {
        return Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'the trigger message',
            'status' => 'delivered',
            'provider_message_id' => 'in_1',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    private function makeAutomation(): Automation
    {
        return Automation::create([
            'workspace_id' => $this->conversation->workspace_id,
            'name' => 'React to trigger',
            'status' => 'active',
            'trigger_type' => 'message.received',
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => 'react_message', 'position' => ['x' => 0, 'y' => 100], 'data' => ['emoji' => '👍']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1'],
            ],
        ]);
    }

    private function makeRun(Automation $automation, array $context): AutomationRun
    {
        return AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $this->conversation->contact_id,
            'status' => 'pending',
            'context' => $context,
            'started_at' => now(),
        ]);
    }

    #[Test]
    public function it_reacts_to_the_trigger_message(): void
    {
        $inbound = $this->inbound();
        Http::fake(['waha.test/api/reaction' => Http::response([], 200)]);

        $run = $this->makeRun($this->makeAutomation(), ['message_id' => $inbound->id]);

        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/api/reaction')
            && $r['messageId'] === 'in_1'
            && $r['reaction'] === '👍');

        $this->assertSame('ok', AutomationRunLog::where('run_id', $run->id)->where('node_id', 'n1')->value('result'));
    }

    #[Test]
    public function it_skips_when_there_is_no_trigger_message(): void
    {
        $this->inbound();
        Http::fake(['waha.test/*' => Http::response([], 200)]);

        $run = $this->makeRun($this->makeAutomation(), []);

        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/reaction'));

        $log = AutomationRunLog::where('run_id', $run->id)->where('node_id', 'n1')->first();
        $this->assertNotNull($log);
        $this->assertSame('skipped', $log->result);
    }
}
