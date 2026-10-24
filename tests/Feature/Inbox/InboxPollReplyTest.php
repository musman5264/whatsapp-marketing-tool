<?php

namespace Tests\Feature\Inbox;

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

/** T-0046: an agent sends a native poll from the inbox composer; votes are kept on the poll message. */
class InboxPollReplyTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
        IntegrationConfig::create([
            'provider' => 'whatsapp_web', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'],
        ]);
    }

    private function conversation(string $provider): Conversation
    {
        $ws = $this->ctx['workspace'];
        $account = ChannelAccount::create([
            'workspace_id' => $ws->id, 'channel' => 'whatsapp', 'provider' => $provider,
            'phone_number_id' => 'ws-'.$ws->id, 'display_name' => 'WA', 'status' => 'active',
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $ws->id, 'phone_e164' => '+15559998888']);

        return Conversation::create([
            'workspace_id' => $ws->id, 'contact_id' => $contact->id,
            'channel_account_id' => $account->id, 'status' => 'open',
        ]);
    }

    private function poll(array $override = []): array
    {
        return ['type' => 'poll', 'payload' => ['poll' => array_merge(
            ['question' => 'Tea or coffee?', 'options' => ['Tea', 'Coffee'], 'multiple' => true],
            $override,
        )]];
    }

    #[Test]
    public function an_agent_sends_a_native_poll_from_the_inbox(): void
    {
        $conv = $this->conversation('whatsapp_web');
        Http::fake(['waha.test/api/sendPoll' => Http::response(['id' => 'true_15559998888@c.us_3EB0POLL77'], 201)]);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.reply', $conv->uuid), $this->poll())
            ->assertOk()
            ->assertJsonPath('error', null)
            ->assertJsonPath('message.type', 'poll');

        $msg = Message::where('conversation_id', $conv->id)->first();
        $this->assertSame('Tea or coffee?', $msg->body);
        $this->assertSame('sent', $msg->status);
        $this->assertSame('true_15559998888@c.us_3EB0POLL77', $msg->provider_message_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendPoll')
            && $r['poll']['name'] === 'Tea or coffee?'
            && $r['poll']['options'] === ['Tea', 'Coffee']
            && $r['poll']['multipleAnswers'] === true);
    }

    #[Test]
    public function a_poll_needs_two_to_twelve_options(): void
    {
        $conv = $this->conversation('whatsapp_web');
        Http::fake();

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.reply', $conv->uuid), $this->poll(['options' => ['Only one']]))
            ->assertStatus(422);
        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.reply', $conv->uuid), $this->poll(['options' => range(1, 13)]))
            ->assertStatus(422);

        $this->assertSame(0, Message::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_cloud_api_number_cannot_send_a_poll(): void
    {
        $conv = $this->conversation('meta');
        Http::fake();

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.reply', $conv->uuid), $this->poll())
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'WhatsApp Web'));

        $this->assertSame(0, Message::count());
    }

    #[Test]
    public function votes_are_kept_on_the_poll_message_one_per_voter(): void
    {
        $conv = $this->conversation('whatsapp_web');
        Http::fake(['*' => Http::response([], 200)]);
        $session = WhatsappWebSession::create([
            'workspace_id' => $conv->workspace_id, 'session_name' => 'ws-'.$conv->workspace_id,
            'engine' => 'waha', 'status' => 'active', 'webhook_token' => Str::random(48),
        ]);
        $poll = Message::create([
            'conversation_id' => $conv->id, 'direction' => 'out', 'channel' => 'whatsapp', 'type' => 'poll',
            'body' => 'Tea or coffee?', 'status' => 'sent', 'provider_message_id' => '3EB0POLL77',
            'sent_by' => 'human', 'sent_at' => now(),
            'payload' => ['poll' => ['question' => 'Tea or coffee?', 'options' => ['Tea', 'Coffee'], 'multiple' => false]],
        ]);

        $vote = fn (string $from, array $opts) => [
            'event' => 'poll.vote', 'session' => $session->session_name,
            'payload' => [
                'vote' => ['id' => 'v-'.$from.implode('', $opts), 'from' => $from, 'to' => 'me@c.us', 'fromMe' => false, 'selectedOptions' => $opts],
                'poll' => ['id' => 'true_15559998888@c.us_3EB0POLL77', 'from' => 'me@c.us', 'to' => $from, 'fromMe' => true],
            ],
        ];

        $processor = app(WahaEventProcessor::class);
        $processor->process($vote('15559998888@c.us', ['Tea']), $session);
        $processor->process($vote('15559998888@c.us', ['Coffee']), $session); // changed their mind
        $processor->process($vote('15551112222@c.us', ['Coffee']), $session);

        $this->assertSame([
            '15559998888@c.us' => ['Coffee'],
            '15551112222@c.us' => ['Coffee'],
        ], $poll->fresh()->payload['votes']);
        // the poll definition is untouched
        $this->assertSame(['Tea', 'Coffee'], $poll->fresh()->payload['poll']['options']);
    }
}
