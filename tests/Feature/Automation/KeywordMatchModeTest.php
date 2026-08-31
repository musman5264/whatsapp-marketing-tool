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

class KeywordMatchModeTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private Contact $contact;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['automation.execute_inline' => true]);
        $this->ctx = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp', 'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-x', 'display_name' => 'wa', 'status' => 'active',
        ]);
        $this->contact = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id]);
        $this->conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $this->contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    private function automation(array $triggerConfig): Automation
    {
        return Automation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'kw test',
            'status' => 'active',
            'trigger_type' => 'message.received',
            'trigger_config' => $triggerConfig,
            'nodes' => [
                ['id' => 't', 'type' => 'triggerNode', 'data' => ['triggerType' => 'message.received']],
                ['id' => 'end', 'type' => 'tag', 'data' => ['nodeType' => 'tag', 'tag' => 'x']],
            ],
            'edges' => [['id' => 'e', 'source' => 't', 'target' => 'end']],
        ]);
    }

    private function inbound(string $body): void
    {
        $m = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'in', 'channel' => 'whatsapp', 'type' => 'text',
            'body' => $body, 'status' => 'delivered', 'sent_by' => 'human', 'sent_at' => now(),
        ]);
        MessageReceived::dispatch($m);
    }

    private function ranFor(int $automationId): bool
    {
        return AutomationRun::where('automation_id', $automationId)->exists();
    }

    #[Test]
    public function contains_is_the_default(): void
    {
        $a = $this->automation(['keywords' => ['price']]);
        $this->inbound('what is the PRICE please');
        $this->assertTrue($this->ranFor($a->id));
    }

    #[Test]
    public function equals_requires_the_whole_message(): void
    {
        $a = $this->automation(['keywords' => ['hi'], 'match_mode' => 'equals']);

        $this->inbound('hi there');
        $this->assertFalse($this->ranFor($a->id));

        $this->inbound('  Hi  ');
        $this->assertTrue($this->ranFor($a->id));
    }

    #[Test]
    public function starts_with(): void
    {
        $a = $this->automation(['keywords' => ['order '], 'match_mode' => 'starts_with']);

        $this->inbound('please order 3 items');
        $this->assertFalse($this->ranFor($a->id));

        $this->inbound('Order 3 items');
        $this->assertTrue($this->ranFor($a->id));
    }

    #[Test]
    public function regex(): void
    {
        $a = $this->automation(['keywords' => ['\\b(buy|purchase)\\b'], 'match_mode' => 'regex']);

        $this->inbound('I want to purchase this');
        $this->assertTrue($this->ranFor($a->id));
    }

    #[Test]
    public function a_broken_regex_never_matches_and_does_not_error(): void
    {
        $a = $this->automation(['keywords' => ['('], 'match_mode' => 'regex']);
        $this->inbound('anything at all');
        $this->assertFalse($this->ranFor($a->id));
    }
}
