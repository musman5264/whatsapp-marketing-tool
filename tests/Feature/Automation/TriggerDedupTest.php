<?php

namespace Tests\Feature\Automation;

use App\Events\MessageReceived;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The WhatsApp Web engine re-delivers webhooks and the cron drain can re-queue a
 * failed run, so MessageReceived can reach the trigger listener multiple times
 * for one Message. Each automation must still fire at most once per message.
 */
class TriggerDedupTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['automation.execute_inline' => true]);
        $this->ctx = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp', 'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-1', 'display_name' => 'wa', 'status' => 'active',
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id]);
        $this->conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    private function automation(): Automation
    {
        return Automation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'welcome', 'status' => 'active',
            'trigger_type' => 'message.received',
            'trigger_config' => ['keywords' => ['hi']],
            'nodes' => [
                ['id' => 't', 'type' => 'triggerNode', 'data' => ['triggerType' => 'message.received']],
                ['id' => 'end', 'type' => 'tag', 'data' => ['nodeType' => 'tag', 'tag' => 'x']],
            ],
            'edges' => [['id' => 'e', 'source' => 't', 'target' => 'end']],
        ]);
    }

    #[Test]
    public function the_same_message_event_fired_repeatedly_creates_one_run(): void
    {
        $a = $this->automation();

        $m = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'in', 'channel' => 'whatsapp', 'type' => 'text',
            'body' => 'hi there', 'status' => 'delivered', 'sent_by' => 'human', 'sent_at' => now(),
        ]);

        // Simulate WAHA re-delivering the webhook 4 times.
        MessageReceived::dispatch($m);
        MessageReceived::dispatch($m);
        MessageReceived::dispatch($m->fresh());
        MessageReceived::dispatch(Message::find($m->id));

        $this->assertSame(1, AutomationRun::where('automation_id', $a->id)->count());
    }

    #[Test]
    public function two_different_messages_each_get_their_own_run(): void
    {
        $a = $this->automation();

        foreach (['hi one', 'hi two'] as $body) {
            $m = Message::create([
                'conversation_id' => $this->conversation->id,
                'direction' => 'in', 'channel' => 'whatsapp', 'type' => 'text',
                'body' => $body, 'status' => 'delivered', 'sent_by' => 'human', 'sent_at' => now(),
            ]);
            MessageReceived::dispatch($m);
            MessageReceived::dispatch($m); // duplicate delivery
        }

        $this->assertSame(2, AutomationRun::where('automation_id', $a->id)->count());
    }
}
