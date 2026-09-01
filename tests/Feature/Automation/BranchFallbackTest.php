<?php

namespace Tests\Feature\Automation;

use App\Events\MessageReceived;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AI-generated / mis-wired flows often leave a single plain edge on a Condition
 * or Ask-Question node instead of true/false handles. The run must fall through
 * that edge, not silently dead-end (the "customer got no reply" bug).
 */
class BranchFallbackTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['automation.execute_inline' => true]);
        $this->ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web', 'label' => 'WA Web', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test', 'api_key' => 'k'],
        ]);
        WhatsappWebSession::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'session_name' => 'ws-'.$this->ctx['workspace']->id,
            'engine' => 'waha', 'status' => 'active', 'phone_e164' => '+10000000000',
            'webhook_token' => str_repeat('b', 48),
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp', 'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-'.$this->ctx['workspace']->id, 'display_name' => 'wa', 'status' => 'active',
        ]);
        $contact = Contact::factory()->create([
            'workspace_id' => $this->ctx['workspace']->id, 'phone_e164' => '+15550001111',
        ]);
        $this->conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
        Http::fake(['*' => Http::response(['id' => 'x'], 200)]);
    }

    private function inbound(string $body): Message
    {
        $m = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'in', 'channel' => 'whatsapp', 'type' => 'text',
            'body' => $body, 'status' => 'delivered', 'sent_by' => 'human', 'sent_at' => now(),
        ]);
        MessageReceived::dispatch($m);

        return $m;
    }

    #[Test]
    public function a_condition_with_only_a_plain_edge_falls_through_instead_of_dead_ending(): void
    {
        // trigger -> condition(false) -> [single plain edge] -> send_whatsapp
        $a = Automation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'plain-edge', 'status' => 'active',
            'trigger_type' => 'message.received', 'trigger_config' => ['keywords' => ['go']],
            'nodes' => [
                ['id' => 't', 'type' => 'triggerNode', 'data' => ['triggerType' => 'message.received']],
                ['id' => 'c', 'type' => 'condition', 'data' => ['nodeType' => 'condition', 'field' => 'context.nope', 'operator' => 'equals', 'value' => 'never']],
                ['id' => 's', 'type' => 'send_whatsapp', 'data' => ['nodeType' => 'send_whatsapp', 'body' => 'reached the send node']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't', 'target' => 'c'],
                ['id' => 'e2', 'source' => 'c', 'target' => 's'], // no sourceHandle
            ],
        ]);

        $this->inbound('go');

        $sent = Message::where('conversation_id', $this->conversation->id)
            ->where('direction', 'out')->where('body', 'reached the send node')->exists();
        $this->assertTrue($sent, 'the flow should fall through the plain edge and send');
        $this->assertSame('completed', AutomationRun::where('automation_id', $a->id)->value('status'));
    }

    #[Test]
    public function a_condition_with_proper_true_false_handles_still_branches_correctly(): void
    {
        $a = Automation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'branched', 'status' => 'active',
            'trigger_type' => 'message.received', 'trigger_config' => ['keywords' => ['go']],
            'nodes' => [
                ['id' => 't', 'type' => 'triggerNode', 'data' => ['triggerType' => 'message.received']],
                ['id' => 'c', 'type' => 'condition', 'data' => ['nodeType' => 'condition', 'field' => 'message.body', 'operator' => 'contains', 'value' => 'go']],
                ['id' => 'yes', 'type' => 'send_whatsapp', 'data' => ['nodeType' => 'send_whatsapp', 'body' => 'YES branch']],
                ['id' => 'no', 'type' => 'send_whatsapp', 'data' => ['nodeType' => 'send_whatsapp', 'body' => 'NO branch']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't', 'target' => 'c'],
                ['id' => 'e2', 'source' => 'c', 'target' => 'yes', 'sourceHandle' => 'true'],
                ['id' => 'e3', 'source' => 'c', 'target' => 'no', 'sourceHandle' => 'false'],
            ],
        ]);

        $this->inbound('go now');

        $this->assertTrue(Message::where('conversation_id', $this->conversation->id)->where('body', 'YES branch')->exists());
        $this->assertFalse(Message::where('conversation_id', $this->conversation->id)->where('body', 'NO branch')->exists());
    }

    #[Test]
    public function ask_question_resumes_on_the_next_message_even_with_stray_handles(): void
    {
        // ask_question node carries stray true/false handles (AI-generated) plus a
        // plain edge — the plain edge is the resume target.
        $a = Automation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'ask-resume', 'status' => 'active',
            'trigger_type' => 'message.received', 'trigger_config' => ['keywords' => ['hi']],
            'nodes' => [
                ['id' => 't', 'type' => 'triggerNode', 'data' => ['triggerType' => 'message.received']],
                ['id' => 'ask', 'type' => 'ask_question', 'data' => ['nodeType' => 'ask_question', 'channel' => 'whatsapp', 'question' => 'What do you need?', 'variable' => 'need']],
                ['id' => 'reply', 'type' => 'send_whatsapp', 'data' => ['nodeType' => 'send_whatsapp', 'body' => 'You said: {{need}}']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't', 'target' => 'ask'],
                ['id' => 'e2', 'source' => 'ask', 'target' => 'reply', 'sourceHandle' => 'true'],
                ['id' => 'e3', 'source' => 'ask', 'target' => 'reply'], // plain — the real path
            ],
        ]);

        $this->inbound('hi');
        $this->assertSame(1, AutomationRun::where('automation_id', $a->id)->count(), 'exactly one run per message');
        $run = AutomationRun::where('automation_id', $a->id)->latest('id')->first();
        $this->assertSame('waiting', $run->status, 'run parks after asking');
        $this->assertSame('reply', $run->resume_node_id);

        // customer answers
        $this->inbound('a refund');

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertTrue(
            Message::where('conversation_id', $this->conversation->id)->where('body', 'You said: a refund')->exists(),
            'the answer should be saved to {{need}} and used in the reply'
        );
    }
}
