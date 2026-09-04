<?php

namespace Tests\Feature\WhatsappWeb;

use App\Events\CallReceived;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\WahaEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inbound `call.*` webhook events for a personal number:
 *  - always logged into the caller's conversation as a "📞 ..." line
 *  - `call.received` + `auto_reject_calls` → adapter reject + optional auto-reply
 *  - `call.received` dispatches `CallReceived` → `call.received` automation trigger
 */
class CallHandlingTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private WhatsappWebSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        config(['automation.execute_inline' => true]);
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

        ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => $name,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);
    }

    private function fireCall(string $event = 'call.received', array $extra = []): void
    {
        app(WahaEventProcessor::class)->process([
            'event' => $event,
            'session' => $this->session->session_name,
            'payload' => array_merge(['id' => 'call_1', 'from' => '15551230000@c.us', 'isVideo' => false], $extra),
        ], $this->session);
    }

    private function callAutomation(): Automation
    {
        return Automation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Missed call follow-up',
            'status' => 'active',
            'trigger_type' => 'call.received',
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => 'send_whatsapp', 'position' => ['x' => 0, 'y' => 100], 'data' => ['body' => 'Sorry we missed your call!']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1'],
            ],
        ]);
    }

    private function conversationFor(string $phone = '+15551230000'): Conversation
    {
        $contact = Contact::where('workspace_id', $this->ctx['workspace']->id)
            ->where('phone_e164', $phone)->firstOrFail();

        return Conversation::where('workspace_id', $this->ctx['workspace']->id)
            ->where('contact_id', $contact->id)->firstOrFail();
    }

    #[Test]
    public function call_received_logs_a_missed_call_line_and_does_not_reject_by_default(): void
    {
        Http::fake(['waha.test/*' => Http::response([], 200)]);

        $this->fireCall();

        $conversation = $this->conversationFor();
        $this->assertSame(
            1,
            Message::where('conversation_id', $conversation->id)
                ->where('body', 'like', '📞 Missed call%')
                ->where('direction', 'in')
                ->count(),
        );

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/calls/reject'));
    }

    #[Test]
    public function call_received_dispatches_the_call_received_event(): void
    {
        Http::fake(['waha.test/*' => Http::response([], 200)]);
        Event::fake([CallReceived::class]);

        $this->fireCall();

        Event::assertDispatched(CallReceived::class, function (CallReceived $e) {
            return $e->callId === 'call_1'
                && $e->callType === 'audio'
                && $e->callerPhone === '+15551230000'
                && $e->workspaceId === $this->ctx['workspace']->id;
        });
    }

    #[Test]
    public function auto_reject_toggle_rejects_the_call_and_sends_the_reply_text(): void
    {
        Http::fake(['waha.test/*' => Http::response([], 200)]);

        $this->session->update([
            'auto_reject_calls' => true,
            'call_reject_message' => 'Please message us instead',
        ]);

        $this->fireCall();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/reject') && $r['callId'] === 'call_1');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && $r['text'] === 'Please message us instead');
    }

    #[Test]
    public function the_same_call_event_delivered_twice_logs_one_line(): void
    {
        Http::fake(['waha.test/*' => Http::response([], 200)]);

        $this->fireCall();
        $this->fireCall();

        $conversation = $this->conversationFor();
        $this->assertSame(
            1,
            Message::where('conversation_id', $conversation->id)
                ->where('body', 'like', '📞 Missed call%')
                ->count(),
        );
    }

    #[Test]
    public function call_received_fires_the_call_received_automation_trigger(): void
    {
        Http::fake(['waha.test/*' => Http::response([], 200)]);
        $auto = $this->callAutomation();

        $this->fireCall();

        $run = AutomationRun::where('automation_id', $auto->id)->first();
        $this->assertNotNull($run, 'a call.received automation run was created');
        $this->assertSame('call_1', $run->context['call_id']);
        $this->assertSame('audio', $run->context['call_type']);
        $this->assertSame('+15551230000', $run->context['caller_phone']);
    }

    #[Test]
    public function call_rejected_logs_a_line_but_does_not_reject_or_dispatch(): void
    {
        Http::fake(['waha.test/*' => Http::response([], 200)]);
        Event::fake([CallReceived::class]);

        $this->fireCall('call.rejected');

        $conversation = $this->conversationFor();
        $this->assertSame(
            1,
            Message::where('conversation_id', $conversation->id)
                ->where('body', 'like', '📞 Call rejected%')
                ->count(),
        );

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/calls/reject'));
        Event::assertNotDispatched(CallReceived::class);
    }
}
