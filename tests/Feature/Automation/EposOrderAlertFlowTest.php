<?php

namespace Tests\Feature\Automation;

use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Services\External\HostResolver;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The bundled example flow (app/logic/examples/epos-order-alert.flow.json) against the EPOS alert.push answers
 * from EPOS-ALERT-SPEC.md: every business result must give the customer the matching reply.
 */
class EposOrderAlertFlowTest extends TestCase
{
    use RefreshDatabase;

    private $workspace;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = $this->createWorkspaceContext()['workspace'];
        $this->contact = Contact::factory()->create(['workspace_id' => $this->workspace->id, 'phone_e164' => '+923001112233']);
        ChannelAccount::create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'provider' => 'whatsapp', 'status' => 'active', 'display_name' => 'WA', 'phone_number_id' => '1234567890']);
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andReturn('provider.msg.id');
        $this->app->instance(WhatsappDriver::class, $driver);
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function ips(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        $i = new ExternalIntegration(['workspace_id' => $this->workspace->id, 'type' => 'epos', 'name' => 'Shop', 'base_url' => 'https://epos.example.com', 'manifest_url' => 'https://epos.example.com/api/whatsapp/v1/manifest', 'key' => 'wak_live_x']);
        $i->manifest_version = 'v1';
        $i->manifest = ['business' => [], 'capabilities' => [[
            'key' => 'alert.push', 'kind' => 'action', 'label' => ['en' => 'Alert'], 'method' => 'POST', 'path' => '/api/whatsapp/v1/alerts',
            'params' => [
                ['name' => 'phone', 'type' => 'phone', 'from' => 'sender_phone'],
                ['name' => 'message', 'type' => 'text', 'from' => 'chat_answer', 'max_length' => 1500],
                ['name' => 'title', 'type' => 'text', 'from' => 'fixed', 'optional' => true],
            ],
        ]]];
        $i->save();
    }

    /** The customer has just sent their 6 lines: run the flow from the E-POS step. */
    private function runWith(string $answer): void
    {
        $file = json_decode((string) file_get_contents(base_path('app/logic/examples/epos-order-alert.flow.json')), true)['automation'];
        $automation = Automation::create(['workspace_id' => $this->workspace->id, 'name' => 'Order', 'status' => 'active', 'trigger_type' => 'message.received', 'trigger_config' => $file['trigger_config'], 'nodes' => $file['nodes'], 'edges' => $file['edges']]);
        $run = AutomationRun::create(['automation_id' => $automation->id, 'contact_id' => $this->contact->id, 'status' => 'pending', 'context' => ['answer' => $answer], 'resume_node_id' => 'alert', 'started_at' => now()]);
        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));
    }

    private function fake($response): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => $response]);
    }

    #[DataProvider('answers')]
    public function test_each_epos_answer_gets_the_right_reply(array $epos, string $expected): void
    {
        $this->fake(Http::response($epos));

        $this->runWith("Name: Ali\nItems: Rice");

        $this->assertStringContainsString($expected, (string) Message::where('direction', 'out')->latest('id')->value('body'));
    }

    public static function answers(): array
    {
        return [
            'saved' => [['found' => true, 'ok' => true, 'alert_id' => 'AL-1042'], 'Reference: AL-1042.'],
            'disabled' => [['found' => true, 'ok' => false, 'reason' => 'disabled'], 'not accepting requests'],
            'rate limited' => [['found' => true, 'ok' => false, 'reason' => 'rate_limited'], 'not available right now'],
            'invalid' => [['found' => true, 'ok' => false, 'reason' => 'invalid'], 'too long or empty'],
            'no phone' => [['found' => false, 'reason' => 'no_phone'], 'could not send your request'],
            'not available' => [['found' => false, 'reason' => 'not_available'], 'could not send your request'],
            'unknown capability' => [['found' => false, 'reason' => 'unknown_capability'], 'could not send your request'],
        ];
    }

    public function test_a_server_error_is_never_called_saved(): void
    {
        $this->fake(Http::response('boom', 500));

        $this->runWith('Name: Ali');

        $body = (string) Message::where('direction', 'out')->latest('id')->value('body');
        $this->assertStringContainsString('could not send your request', $body);
        $this->assertStringNotContainsString('has been received', $body);
    }

    public function test_a_message_over_1500_characters_is_never_sent_to_epos_and_gets_the_too_long_reply(): void
    {
        $this->fake(Http::response(['found' => true, 'ok' => true, 'alert_id' => 'AL-1']));

        $this->runWith(str_repeat('a', 1501));

        Http::assertNothingSent();
        $this->assertStringContainsString('too long or empty', (string) Message::where('direction', 'out')->latest('id')->value('body'));
    }

    public function test_the_alert_is_posted_once_with_the_title_the_customers_text_and_their_verified_number(): void
    {
        $this->fake(Http::response(['found' => true, 'ok' => true, 'alert_id' => 'AL-1']));

        $this->runWith("Name: Ali\nQuantity: 2");

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->url() === 'https://epos.example.com/api/whatsapp/v1/alerts'
            && $r['phone'] === '+923001112233'
            && $r['title'] === 'New order request'
            && $r['message'] === "Name: Ali\nQuantity: 2"
            && $r->hasHeader('Idempotency-Key'));
    }
}
