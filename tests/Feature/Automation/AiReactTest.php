<?php

namespace Tests\Feature\Automation;

use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
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

/**
 * Task 14 — the AI Reply node may answer with an emoji reaction instead of a
 * text message when the model returns {"action":"react","emoji":"X"}.
 */
class AiReactTest extends TestCase
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

    private function inbound(string $body = 'thank you!'): Message
    {
        return Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $body,
            'status' => 'delivered',
            'provider_message_id' => 'in_1',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    private function fakeLlm(string $content): void
    {
        $this->mock(LlmGateway::class, fn ($m) => $m->shouldReceive('chat')
            ->andReturn(new LlmResponse($content, 5, 5, 'test-model', 10)));
    }

    private function makeAutomation(): Automation
    {
        return Automation::create([
            'workspace_id' => $this->conversation->workspace_id,
            'name' => 'AI reply',
            'status' => 'active',
            'trigger_type' => 'message.received',
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => 'ai_reply', 'position' => ['x' => 0, 'y' => 100], 'data' => ['prompt' => 'be helpful']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1'],
            ],
        ]);
    }

    private function runWith(array $context): AutomationRun
    {
        $run = AutomationRun::create([
            'automation_id' => $this->makeAutomation()->id,
            'contact_id' => $this->conversation->contact_id,
            'status' => 'pending',
            'context' => $context,
            'started_at' => now(),
        ]);

        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        return $run;
    }

    #[Test]
    public function it_reacts_when_the_ai_returns_a_react_object(): void
    {
        $inbound = $this->inbound();
        $this->fakeLlm('{"action":"react","emoji":"🙏"}');
        Http::fake([
            'waha.test/api/reaction' => Http::response([], 200),
            'waha.test/*' => Http::response([], 200),
        ]);

        $this->runWith(['message_id' => $inbound->id, 'message_body' => 'thank you!']);

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/api/reaction')
            && $r['reaction'] === '🙏');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
    }

    #[Test]
    public function it_sends_plain_text_when_the_ai_returns_prose(): void
    {
        $inbound = $this->inbound();
        $this->fakeLlm("You're welcome!");
        Http::fake([
            'waha.test/api/sendText' => Http::response(['id' => 'out_1'], 200),
            'waha.test/*' => Http::response([], 200),
        ]);

        $this->runWith(['message_id' => $inbound->id, 'message_body' => 'thank you!']);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText')
            && $r['text'] === "You're welcome!");
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/reaction'));
    }

    #[Test]
    public function it_falls_back_to_text_when_there_is_no_trigger_message_to_react_to(): void
    {
        // react-form reply but no message_id in context → known limitation:
        // the raw JSON string is sent as a text message.
        $this->inbound();
        $this->fakeLlm('{"action":"react","emoji":"🙏"}');
        Http::fake([
            'waha.test/api/sendText' => Http::response(['id' => 'out_1'], 200),
            'waha.test/*' => Http::response([], 200),
        ]);

        $this->runWith(['message_body' => 'thank you!']);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/reaction'));
    }
}
