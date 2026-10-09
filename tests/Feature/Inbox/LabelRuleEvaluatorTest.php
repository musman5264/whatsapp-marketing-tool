<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Listeners\ApplyInboxLabelRules;
use App\Modules\Inbox\Models\ConversationActivity;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Inbox\Models\InboxLabelRule;
use App\Modules\Inbox\Services\LabelRuleEvaluator;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Events\MessageReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LabelRuleEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private int $workspaceId;

    private Conversation $conversation;

    private InboxLabel $priceLabel;

    private InboxLabel $vipLabel;

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = $this->createWorkspaceContext();
        $this->workspaceId = $ctx['workspace']->id;

        $account = ChannelAccount::create([
            'workspace_id' => $this->workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'cloud',
            'phone_number_id' => '100200300',
            'display_name' => 'Business line',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'workspace_id' => $this->workspaceId,
            'phone_e164' => '+923001234567',
            'first_name' => 'Ayesha',
        ]);

        $this->conversation = Conversation::create([
            'workspace_id' => $this->workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $this->priceLabel = InboxLabel::create(['workspace_id' => $this->workspaceId, 'name' => 'Pricing', 'color' => '#22c55e']);
        $this->vipLabel = InboxLabel::create(['workspace_id' => $this->workspaceId, 'name' => 'VIP', 'color' => '#f97316']);
    }

    private function rule(array $attrs): InboxLabelRule
    {
        return InboxLabelRule::create(array_merge([
            'workspace_id' => $this->workspaceId,
            'inbox_label_id' => $this->priceLabel->id,
            'name' => 'Rule',
            'match_type' => InboxLabelRule::MATCH_KEYWORD_ANY,
            'pattern' => 'price',
            'is_active' => true,
            'priority' => 100,
            'hits' => 0,
        ], $attrs));
    }

    private function inbound(string $body, ?Conversation $conversation = null): Message
    {
        return Message::create([
            'conversation_id' => ($conversation ?? $this->conversation)->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $body,
            'status' => 'delivered',
            'sent_at' => now(),
        ]);
    }

    private function evaluate(Message $message): array
    {
        return app(LabelRuleEvaluator::class)->evaluate($message);
    }

    #[Test]
    public function arabic_keyword_attaches_the_label(): void
    {
        $this->rule(['pattern' => 'قیمت, کتنا', 'name' => 'Urdu price']);

        $attached = $this->evaluate($this->inbound('اس کی قیمت کیا ہے؟'));

        $this->assertCount(1, $attached);
        $this->assertTrue($this->conversation->labels()->whereKey($this->priceLabel->id)->exists());
    }

    #[Test]
    public function english_keyword_matching_is_case_insensitive(): void
    {
        $this->rule(['pattern' => 'Price']);

        $this->evaluate($this->inbound('what is the PRICE of this?'));

        $this->assertTrue($this->conversation->labels()->whereKey($this->priceLabel->id)->exists());
    }

    #[Test]
    public function keyword_all_requires_every_keyword(): void
    {
        $this->rule(['match_type' => InboxLabelRule::MATCH_KEYWORD_ALL, 'pattern' => 'refund, order']);

        $this->evaluate($this->inbound('I want a refund'));
        $this->assertFalse($this->conversation->labels()->exists());

        $this->evaluate($this->inbound('refund for my order please'));
        $this->assertTrue($this->conversation->labels()->whereKey($this->priceLabel->id)->exists());
    }

    #[Test]
    public function regex_rule_matches_unicode_text(): void
    {
        $this->rule(['match_type' => InboxLabelRule::MATCH_REGEX, 'pattern' => '/#\d{4,}/']);

        $this->evaluate($this->inbound('یہ آرڈر #12345 کے بارے میں ہے'));

        $this->assertTrue($this->conversation->labels()->whereKey($this->priceLabel->id)->exists());
    }

    #[Test]
    public function first_message_rule_fires_only_on_the_first_inbound(): void
    {
        $this->rule(['match_type' => InboxLabelRule::MATCH_FIRST_MESSAGE, 'pattern' => null]);

        $this->evaluate($this->inbound('hello'));
        $this->conversation->labels()->detach($this->priceLabel->id);
        $this->evaluate($this->inbound('hello again'));

        $this->assertFalse($this->conversation->labels()->exists());
    }

    #[Test]
    public function outbound_messages_are_ignored(): void
    {
        $this->rule(['pattern' => 'price']);

        $outbound = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'the price is 500',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->assertSame([], $this->evaluate($outbound));
        $this->assertFalse($this->conversation->labels()->exists());
    }

    #[Test]
    public function two_matching_rules_for_one_label_attach_it_once_and_log_once(): void
    {
        $this->rule(['name' => 'A', 'pattern' => 'price']);
        $this->rule(['name' => 'B', 'pattern' => 'cost']);

        $this->evaluate($this->inbound('what is the price and cost'));

        $this->assertSame(1, $this->conversation->labels()->count());
        $this->assertSame(
            1,
            ConversationActivity::where('conversation_id', $this->conversation->id)->where('type', 'label_added')->count()
        );
    }

    #[Test]
    public function a_label_already_on_the_conversation_is_not_attached_again(): void
    {
        $this->rule(['pattern' => 'price']);
        $this->conversation->labels()->attach($this->priceLabel->id);

        $attached = $this->evaluate($this->inbound('price?'));

        $this->assertSame([], $attached);
        $this->assertSame(1, $this->conversation->labels()->count());
    }

    #[Test]
    public function channel_scoped_rule_only_applies_to_its_channel(): void
    {
        $other = ChannelAccount::create([
            'workspace_id' => $this->workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'cloud',
            'phone_number_id' => '999',
            'display_name' => 'Other',
            'status' => 'active',
        ]);
        $this->rule(['channel_account_id' => $other->id]);

        $this->evaluate($this->inbound('price'));

        $this->assertFalse($this->conversation->labels()->exists());
    }

    #[Test]
    public function hits_are_incremented_for_each_matching_message(): void
    {
        $rule = $this->rule(['pattern' => 'price']);

        $this->evaluate($this->inbound('price one'));
        $this->evaluate($this->inbound('price two'));

        $this->assertSame(2, $rule->fresh()->hits);
    }

    #[Test]
    public function a_web_session_with_auto_labelling_off_is_skipped(): void
    {
        WhatsappWebSession::create([
            'workspace_id' => $this->workspaceId,
            'session_name' => 'ws-test',
            'engine' => 'waha',
            'status' => 'connected',
            'auto_label_enabled' => false,
        ]);
        ChannelAccount::where('workspace_id', $this->workspaceId)->update(['phone_number_id' => 'ws-test']);
        $this->rule(['pattern' => 'price']);

        $this->assertSame([], $this->evaluate($this->inbound('price')));
    }

    #[Test]
    public function the_listener_applies_rules_on_message_received(): void
    {
        $this->rule(['pattern' => 'price']);
        $message = $this->inbound('what is the price');

        (new ApplyInboxLabelRules(app(LabelRuleEvaluator::class)))->handle(new MessageReceived($message));

        $this->assertTrue($this->conversation->labels()->whereKey($this->priceLabel->id)->exists());
    }
}
