<?php

namespace Tests\Feature\Integrations;

use App\Models\ClientSetting;
use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Services\External\HostResolver;
use App\Modules\Integrations\Services\External\ReplyContextHandler;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\WahaEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * RentOyee's customer-sharing consent poll: the customer's vote is their text answer, sent to customer.update_info.
 * RentOyee is faked at the HTTP layer; the vote goes through the real WahaEventProcessor.
 */
class ReplyContextPollVoteTest extends TestCase
{
    use RefreshDatabase;

    private $workspace;

    private WhatsappWebSession $session;

    private Contact $contact;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->workspace = $this->createWorkspaceContext()['workspace'];
        $this->session = WhatsappWebSession::create([
            'workspace_id' => $this->workspace->id, 'session_name' => 'ws-'.$this->workspace->id, 'engine' => 'waha',
            'status' => 'active', 'webhook_token' => Str::random(48),
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'provider' => 'whatsapp_web',
            'phone_number_id' => $this->session->session_name, 'display_name' => 'WhatsApp (personal)', 'status' => 'active',
        ]);
        $this->contact = Contact::factory()->create(['workspace_id' => $this->workspace->id, 'phone_e164' => '+15551230000']);
        $conversation = Conversation::create([
            'workspace_id' => $this->workspace->id, 'contact_id' => $this->contact->id, 'channel_account_id' => $account->id, 'status' => 'open',
        ]);
        Message::create([
            'conversation_id' => $conversation->id, 'direction' => 'out', 'channel' => 'whatsapp', 'type' => 'poll',
            'body' => 'Allow Acme Rentals?', 'status' => 'sent', 'provider_message_id' => 'poll_share', 'sent_by' => 'api', 'sent_at' => now(),
        ]);

        $this->sent = [];
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andReturnUsing(function (Message $m) {
            $this->sent[] = $m->body;

            return 'out.'.count($this->sent);
        });
        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function ips(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        $i = new ExternalIntegration([
            'workspace_id' => $this->workspace->id, 'type' => 'rentoyee', 'name' => 'Acme',
            'base_url' => 'https://rent.example.com', 'manifest_url' => 'https://rent.example.com/api/whatsapp/v1/manifest',
            'key' => 'wak_live_secret',
        ]);
        $i->manifest_version = 'v1';
        $i->manifest = ['business' => [], 'capabilities' => [[
            'key' => 'customer.update_info', 'kind' => 'action', 'label' => ['en' => 'Update info'], 'method' => 'POST',
            'path' => '/api/whatsapp/v1/customer-info',
            'params' => [
                ['name' => 'phone', 'type' => 'phone', 'from' => 'sender_phone'],
                ['name' => 'request_id', 'type' => 'text'],
                ['name' => 'value', 'type' => 'text', 'max_length' => 500],
            ],
        ]]];
        $i->save();

        // Per-client switch (T-0071): off unless the client turned RentOyee on.
        ClientSetting::set((int) $this->workspace->client_id, ExternalIntegration::switchKey('rentoyee'), '1');
        $this->askShareQuestion();
    }

    private function askShareQuestion(): void
    {
        Cache::put(ReplyContextHandler::key($this->contact->id), [
            'source' => 'rentoyee', 'request_id' => 'share-7', 'field' => 'sharing', 'expires_at' => now()->addDay()->toIso8601String(),
        ], now()->addDay());
    }

    private function fakeRentOyee($response): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => $response]);
    }

    private function vote(array $options = ['Yes, allow'], string $voteId = 'vote1'): void
    {
        app(WahaEventProcessor::class)->process([
            'event' => 'poll.vote',
            'session' => $this->session->session_name,
            'payload' => [
                'vote' => ['id' => $voteId, 'from' => '15551230000@c.us', 'fromMe' => false, 'selectedOptions' => $options],
                'poll' => ['id' => 'poll_share', 'from' => 'me@c.us', 'fromMe' => true],
            ],
        ], $this->session);
    }

    public function test_a_vote_is_sent_as_the_text_answer_and_the_reply_goes_to_the_chat(): void
    {
        $this->fakeRentOyee(Http::response(['found' => true, 'ok' => true, 'status' => 'applied', 'reply_text' => 'Thank you, Acme can now use your profile.']));

        $this->vote(['Yes, allow']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://rent.example.com/api/whatsapp/v1/customer-info'
            && $r['phone'] === '+15551230000'
            && $r['request_id'] === 'share-7'
            && $r['value'] === 'Yes, allow'
            && $r->hasHeader('Idempotency-Key'));
        $this->assertSame(['Thank you, Acme can now use your profile.'], $this->sent);
        $this->assertNull(Cache::get(ReplyContextHandler::key($this->contact->id)), 'applied closes the question');
    }

    public function test_an_invalid_answer_keeps_the_question_open(): void
    {
        $this->fakeRentOyee(Http::response(['found' => true, 'ok' => false, 'status' => 'invalid', 'reason' => 'invalid_value', 'reply_text' => 'Please answer YES to allow or NO to refuse.']));

        $this->vote(['No, do not allow']);

        $this->assertSame(['Please answer YES to allow or NO to refuse.'], $this->sent);
        $this->assertNotNull(Cache::get(ReplyContextHandler::key($this->contact->id)));
    }

    public function test_a_closed_request_closes_the_question(): void
    {
        $this->fakeRentOyee(Http::response(['found' => false, 'reason' => 'request_closed']));

        $this->vote();

        $this->assertSame(['That request is closed.'], $this->sent);
        $this->assertNull(Cache::get(ReplyContextHandler::key($this->contact->id)));
    }

    public function test_the_same_vote_delivered_twice_calls_rentoyee_once(): void
    {
        $this->fakeRentOyee(Http::response(['found' => true, 'ok' => false, 'status' => 'invalid', 'reply_text' => 'Again?']));

        $this->vote(['Yes, allow'], 'vote-dup');
        $this->vote(['Yes, allow'], 'vote-dup');

        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_no_question_open_or_no_single_choice_sends_nothing(): void
    {
        $this->fakeRentOyee(Http::response(['found' => true, 'ok' => true, 'status' => 'applied', 'reply_text' => 'x']));

        $this->vote(['Yes, allow', 'No, do not allow'], 'v-multi');
        $this->vote([], 'v-none');
        Cache::forget(ReplyContextHandler::key($this->contact->id));
        $this->vote(['Yes, allow'], 'v-closed');

        $this->assertSame(0, Http::recorded()->count());
        $this->assertSame([], $this->sent);
    }
}
