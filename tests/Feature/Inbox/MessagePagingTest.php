<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MessagePagingTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    private Conversation $conversation;

    private int $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = $this->createWorkspaceContext();
        $this->user = $ctx['user'];
        $this->workspaceId = $ctx['workspace']->id;

        $account = ChannelAccount::create([
            'workspace_id' => $this->workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'waha',
            'display_name' => 'WA Web',
            'status' => 'active',
        ]);
        $this->conversation = Conversation::create([
            'workspace_id' => $this->workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $this->workspaceId])->id,
            'status' => 'open',
        ]);
    }

    /** Creates $count messages, one minute apart, #1 oldest. */
    private function thread(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Message::create([
                'conversation_id' => $this->conversation->id,
                'direction' => $i % 2 ? 'in' : 'out',
                'channel' => 'whatsapp',
                'type' => 'text',
                'body' => "message {$i}",
                'payload' => ['text' => ['body' => "message {$i}"]],
                'status' => 'delivered',
                'sent_at' => now()->startOfSecond()->subMinutes($count - $i),
            ]);
        }
    }

    #[Test]
    public function opening_a_chat_sends_only_the_newest_page(): void
    {
        $this->thread(120);

        $this->actingAs($this->user)
            ->get(route('client.inbox.show', $this->conversation->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('messages', 50)
                ->where('hasMoreMessages', true)
                ->where('messages.0.body', 'message 71')
                ->where('messages.49.body', 'message 120'));
    }

    #[Test]
    public function older_messages_come_in_pages_with_no_gaps_or_repeats(): void
    {
        $this->thread(120);
        $url = route('client.inbox.messages.index', $this->conversation->uuid);

        $cursor = Message::where('body', 'message 71')->firstOrFail();
        $first = $this->actingAs($this->user)->getJson($url.'?'.http_build_query([
            'before_sent_at' => $cursor->sent_at->toIso8601String(),
            'before_id' => $cursor->id,
        ]))->assertOk();

        $first->assertJsonCount(50, 'messages');
        $first->assertJsonPath('has_more', true);
        $this->assertSame('message 21', $first->json('messages.0.body'));
        $this->assertSame('message 70', $first->json('messages.49.body'));

        $cursor = Message::where('body', 'message 21')->firstOrFail();
        $second = $this->actingAs($this->user)->getJson($url.'?'.http_build_query([
            'before_sent_at' => $cursor->sent_at->toIso8601String(),
            'before_id' => $cursor->id,
        ]))->assertOk();

        $second->assertJsonCount(20, 'messages');
        $second->assertJsonPath('has_more', false);
        $this->assertSame('message 1', $second->json('messages.0.body'));
    }

    #[Test]
    public function messages_with_the_same_time_are_paged_by_id_without_repeats(): void
    {
        // 52 messages in the same second: the page boundary must fall back to id.
        $same = now()->startOfSecond()->subHour();
        $ids = [];
        for ($i = 1; $i <= 52; $i++) {
            $ids[] = Message::create([
                'conversation_id' => $this->conversation->id,
                'direction' => 'in',
                'channel' => 'whatsapp',
                'type' => 'text',
                'body' => "same {$i}",
                'payload' => ['text' => ['body' => "same {$i}"]],
                'status' => 'delivered',
                'sent_at' => $same,
            ])->id;
        }
        $url = route('client.inbox.messages.index', $this->conversation->uuid);

        $page = $this->actingAs($this->user)->getJson($url.'?'.http_build_query([
            'before_sent_at' => $same->toIso8601String(),
            'before_id' => $ids[2], // the 3rd message: older ids are 1 and 2
        ]))->assertOk();

        $page->assertJsonCount(2, 'messages');
        $page->assertJsonPath('has_more', false);
        $this->assertSame([$ids[0], $ids[1]], collect($page->json('messages'))->pluck('id')->all());
    }

    #[Test]
    public function another_workspaces_user_cannot_read_older_messages(): void
    {
        $this->thread(60);
        $other = $this->createWorkspaceContext()['user'];

        $this->actingAs($other)->getJson(route('client.inbox.messages.index', $this->conversation->uuid).'?'.http_build_query([
            'before_sent_at' => now()->toIso8601String(),
            'before_id' => 999999,
        ]))->assertForbidden();
    }
}
