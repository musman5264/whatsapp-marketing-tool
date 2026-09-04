<?php

namespace Tests\Feature\WhatsappWeb;

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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A contact reacts to a message we sent → WAHA delivers `message.reaction`.
 * The emoji is stored on the target message and a `reaction.received` trigger
 * fires so automations can respond.
 */
class ReactionInboundTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private WhatsappWebSession $session;

    private Conversation $conversation;

    private Message $outbound;

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

        // The message the contact reacts to — one we sent.
        $this->outbound = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'How did we do?',
            'status' => 'delivered',
            'provider_message_id' => 'orig_1',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    private function reactionAutomation(): Automation
    {
        return Automation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Reaction follow-up',
            'status' => 'active',
            'trigger_type' => 'reaction.received',
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => 'send_whatsapp', 'position' => ['x' => 0, 'y' => 100], 'data' => ['body' => 'thanks for the reaction!']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1'],
            ],
        ]);
    }

    private function reactionPayload(string $emoji = '❤️', string $targetId = 'orig_1', string $rxId = 'rx_1'): array
    {
        return [
            'event' => 'message.reaction',
            'session' => $this->session->session_name,
            'payload' => [
                'id' => $rxId,
                'reaction' => ['text' => $emoji, 'messageId' => $targetId],
                'from' => '15551230000@c.us',
            ],
        ];
    }

    #[Test]
    public function an_inbound_reaction_stores_the_emoji_and_fires_the_trigger(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $auto = $this->reactionAutomation();

        app(WahaEventProcessor::class)->process($this->reactionPayload(), $this->session);

        $this->assertSame('❤️', Message::find($this->outbound->id)->reaction_emoji);
        $this->assertSame(
            1,
            AutomationRun::where('automation_id', $auto->id)
                ->where('contact_id', $this->conversation->contact_id)
                ->count(),
        );
    }

    #[Test]
    public function the_same_reaction_delivered_twice_fires_the_trigger_once(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $auto = $this->reactionAutomation();

        app(WahaEventProcessor::class)->process($this->reactionPayload(), $this->session);
        app(WahaEventProcessor::class)->process($this->reactionPayload(), $this->session);

        $this->assertSame(1, AutomationRun::where('automation_id', $auto->id)->count());
    }

    #[Test]
    public function a_reaction_to_an_unknown_message_is_a_no_op(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $auto = $this->reactionAutomation();

        app(WahaEventProcessor::class)->process(
            $this->reactionPayload(targetId: 'nonexistent', rxId: 'rx_unknown'),
            $this->session,
        );

        $this->assertSame(0, AutomationRun::where('automation_id', $auto->id)->count());
        $this->assertNull(Message::find($this->outbound->id)->reaction_emoji);
    }
}
