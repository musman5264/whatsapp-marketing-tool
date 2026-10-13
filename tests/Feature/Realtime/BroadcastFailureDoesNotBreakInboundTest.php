<?php

namespace Tests\Feature\Realtime;

use App\Events\MessageReceived;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Pusher or Reverb refusing an inbound message event (quota, payload size, the server being
 * down) must not stop the WhatsApp message: it is stored once, its listeners (automations)
 * still run, and no second copy is created by a retry.
 */
class BroadcastFailureDoesNotBreakInboundTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappWebSession $session;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'],
            'webhook_secret' => 'secret',
        ]);

        $this->token = Str::random(48);
        $name = 'ws-'.$ctx['workspace']->id;
        $this->session = WhatsappWebSession::create([
            'workspace_id' => $ctx['workspace']->id,
            'session_name' => $name,
            'engine' => 'waha',
            'status' => 'active',
            'webhook_token' => $this->token,
        ]);
        ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => $name,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);

        // A broadcaster that refuses the inbound message event, as Pusher does for a rejected
        // event. Other events are accepted, so the test isolates this one event.
        Broadcast::extend('refusing', fn () => new class implements Broadcaster {
            public function auth($request)
            {
                return [];
            }

            public function validAuthenticationResponse($request, $result)
            {
                return [];
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                if ($event === 'MessageReceived') {
                    throw new RuntimeException('Pusher refused the event (test)');
                }
            }
        });
        config([
            'broadcasting.connections.refusing' => ['driver' => 'refusing'],
            'broadcasting.default' => 'refusing',
        ]);
    }

    private function postInbound(string $id): void
    {
        $payload = [
            'event' => 'message',
            'session' => $this->session->session_name,
            'payload' => [
                'id' => $id,
                'timestamp' => now()->timestamp,
                'from' => '15551239999@c.us',
                'fromMe' => false,
                'body' => 'Hello while Pusher refuses',
                'type' => 'chat',
                'notifyName' => 'Sam',
            ],
        ];
        $this->postJson("/webhooks/whatsapp-web/{$this->token}", $payload, [
            'X-Webhook-Hmac' => hash_hmac('sha512', json_encode($payload), 'secret'),
        ])->assertOk();
    }

    #[Test]
    public function a_refused_broadcast_keeps_one_message_and_still_runs_the_listeners(): void
    {
        $listened = 0;
        Event::listen(MessageReceived::class, function () use (&$listened) {
            $listened++;
        });

        $this->postInbound('false_900@c.us_REFUSE');

        $this->assertSame(1, Message::where('provider_message_id', 'false_900@c.us_REFUSE')->count());
        $this->assertSame(1, $listened);
    }

    #[Test]
    public function a_failing_listener_after_the_message_is_stored_does_not_create_it_twice(): void
    {
        // The row exists once the listener runs; a retry of the same WhatsApp message must
        // find it instead of storing a second copy.
        Event::listen(MessageReceived::class, function () {
            throw new RuntimeException('listener failed (test)');
        });

        $this->postInbound('false_901@c.us_LISTENER');

        $this->assertSame(1, Message::where('provider_message_id', 'false_901@c.us_LISTENER')->count());
    }
}
