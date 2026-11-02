<?php

namespace Tests\Feature\Automation;

use App\Models\ClientSetting;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * RentOyee has its own flow step ("rentoyee"), separate from the E-POS one. With both systems linked to the same
 * workspace each step talks only to its own system, whatever integration_id a flow carries or leaves out.
 */
class RentOyeeNodeTest extends TestCase
{
    use RefreshDatabase;

    private $workspace;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->workspace = $this->createWorkspaceContext()['workspace'];
        $this->contact = Contact::factory()->create(['workspace_id' => $this->workspace->id, 'first_name' => 'Alice', 'phone_e164' => '+15550000001']);
        ChannelAccount::create([
            'workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'provider' => 'whatsapp',
            'status' => 'active', 'display_name' => 'Test WA', 'phone_number_id' => '1234567890',
        ]);
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

        foreach (['epos' => 'epos.example.com', 'rentoyee' => 'rent.example.com'] as $type => $host) {
            ClientSetting::set((int) $this->workspace->client_id, ExternalIntegration::switchKey($type), '1');
            $i = new ExternalIntegration([
                'workspace_id' => $this->workspace->id, 'type' => $type, 'name' => $type, 'base_url' => "https://$host",
                'manifest_url' => "https://$host/api/whatsapp/v1/manifest", 'key' => 'wak_live_'.$type,
            ]);
            $i->manifest_version = 'v1';
            $i->manifest = ['business' => [], 'capabilities' => [[
                'key' => 'customer.lookup', 'kind' => 'query', 'label' => ['en' => 'Lookup'], 'method' => 'GET', 'path' => '/api/whatsapp/v1/lookup',
                'params' => [['name' => 'phone', 'type' => 'phone', 'from' => 'sender_phone']],
            ]]];
            $i->save();
        }

        Http::swap(new HttpFactory);
        Http::fake([
            'epos.example.com/*' => Http::response(['found' => true, 'name' => 'FromEpos']),
            'rent.example.com/*' => Http::response(['found' => true, 'name' => 'FromRentOyee']),
        ]);
    }

    private function runStep(string $nodeType, array $data): string
    {
        $automation = Automation::create([
            'workspace_id' => $this->workspace->id, 'name' => 'Test', 'status' => 'active', 'trigger_type' => 'contact.created',
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => $nodeType, 'position' => ['x' => 0, 'y' => 100], 'data' => $data + ['capability' => 'customer.lookup']],
                ['id' => 'y', 'type' => 'send_whatsapp', 'position' => ['x' => 0, 'y' => 200], 'data' => ['body' => 'Hi {{'.$nodeType.'.name}}']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1'],
                ['id' => 'e2', 'source' => 'n1', 'target' => 'y', 'sourceHandle' => 'true'],
            ],
        ]);
        $run = AutomationRun::create(['automation_id' => $automation->id, 'contact_id' => $this->contact->id, 'status' => 'pending', 'context' => [], 'started_at' => now()]);
        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        return (string) Message::where('direction', 'out')->latest('id')->value('body');
    }

    public function test_a_rentoyee_step_without_an_integration_id_uses_the_rentoyee_link_and_its_own_variable(): void
    {
        $this->assertSame('Hi FromRentOyee', $this->runStep('rentoyee', []));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'rent.example.com') && $r->hasHeader('Authorization', 'Bearer wak_live_rentoyee'));
    }

    public function test_an_epos_step_without_an_integration_id_still_uses_the_epos_link(): void
    {
        $this->assertSame('Hi FromEpos', $this->runStep('epos', []));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'epos.example.com'));
    }

    public function test_a_rentoyee_step_with_no_rentoyee_link_does_not_fall_back_to_epos(): void
    {
        ExternalIntegration::where('type', 'rentoyee')->delete();

        $this->runStep('rentoyee', []);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'epos.example.com'));
    }
}
