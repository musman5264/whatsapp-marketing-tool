<?php

namespace Tests\Feature\WhatsappWeb;

use App\Events\MessageReceived;
use App\Events\MessageSent;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Jobs\SyncWhatsappWebHistoryJob;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\Waha\WahaAdapter;
use App\Modules\WhatsappWeb\Services\Waha\WahaClient;
use App\Modules\WhatsappWeb\Services\WahaEventProcessor;
use App\Modules\WhatsappWeb\Services\WahaHistorySync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-0002: phone-sent messages, ack matching, history backfill, webhook
 * (re)subscription and the "Sync now" endpoint for the WAHA engine.
 */
class WahaSyncCoreTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private WhatsappWebSession $session;

    private ChannelAccount $account;

    private string $sessionName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test', 'api_key' => 'k'],
            'webhook_secret' => 'secret',
        ]);

        $this->sessionName = 'ws-'.$this->ctx['workspace']->id;
        $this->session = WhatsappWebSession::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'session_name' => $this->sessionName,
            'engine' => 'waha',
            'status' => 'active',
            'webhook_token' => str_repeat('t', 48),
        ]);

        $this->account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => $this->sessionName,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);
    }

    private function processor(): WahaEventProcessor
    {
        return app(WahaEventProcessor::class);
    }

    /** A fromMe message as WAHA delivers it on message.any (sent from the phone). */
    private function phoneSent(array $overrides = []): array
    {
        $p = array_merge([
            'id' => 'true_15551234567@c.us_OWN1',
            'timestamp' => now()->timestamp,
            'from' => '15550000000@c.us',
            'to' => '15551234567@c.us',
            'fromMe' => true,
            'body' => 'Sent from the phone',
            'type' => 'chat',
            'ack' => 1,
            'source' => 'app',
        ], $overrides);

        return ['event' => 'message.any', 'session' => $this->sessionName, 'payload' => $p];
    }

    /** A message from the customer (fromMe=false). */
    private function customerMessage(array $overrides = []): array
    {
        $p = array_merge([
            'id' => 'false_15551234567@c.us_IN1',
            'timestamp' => now()->timestamp,
            'from' => '15551234567@c.us',
            'to' => '15550000000@c.us',
            'fromMe' => false,
            'body' => 'Hi there',
            'type' => 'chat',
            'notifyName' => 'Sam',
        ], $overrides);

        return ['event' => 'message.any', 'session' => $this->sessionName, 'payload' => $p];
    }

    private function contactAndConversation(int $unread = 0): Conversation
    {
        $contact = Contact::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'phone_e164' => '+15551234567',
            'first_name' => 'Sam',
            'opt_in_whatsapp' => true,
        ]);

        return Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $this->account->id,
            'status' => 'open',
            'unread_count' => $unread,
        ]);
    }

    // ── webhook subscription + endpoint ─────────────────────────────────────

    #[Test]
    public function start_session_updates_config_with_put_and_subscribes_to_message_any(): void
    {
        Http::fake(['waha.test/*' => Http::response(['status' => 'WORKING'], 200)]);

        (new WahaAdapter(new WahaClient('http://waha.test', 'k')))
            ->startSession($this->sessionName, 'https://app.test/webhooks/whatsapp-web/abc');

        Http::assertSent(function (HttpRequest $r) {
            if ($r->method() !== 'PUT' || ! str_ends_with($r->url(), '/api/sessions/'.$this->sessionName)) {
                return false;
            }
            $events = $r['config']['webhooks'][0]['events'] ?? [];

            return in_array('message.any', $events, true)
                && ! in_array('message', $events, true)
                && in_array('message.ack', $events, true)
                && $r['config']['webhooks'][0]['url'] === 'https://app.test/webhooks/whatsapp-web/abc';
        });
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/api/sessions/'.$this->sessionName));
    }

    #[Test]
    public function resubscribe_command_reregisters_webhook_without_logout(): void
    {
        Http::fake(['waha.test/*' => Http::response(['status' => 'WORKING'], 200)]);

        $this->artisan('whatsapp-web:resubscribe')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/api/sessions/'.$this->sessionName));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/logout')
            || $r->method() === 'DELETE');
    }

    #[Test]
    public function sync_endpoint_queues_the_backfill_job(): void
    {
        Queue::fake();

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.whatsapp-web.sync'))
            ->assertOk()
            ->assertJson(['queued' => true]);

        Queue::assertPushedOn('whatsapp', SyncWhatsappWebHistoryJob::class);
    }

    #[Test]
    public function sync_endpoint_refuses_when_number_is_not_connected(): void
    {
        Queue::fake();
        $this->session->update(['status' => 'disconnected']);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.whatsapp-web.sync'))
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ── phone-sent messages ─────────────────────────────────────────────────

    #[Test]
    public function phone_sent_message_is_stored_as_outbound_and_clears_unread(): void
    {
        Event::fake([MessageReceived::class, MessageSent::class]);

        $this->processor()->process($this->customerMessage(), $this->session);
        $this->assertSame(1, Conversation::first()->unread_count);

        $this->processor()->process($this->phoneSent(['ack' => 1]), $this->session);

        $this->assertDatabaseHas('messages', [
            'direction' => 'out',
            'provider_message_id' => 'true_15551234567@c.us_OWN1',
            'body' => 'Sent from the phone',
            'status' => 'sent',
            'sent_by' => 'human',
        ]);
        $conversation = Conversation::first();
        $this->assertSame(0, $conversation->unread_count);
        $this->assertNotNull($conversation->first_response_at);
        // The phone reply is not an inbound message: only the first one fired MessageReceived.
        Event::assertDispatchedTimes(MessageReceived::class, 1);
        Event::assertDispatched(MessageSent::class);
    }

    #[Test]
    public function api_sent_echo_is_skipped_because_the_send_path_already_stored_it(): void
    {
        $this->processor()->process($this->phoneSent(['source' => 'api', 'body' => 'From automation']), $this->session);

        $this->assertDatabaseMissing('messages', ['body' => 'From automation']);
    }

    #[Test]
    public function redelivered_phone_message_is_not_duplicated(): void
    {
        $payload = $this->phoneSent();

        $this->processor()->process($payload, $this->session);
        $this->processor()->process($payload, $this->session);

        $this->assertSame(1, Message::where('provider_message_id', 'true_15551234567@c.us_OWN1')->count());
    }

    #[Test]
    public function missing_source_echo_of_a_recent_own_send_is_skipped(): void
    {
        $conversation = $this->contactAndConversation();
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'Thanks for waiting',
            'status' => 'sent',
            'sent_by' => 'bot',
            'sent_at' => now(),
        ]);

        $payload = $this->phoneSent(['body' => 'Thanks for waiting']);
        unset($payload['payload']['source']);
        $this->processor()->process($payload, $this->session);

        $this->assertSame(1, Message::where('body', 'Thanks for waiting')->count());
    }

    #[Test]
    public function ack_matches_a_bare_stored_id_and_moves_the_tick_forward(): void
    {
        $conversation = $this->contactAndConversation();
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'Our send',
            'status' => 'sent',
            'provider_message_id' => '3EB0ABC123',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        $this->processor()->process([
            'event' => 'message.ack',
            'session' => $this->sessionName,
            'payload' => ['id' => 'true_15551234567@c.us_3EB0ABC123', 'ack' => 3, 'ackName' => 'READ'],
        ], $this->session);

        $this->assertDatabaseHas('messages', ['provider_message_id' => '3EB0ABC123', 'status' => 'read']);
    }

    #[Test]
    public function ack_that_arrives_before_the_message_is_picked_up_by_the_ingest(): void
    {
        $this->processor()->process([
            'event' => 'message.ack',
            'session' => $this->sessionName,
            'payload' => ['id' => ['_serialized' => 'true_15551234567@c.us_OWN2'], 'ack' => 2],
        ], $this->session);
        $this->assertDatabaseMissing('messages', ['provider_message_id' => 'true_15551234567@c.us_OWN2']);

        $this->processor()->process($this->phoneSent(['id' => 'true_15551234567@c.us_OWN2', 'ack' => 2]), $this->session);

        $this->assertDatabaseHas('messages', ['provider_message_id' => 'true_15551234567@c.us_OWN2', 'status' => 'delivered']);
    }

    // ── history backfill ────────────────────────────────────────────────────

    /** Fake WAHA: one chat overview row and its message history. */
    private function fakeHistory(array $messages, ?int $chatUnread = 0): void
    {
        Http::fake(function (HttpRequest $r) use ($messages, $chatUnread) {
            if (str_contains($r->url(), '/chats/overview')) {
                return Http::response([[
                    'id' => ['_serialized' => '15551234567@c.us'],
                    'name' => 'Sam',
                    '_chat' => ['unreadCount' => $chatUnread],
                ]], 200);
            }
            if (str_contains($r->url(), '/messages')) {
                return Http::response($messages, 200);
            }

            return Http::response([], 404);
        });
    }

    #[Test]
    public function backfill_ingests_missing_history_without_firing_automations_or_unread(): void
    {
        Event::fake([MessageReceived::class, MessageSent::class]);
        $conversation = $this->contactAndConversation(unread: 0);

        $old = now()->subDay()->timestamp;
        $this->fakeHistory([
            // newest first, like WAHA returns it
            ['id' => 'true_15551234567@c.us_OUT2', 'timestamp' => $old + 60, 'from' => '15550000000@c.us', 'to' => '15551234567@c.us', 'fromMe' => true, 'body' => 'Old reply', 'type' => 'chat', 'ack' => 3, 'source' => 'app'],
            ['id' => 'false_15551234567@c.us_IN2', 'timestamp' => $old, 'from' => '15551234567@c.us', 'fromMe' => false, 'body' => 'Old question', 'type' => 'chat'],
            // already stored by the webhook: must be skipped
            ['id' => 'false_15551234567@c.us_STORED', 'timestamp' => $old - 10, 'from' => '15551234567@c.us', 'fromMe' => false, 'body' => 'Stored', 'type' => 'chat'],
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'Stored',
            'status' => 'delivered',
            'provider_message_id' => 'false_15551234567@c.us_STORED',
            'sent_by' => 'human',
            'sent_at' => now()->subDay(),
        ]);

        $stats = app(WahaHistorySync::class)->run($this->session, 50, 30);

        $this->assertSame(2, $stats['ingested']);
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'false_15551234567@c.us_IN2', 'direction' => 'in']);
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'true_15551234567@c.us_OUT2', 'direction' => 'out', 'status' => 'read']);
        $this->assertSame(1, Message::where('provider_message_id', 'false_15551234567@c.us_STORED')->count());
        Event::assertNotDispatched(MessageReceived::class);
        $this->assertSame(0, $conversation->fresh()->unread_count);
    }

    #[Test]
    public function backfill_is_idempotent(): void
    {
        $this->contactAndConversation();
        $old = now()->subDay()->timestamp;
        $this->fakeHistory([
            ['id' => 'false_15551234567@c.us_IN3', 'timestamp' => $old, 'from' => '15551234567@c.us', 'fromMe' => false, 'body' => 'Once', 'type' => 'chat'],
        ]);

        app(WahaHistorySync::class)->run($this->session);
        app(WahaHistorySync::class)->run($this->session);

        $this->assertSame(1, Message::where('provider_message_id', 'false_15551234567@c.us_IN3')->count());
    }

    #[Test]
    public function backfill_clears_unread_when_the_engine_shows_the_chat_read(): void
    {
        Event::fake([MessageReceived::class, MessageSent::class]);
        $conversation = $this->contactAndConversation(unread: 4);
        $this->fakeHistory([], chatUnread: 0);

        $stats = app(WahaHistorySync::class)->run($this->session);

        $this->assertSame(1, $stats['unread_reset']);
        $this->assertSame(0, $conversation->fresh()->unread_count);
    }

    #[Test]
    public function backfill_keeps_unread_when_the_engine_reports_unread_messages(): void
    {
        $conversation = $this->contactAndConversation(unread: 4);
        $this->fakeHistory([], chatUnread: 2);

        app(WahaHistorySync::class)->run($this->session);

        $this->assertSame(4, $conversation->fresh()->unread_count);
    }

    #[Test]
    public function a_failing_chat_is_counted_and_does_not_abort_the_run(): void
    {
        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), '/chats/overview')) {
                return Http::response([
                    ['id' => '15551111111@c.us'],
                    ['id' => '15552222222@c.us'],
                ], 200);
            }
            if (str_contains($r->url(), '15551111111')) {
                return Http::response(['error' => 'boom'], 500);
            }

            return Http::response([[
                'id' => 'false_15552222222@c.us_OK1', 'timestamp' => now()->subDay()->timestamp,
                'from' => '15552222222@c.us', 'fromMe' => false, 'body' => 'Still works', 'type' => 'chat',
            ]], 200);
        });

        $stats = app(WahaHistorySync::class)->run($this->session);

        $this->assertSame(1, $stats['failed']);
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'false_15552222222@c.us_OK1']);
    }

    #[Test]
    public function fresh_inbound_during_backfill_still_triggers_the_normal_path(): void
    {
        Event::fake([MessageReceived::class, MessageSent::class]);
        // The engine does not report an unread count for this chat.
        $this->fakeHistory([
            ['id' => 'false_15551234567@c.us_NOW1', 'timestamp' => now()->timestamp, 'from' => '15551234567@c.us', 'fromMe' => false, 'body' => 'Just now', 'type' => 'chat'],
        ], chatUnread: null);

        app(WahaHistorySync::class)->run($this->session);

        Event::assertDispatched(MessageReceived::class);
        $this->assertSame(1, Conversation::first()->unread_count);
    }

    #[Test]
    public function backfill_does_not_clear_an_inbound_that_arrived_after_the_engine_snapshot(): void
    {
        $conversation = $this->contactAndConversation(unread: 1);
        $conversation->update(['last_inbound_at' => now()->addMinute()]);
        $this->fakeHistory([], chatUnread: 0);

        $stats = app(WahaHistorySync::class)->run($this->session);

        $this->assertSame(0, $stats['unread_reset']);
        $this->assertSame(1, $conversation->fresh()->unread_count);
    }

    #[Test]
    public function queued_job_runs_the_backfill_for_an_active_session(): void
    {
        $this->fakeHistory([]);

        SyncWhatsappWebHistoryJob::dispatchSync($this->session->id);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/chats/overview'));
    }
}
