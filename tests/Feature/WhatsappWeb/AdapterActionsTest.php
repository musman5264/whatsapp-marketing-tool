<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\WhatsappWeb\Services\Waha\WahaAdapter;
use App\Modules\WhatsappWeb\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdapterActionsTest extends TestCase
{
    private function adapter(): WahaAdapter
    {
        return new WahaAdapter(new WahaClient('http://waha.test', 'k'));
    }

    #[Test]
    public function send_poll_posts_the_poll_shape_and_returns_the_id(): void
    {
        Http::fake(['waha.test/api/sendPoll' => Http::response(['id' => ['_serialized' => 'poll_1']], 201)]);

        $id = $this->adapter()->sendPoll('ws-1', '+15551230000', 'Pick one', ['A', 'B', 'C'], false);

        $this->assertSame('poll_1', $id);
        Http::assertSent(function ($r) {
            return str_ends_with($r->url(), '/api/sendPoll')
                && $r['session'] === 'ws-1'
                && $r['chatId'] === '15551230000@c.us'
                && $r['poll']['name'] === 'Pick one'
                && $r['poll']['options'] === ['A', 'B', 'C']
                && $r['poll']['multipleAnswers'] === false;
        });
    }

    #[Test]
    public function send_reaction_puts_to_the_reaction_endpoint(): void
    {
        Http::fake(['waha.test/api/reaction' => Http::response([], 200)]);

        $this->adapter()->sendReaction('ws-1', 'true_15551230000@c.us_ABC', '👍');

        Http::assertSent(function ($r) {
            return $r->method() === 'PUT'
                && str_ends_with($r->url(), '/api/reaction')
                && $r['session'] === 'ws-1'
                && $r['messageId'] === 'true_15551230000@c.us_ABC'
                && $r['reaction'] === '👍';
        });
    }

    #[Test]
    public function send_seen_posts_chat_id_and_optional_message_id(): void
    {
        Http::fake(['waha.test/api/sendSeen' => Http::response([], 200)]);

        $this->adapter()->sendSeen('ws-1', '15551230000@c.us', 'msg_9');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendSeen')
            && $r['session'] === 'ws-1'
            && $r['chatId'] === '15551230000@c.us'
            && $r['messageId'] === 'msg_9');
    }

    #[Test]
    public function send_typing_on_hits_start_typing_and_off_hits_stop_typing(): void
    {
        Http::fake([
            'waha.test/api/startTyping' => Http::response([], 200),
            'waha.test/api/stopTyping' => Http::response([], 200),
        ]);

        $this->adapter()->sendTyping('ws-1', '+15551230000', true);
        $this->adapter()->sendTyping('ws-1', '+15551230000', false);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/startTyping') && $r['chatId'] === '15551230000@c.us');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/stopTyping') && $r['chatId'] === '15551230000@c.us');
    }

    #[Test]
    public function reject_call_posts_the_call_id_to_the_session_scoped_endpoint(): void
    {
        Http::fake(['waha.test/api/ws-1/calls/reject' => Http::response([], 200)]);

        $this->adapter()->rejectCall('ws-1', 'call_42');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/ws-1/calls/reject') && $r['callId'] === 'call_42');
    }
}
