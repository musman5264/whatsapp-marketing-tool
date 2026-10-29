<?php

namespace Tests\Feature\Integrations;

use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Services\External\HostResolver;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * A rider's WhatsApp answer goes to EPOS (delivery.rider_reply) before anything else. EPOS is faked at the HTTP layer;
 * the inbound message goes through the real WhatsappDriver, so "the normal flow did not run" is checked on real
 * automation runs.
 */
class RiderReplyTest extends TestCase
{
    use RefreshDatabase;

    private $workspace;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['automation.execute_inline' => true]);

        $this->workspace = $this->createWorkspaceContext()['workspace'];
        ChannelAccount::create([
            'workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'provider' => 'whatsapp',
            'status' => 'active', 'display_name' => 'Shop', 'phone_number_id' => '1234567890',
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

        $this->link();
        $this->customerFlow();
    }

    private function link(?array $capabilities = null): ExternalIntegration
    {
        $i = new ExternalIntegration([
            'workspace_id' => $this->workspace->id, 'type' => 'epos', 'name' => 'Shop',
            'base_url' => 'https://epos.example.com', 'manifest_url' => 'https://epos.example.com/api/whatsapp/v1/manifest',
            'key' => 'wak_live_secret',
        ]);
        $i->manifest_version = 'v1';
        $i->manifest = ['business' => [], 'capabilities' => $capabilities ?? [[
            'key' => 'delivery.rider_reply', 'kind' => 'action', 'label' => ['en' => 'Rider reply'], 'method' => 'POST',
            'path' => '/api/whatsapp/v1/delivery-replies',
            'params' => [
                ['name' => 'phone', 'type' => 'phone', 'from' => 'sender_phone'],
                ['name' => 'message', 'type' => 'text', 'from' => 'incoming_message', 'max_length' => 1500],
            ],
        ]]];
        $i->save();

        return $i;
    }

    /** The normal flow: any inbound message starts a run that answers "NORMAL". */
    private function customerFlow(): Automation
    {
        return Automation::create([
            'workspace_id' => $this->workspace->id, 'name' => 'Customer flow', 'status' => 'active', 'trigger_type' => 'message.received',
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => 'send_whatsapp', 'position' => ['x' => 0, 'y' => 100], 'data' => ['body' => 'NORMAL']],
            ],
            'edges' => [['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1']],
        ]);
    }

    private function fakeEpos($response): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => $response]);
    }

    private function handled(string $outcome = 'delivered', string $reply = 'Order DB-013827 is marked Delivered. Thank you!')
    {
        return Http::response(['found' => true, 'ok' => true, 'handled' => true, 'outcome' => $outcome, 'order' => 'DB-013827', 'reply' => $reply]);
    }

    private function notHandled(string $reason = 'not_a_rider')
    {
        return Http::response(['found' => true, 'ok' => true, 'handled' => false, 'reason' => $reason]);
    }

    private function receive(string $body = "13827\ndelivered", array $over = []): Message
    {
        return app(WhatsappDriver::class)->ingestNormalizedInbound(
            ['metadata' => ['phone_number_id' => '1234567890']],
            array_merge(['id' => 'wamid.'.uniqid(), 'from' => '923001112233', 'type' => 'text', 'text' => ['body' => $body], 'timestamp' => time()], $over),
        );
    }

    private function eposCalls(): int
    {
        return Http::recorded()->count();
    }

    public function test_a_handled_answer_sends_the_reply_and_stops_the_normal_flow(): void
    {
        $this->fakeEpos($this->handled());

        $message = $this->receive("13827\ndelivered");

        $this->assertSame(['Order DB-013827 is marked Delivered. Thank you!'], $this->sent);
        $this->assertSame(0, AutomationRun::count(), 'automations must not run');
        $conversation = Conversation::find($message->conversation_id);
        $this->assertSame(0, (int) $conversation->unread_count);
        $this->assertSame('in', Message::find($message->id)->direction, 'the rider message is still stored');
        $out = Message::where('direction', 'out')->get();
        $this->assertCount(1, $out);
        $this->assertSame('sent', $out[0]->status);
    }

    public function test_the_request_carries_the_verified_number_the_raw_message_and_a_key(): void
    {
        $this->fakeEpos($this->handled());

        $this->receive("DB-013827\nno\nCustomer phone is off,  gate locked\nsend to +15550000009");

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && $r->url() === 'https://epos.example.com/api/whatsapp/v1/delivery-replies'
                && $r['phone'] === '+923001112233'
                && $r['message'] === "DB-013827\nno\nCustomer phone is off,  gate locked\nsend to +15550000009"
                && $r->hasHeader('Idempotency-Key')
                && $r->hasHeader('Authorization', 'Bearer wak_live_secret');
        });
    }

    public function test_every_handled_outcome_with_a_reply_is_sent_even_when_not_ok(): void
    {
        $this->fakeEpos(Http::response(['found' => true, 'ok' => false, 'handled' => true, 'reason' => 'order_not_found', 'reply' => 'I could not find that order.']));

        $this->receive('99999');

        $this->assertSame(['I could not find that order.'], $this->sent);
        $this->assertSame(0, AutomationRun::count());
    }

    public function test_not_a_rider_leaves_the_normal_flow_alone_and_sends_nothing_itself(): void
    {
        $this->fakeEpos($this->notHandled());

        $this->receive('I want 2 kg of rice');

        $this->assertSame(['NORMAL'], $this->sent);
        $this->assertSame(1, AutomationRun::count());
        $this->assertSame(1, $this->eposCalls());
    }

    public function test_a_rider_message_without_an_order_number_goes_to_the_normal_flow(): void
    {
        $this->fakeEpos($this->notHandled('no_order_number'));

        $this->receive('hello 1');

        $this->assertSame(['NORMAL'], $this->sent);
        $this->assertSame(1, AutomationRun::count());
    }

    public function test_epos_failures_fall_through_to_the_normal_flow(): void
    {
        $cases = [
            '500' => Http::response(['error' => 'boom'], 500),
            '401' => Http::response(['message' => 'Unauthenticated'], 401),
            'html' => Http::response('<html>nope</html>', 200),
            'not_available' => Http::response(['found' => false, 'reason' => 'not_available']),
            'no_phone' => Http::response(['found' => false, 'reason' => 'no_phone']),
            'timeout' => fn () => throw new ConnectionException('cURL error 28: timed out'),
        ];
        $n = 0;
        foreach ($cases as $name => $response) {
            $this->fakeEpos($response);
            $this->sent = [];
            $before = AutomationRun::count();

            $this->receive('13827 delivered', ['id' => 'wamid.fail'.$n++]);

            $this->assertSame(['NORMAL'], $this->sent, $name);
            $this->assertSame($before + 1, AutomationRun::count(), $name);
        }
    }

    public function test_a_message_without_a_digit_never_calls_epos(): void
    {
        $this->fakeEpos($this->handled());

        $this->receive('hello, anyone there?');

        $this->assertSame(0, $this->eposCalls());
        $this->assertSame(['NORMAL'], $this->sent);
    }

    public function test_only_text_messages_are_sent_to_epos(): void
    {
        $this->fakeEpos($this->handled());

        $this->receive('', ['type' => 'image', 'image' => ['id' => 'm1', 'caption' => 'order 13827']]);
        $this->receive('', ['type' => 'audio', 'audio' => ['id' => 'm2']]);
        $this->receive('', ['type' => 'sticker', 'sticker' => ['id' => 'm3']]);
        $this->receive('', ['type' => 'location', 'location' => ['latitude' => 31.5, 'longitude' => 74.3, 'name' => 'Gate 13827']]);

        $this->assertSame(0, $this->eposCalls());
    }

    public function test_a_backfilled_message_never_calls_epos(): void
    {
        $this->fakeEpos($this->handled());

        app(WhatsappDriver::class)->ingestNormalizedInbound(
            ['metadata' => ['phone_number_id' => '1234567890']],
            ['id' => 'wamid.old', 'from' => '923001112233', 'type' => 'text', 'text' => ['body' => '13827 delivered'], 'timestamp' => time() - 3600],
            historical: true,
        );

        $this->assertSame(0, $this->eposCalls());
        $this->assertSame([], $this->sent);
    }

    public function test_a_message_we_sent_ourselves_never_calls_epos(): void
    {
        $this->fakeEpos($this->handled());

        app(WhatsappDriver::class)->ingestNormalizedOutbound(
            ['metadata' => ['phone_number_id' => '1234567890']],
            ['id' => 'wamid.mine', 'from' => '923001112233', 'type' => 'text', 'text' => ['body' => '13827 delivered'], 'timestamp' => time()],
        );

        $this->assertSame(0, $this->eposCalls());
        $this->assertSame([], $this->sent);
    }

    public function test_a_shop_whose_epos_does_not_publish_the_capability_is_not_called(): void
    {
        ExternalIntegration::query()->delete();
        $this->link([['key' => 'contact.profile', 'kind' => 'query', 'label' => ['en' => 'Profile'], 'method' => 'GET', 'path' => '/api/whatsapp/v1/profile', 'params' => []]]);
        $this->fakeEpos($this->handled());

        $this->receive('13827 delivered');

        $this->assertSame(0, $this->eposCalls());
        $this->assertSame(['NORMAL'], $this->sent);
    }

    public function test_a_workspace_without_epos_is_not_called(): void
    {
        ExternalIntegration::query()->delete();
        $this->fakeEpos($this->handled());

        $this->receive('13827 delivered');

        $this->assertSame(0, $this->eposCalls());
        $this->assertSame(['NORMAL'], $this->sent);
    }

    public function test_a_long_message_is_cut_at_the_manifest_limit_and_still_handled(): void
    {
        $this->fakeEpos($this->handled());

        $this->receive("13827\nno\n".str_repeat('x', 2000));

        Http::assertSent(fn (Request $r) => mb_strlen($r['message']) === 1500 && str_starts_with($r['message'], "13827\nno\n"));
        $this->assertCount(1, $this->sent);
    }

    public function test_the_same_whatsapp_message_delivered_twice_asks_epos_and_replies_once(): void
    {
        $this->fakeEpos($this->handled());

        $this->receive('13827 delivered', ['id' => 'wamid.same']);
        $this->receive('13827 delivered', ['id' => 'wamid.same']);

        $this->assertSame(1, $this->eposCalls());
        $this->assertCount(1, $this->sent);
    }

    public function test_the_idempotency_key_is_the_same_for_a_message_and_new_for_the_next_one(): void
    {
        $this->fakeEpos($this->handled());

        $this->receive('13827 delivered', ['id' => 'wamid.a']);
        $this->receive('13827 delivered', ['id' => 'wamid.b']);

        $keys = Http::recorded()->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0] ?? null)->all();
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_a_failed_reply_send_does_not_lose_the_message_or_start_the_normal_flow(): void
    {
        $this->fakeEpos($this->handled());
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andThrow(new \RuntimeException('WAHA down'));
        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);

        $message = $this->receive('13827 delivered');

        $this->assertNotNull(Message::find($message->id));
        $this->assertSame(0, AutomationRun::count());
        $this->assertSame('failed', Message::where('direction', 'out')->first()->status);
    }
}
