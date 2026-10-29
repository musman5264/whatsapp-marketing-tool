<?php

namespace Tests\Feature\Integrations;

use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\External\HostResolver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\WhatsappWeb\Jobs\SendProfilePhotoToEposJob;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The customer's WhatsApp profile photo goes to EPOS (contact.photo_update). WAHA and EPOS are faked at the HTTP layer.
 */
class EposContactPhotoTest extends TestCase
{
    use RefreshDatabase;

    private $workspace;

    private WhatsappWebSession $session;

    private Contact $contact;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->workspace = $this->createWorkspaceContext()['workspace'];

        IntegrationConfig::create([
            'provider' => 'whatsapp_web', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'], 'webhook_secret' => 'secret',
        ]);
        $this->token = Str::random(48);
        $this->session = WhatsappWebSession::create([
            'workspace_id' => $this->workspace->id, 'session_name' => 'ws-1', 'engine' => 'waha',
            'status' => 'active', 'webhook_token' => $this->token,
        ]);
        ChannelAccount::create([
            'workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-1', 'display_name' => 'WhatsApp (personal)', 'status' => 'active',
        ]);
        $this->contact = Contact::create(['workspace_id' => $this->workspace->id, 'phone_e164' => '+15551234567', 'first_name' => 'Sam']);

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function ips(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    /** An EPOS link; $withCapability false = a manifest that does not publish the photo action. */
    private function link(bool $withCapability = true): void
    {
        $i = new ExternalIntegration([
            'workspace_id' => $this->workspace->id, 'type' => 'epos', 'name' => 'Shop',
            'base_url' => 'https://epos.example.com', 'manifest_url' => 'https://epos.example.com/api/whatsapp/v1/manifest',
            'key' => 'wak_live_secret',
        ]);
        $i->manifest_version = 'v1';
        $i->manifest = ['business' => [], 'capabilities' => $withCapability ? [[
            'key' => 'contact.photo_update', 'kind' => 'action', 'label' => ['en' => 'Save my profile photo'], 'method' => 'POST',
            'path' => '/api/whatsapp/v1/contact/photo',
            'params' => [
                ['name' => 'phone', 'type' => 'phone', 'from' => 'sender_phone'],
                ['name' => 'photo', 'type' => 'text', 'from' => 'profile_photo', 'optional' => true, 'max_length' => 900000],
                ['name' => 'removed', 'type' => 'boolean', 'from' => 'profile_photo', 'optional' => true],
            ],
        ]] : []];
        $i->save();
    }

    private function fake(?string $pictureUrl, string $bytes = 'JPEGBYTES', array $epos = ['found' => true, 'ok' => true, 'updated' => ['photo']]): void
    {
        Http::fake([
            'waha.test/api/contacts/profile-picture*' => Http::response($pictureUrl ? ['profilePictureURL' => $pictureUrl] : ['profilePictureURL' => null]),
            'pps.whatsapp.net/*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
            'epos.example.com/*' => Http::response($epos),
        ]);
    }

    private function runJob(): void
    {
        (new SendProfilePhotoToEposJob($this->contact->id, $this->session->id))->handle(app(\App\Modules\WhatsappWeb\Services\EngineManager::class), app(\App\Modules\Integrations\Services\External\EposExecutor::class));
    }

    private function eposRequests()
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'epos.example.com'));
    }

    public function test_the_photo_is_sent_base64_with_the_contacts_verified_number(): void
    {
        $this->link();
        $bytes = str_repeat('x', 100_000); // far over the 2000-character text cap
        $this->fake('https://pps.whatsapp.net/a.jpg', $bytes);

        $this->runJob();

        $sent = $this->eposRequests();
        $this->assertCount(1, $sent);
        $request = $sent->first()[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame('https://epos.example.com/api/whatsapp/v1/contact/photo', $request->url());
        $this->assertSame('+15551234567', $request['phone']);
        $this->assertSame(base64_encode($bytes), $request['photo']);
        $this->assertArrayNotHasKey('removed', $request->data());
        $this->assertTrue(Cache::has('epos-photo-sent:'.$this->contact->id));
    }

    public function test_nothing_is_asked_when_epos_does_not_publish_the_action(): void
    {
        $this->link(false);
        $this->fake('https://pps.whatsapp.net/a.jpg');

        $this->runJob();

        Http::assertNothingSent();
    }

    public function test_a_hidden_photo_sends_nothing_to_epos(): void
    {
        $this->link();
        $this->fake(null);

        $this->runJob();

        $this->assertCount(0, $this->eposRequests());
        $this->assertFalse(Cache::has('epos-photo-sent:'.$this->contact->id));
    }

    public function test_a_photo_over_600_kb_is_not_sent(): void
    {
        $this->link();
        $this->fake('https://pps.whatsapp.net/a.jpg', str_repeat('x', 600_001));

        $this->runJob();

        $this->assertCount(0, $this->eposRequests());
    }

    public function test_a_refusal_from_epos_is_not_remembered_so_a_later_message_can_try_again(): void
    {
        $this->link();
        $this->fake('https://pps.whatsapp.net/a.jpg', 'JPEGBYTES', ['found' => true, 'ok' => false, 'reason' => 'invalid_photo']);

        $this->runJob();

        $this->assertCount(1, $this->eposRequests());
        $this->assertFalse(Cache::has('epos-photo-sent:'.$this->contact->id));
    }

    public function test_a_photo_already_sent_this_month_is_not_sent_again(): void
    {
        $this->link();
        $this->fake('https://pps.whatsapp.net/a.jpg');
        Cache::put('epos-photo-sent:'.$this->contact->id, 1, now()->addDays(30));

        $this->runJob();

        Http::assertNothingSent();
    }

    public function test_a_customer_message_queues_the_job_once_a_day(): void
    {
        $this->link();
        Queue::fake([SendProfilePhotoToEposJob::class]);

        foreach (['false_1@c.us_A', 'false_2@c.us_B'] as $id) {
            $payload = ['event' => 'message', 'session' => 'ws-1', 'payload' => [
                'id' => $id, 'timestamp' => now()->timestamp, 'from' => '15551234567@c.us', 'fromMe' => false,
                'body' => 'Hi', 'type' => 'chat', 'notifyName' => 'Sam',
            ]];
            $this->withHeaders(['X-Webhook-Hmac' => hash_hmac('sha512', json_encode($payload), 'secret'), 'X-Webhook-Hmac-Algorithm' => 'sha512'])
                ->postJson("/webhooks/whatsapp-web/{$this->token}", $payload)->assertOk();
        }

        Queue::assertPushed(SendProfilePhotoToEposJob::class, 1);
    }

    public function test_a_customer_message_queues_nothing_when_epos_does_not_publish_the_action(): void
    {
        $this->link(false);
        Queue::fake([SendProfilePhotoToEposJob::class]);
        $payload = ['event' => 'message', 'session' => 'ws-1', 'payload' => [
            'id' => 'false_1@c.us_A', 'timestamp' => now()->timestamp, 'from' => '15551234567@c.us', 'fromMe' => false,
            'body' => 'Hi', 'type' => 'chat', 'notifyName' => 'Sam',
        ]];

        $this->withHeaders(['X-Webhook-Hmac' => hash_hmac('sha512', json_encode($payload), 'secret'), 'X-Webhook-Hmac-Algorithm' => 'sha512'])
            ->postJson("/webhooks/whatsapp-web/{$this->token}", $payload)->assertOk();

        Queue::assertNotPushed(SendProfilePhotoToEposJob::class);
    }
}
