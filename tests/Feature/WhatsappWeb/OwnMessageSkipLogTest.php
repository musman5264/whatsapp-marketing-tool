<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Jobs\ProcessWahaEventJob;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\WahaEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-0068: a message sent from the phone (message.any, fromMe=true) that is not stored must say why in the
 * log, so one test message on the live site shows where the path stops. Nothing here changes what is stored.
 */
class OwnMessageSkipLogTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappWebSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = $this->createWorkspaceContext();
        IntegrationConfig::create([
            'provider' => 'whatsapp_web', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test', 'api_key' => 'k'], 'webhook_secret' => 'secret',
        ]);
        $name = 'ws-'.$ctx['workspace']->id;
        $this->session = WhatsappWebSession::create([
            'workspace_id' => $ctx['workspace']->id, 'session_name' => $name, 'engine' => 'waha', 'status' => 'active', 'webhook_token' => str_repeat('t', 48),
        ]);
        ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id, 'channel' => 'whatsapp', 'provider' => 'whatsapp_web',
            'phone_number_id' => $name, 'display_name' => 'WhatsApp (personal)', 'status' => 'active',
        ]);
    }

    private function phoneSent(array $over = []): array
    {
        return ['event' => 'message.any', 'session' => $this->session->session_name, 'payload' => array_merge([
            'id' => 'true_15551234567@c.us_OWN1', 'timestamp' => now()->timestamp, 'from' => '15550000000@c.us', 'to' => '15551234567@c.us',
            'fromMe' => true, 'body' => 'Sent from the phone', 'type' => 'chat', 'ack' => 1, 'source' => 'app',
        ], $over)];
    }

    private function process(array $payload): void
    {
        app(WahaEventProcessor::class)->process($payload, $this->session);
    }

    private function assertSkippedBecause(string $reason, ?callable $extra = null): void
    {
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $ctx = []) use ($reason, $extra) {
            return $message === 'whatsapp_web.own_message.skipped'
                && ($ctx['reason'] ?? null) === $reason
                && ($extra === null || $extra($ctx));
        })->once();
    }

    #[Test]
    public function an_api_message_we_do_not_know_is_looked_at_again_later_instead_of_dropped(): void
    {
        Queue::fake();
        Log::spy();

        $this->process($this->phoneSent(['source' => 'api']));

        $this->assertSkippedBecause('api_send_recheck');
        Queue::assertPushedOn('whatsapp', ProcessWahaEventJob::class);
        $this->assertSame(0, Message::count());
    }

    #[Test]
    public function an_api_message_still_unknown_on_the_second_look_is_stored_because_someone_else_sent_it(): void
    {
        $payload = $this->phoneSent(['source' => 'api', 'body' => 'Typed in the WAHA dashboard']) + ['recheck' => true];

        $this->process($payload);

        $this->assertSame(1, Message::where('direction', 'out')->where('body', 'Typed in the WAHA dashboard')->count());
    }

    #[Test]
    public function an_api_message_our_own_send_path_already_stored_is_not_stored_twice(): void
    {
        $this->process($this->phoneSent(['source' => 'app']));
        Log::spy();

        $this->process($this->phoneSent(['source' => 'api']) + ['recheck' => true]);

        $this->assertSkippedBecause('sent_by_api');
        $this->assertSame(1, Message::count());
    }

    #[Test]
    public function a_message_without_a_usable_chat_says_what_kind_of_ids_it_carried_but_never_the_digits(): void
    {
        Log::spy();

        $this->process($this->phoneSent(['id' => 'true_x_NOTO', 'to' => null]));

        $this->assertSkippedBecause('unusable_chat', fn (array $ctx) => $ctx['to_kind'] === 'none'
            && $ctx['from_kind'] === 'c.us'
            && ! str_contains(json_encode($ctx), '15550000000'));
        $this->assertSame(0, Message::count());
    }

    #[Test]
    public function a_group_message_from_the_phone_says_so(): void
    {
        Log::spy();

        $this->process($this->phoneSent(['id' => 'true_g_G1', 'to' => '120363000000000000@g.us']));

        $this->assertSkippedBecause('unusable_chat', fn (array $ctx) => $ctx['to_kind'] === 'g.us');
    }

    #[Test]
    public function a_message_we_already_stored_says_so(): void
    {
        $this->process($this->phoneSent());
        $this->assertSame(1, Message::count());
        Log::spy();

        $this->process($this->phoneSent());

        $this->assertSkippedBecause('already_stored');
        $this->assertSame(1, Message::count());
    }

    #[Test]
    public function a_phone_message_that_stores_normally_logs_no_skip(): void
    {
        Log::spy();

        $this->process($this->phoneSent());

        Log::shouldNotHaveReceived('info', fn ($m) => $m === 'whatsapp_web.own_message.skipped');
        $this->assertSame(1, Message::count());
    }
}
