<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\WahaEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PollVoteInboundTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private WhatsappWebSession $session;

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
            'webhook_secret' => 'secret',
        ]);

        $name = 'ws-'.$this->ctx['workspace']->id;
        $this->session = WhatsappWebSession::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'session_name' => $name,
            'engine' => 'waha',
            'status' => 'active',
            'webhook_token' => Str::random(48),
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => $name,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);

        $contact = Contact::factory()->create([
            'workspace_id' => $this->ctx['workspace']->id,
            'phone_e164' => '+15551230000',
        ]);

        $this->conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    /**
     * A parked run + an outbound poll Message + an automation with
     * send_poll(n1) -> send_whatsapp(n2).
     */
    private function makeParkedRun(): AutomationRun
    {
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
                    'wait_for_vote' => true,
                ]],
                ['id' => 'n2', 'type' => 'send_whatsapp', 'position' => ['x' => 0, 'y' => 200], 'data' => [
                    'body' => 'Thanks, you picked {{drink}}',
                ]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1'],
                ['id' => 'e2', 'source' => 'n1', 'target' => 'n2'],
            ],
        ]);

        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $this->conversation->contact_id,
            'status' => 'waiting',
            'current_node_id' => 'n1',
            'resume_node_id' => 'n2',
            'context' => ['_poll_result_var' => 'drink', '_awaiting_poll' => true],
            'started_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'poll',
            'body' => 'Tea or coffee?',
            'status' => 'sent',
            'provider_message_id' => 'poll_abc',
            'sent_by' => 'automation',
            'sent_at' => now(),
        ]);

        return $run;
    }

    private function votePayload(array $override = []): array
    {
        return array_merge([
            'event' => 'poll.vote',
            'session' => $this->session->session_name,
            'payload' => [
                'id' => 'v1',
                'pollMessageId' => 'poll_abc',
                'from' => '15551230000@c.us',
                'vote' => ['selectedOptions' => ['Coffee']],
            ],
        ], $override);
    }

    #[Test]
    public function a_vote_writes_the_choice_into_context_and_resumes_a_parked_run(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $run = $this->makeParkedRun();

        app(WahaEventProcessor::class)->process($this->votePayload(), $this->session);

        $this->assertSame('Coffee', $run->fresh()->context['drink']);
        $this->assertNotSame('waiting', $run->fresh()->status);
        $this->assertArrayNotHasKey('_awaiting_poll', $run->fresh()->context);
    }

    #[Test]
    public function the_same_vote_delivered_twice_hits_the_engine_only_once(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $run = $this->makeParkedRun();

        // Spy the engine so we can prove the SECOND webhook is short-circuited by
        // the idempotency guard BEFORE it reaches applyPollVote — the real method
        // is naturally idempotent for identical input, so only the guard makes
        // "processed once" observable. partialMock keeps the real applyPollVote
        // running (so the run still resumes) while recording the calls.
        $spy = $this->partialMock(AutomationEngine::class);

        app(WahaEventProcessor::class)->process($this->votePayload(), $this->session);
        app(WahaEventProcessor::class)->process($this->votePayload(), $this->session);

        $spy->shouldHaveReceived('applyPollVote')->once();

        $this->assertSame('Coffee', $run->fresh()->context['drink']);
        $this->assertNotSame('waiting', $run->fresh()->status);
        $this->assertSame(1, AutomationRun::where('automation_id', $run->automation_id)->count());
    }

    #[Test]
    public function a_vote_with_no_matching_poll_message_is_a_no_op(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $run = $this->makeParkedRun();

        $payload = $this->votePayload();
        $payload['payload']['pollMessageId'] = 'nonexistent';

        app(WahaEventProcessor::class)->process($payload, $this->session);

        $this->assertSame('waiting', $run->fresh()->status);
        $this->assertArrayNotHasKey('drink', $run->fresh()->context);
    }
}
