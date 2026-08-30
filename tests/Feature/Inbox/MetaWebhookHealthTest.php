<?php

namespace Tests\Feature\Inbox;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaWebhookHealthTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = 'test_meta_app_id';

    private const APP_SECRET = 'test_meta_app_secret';

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function seedMetaIntegration(): void
    {
        IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'app_id' => self::APP_ID,
                'app_secret' => self::APP_SECRET,
                'verify_token' => 'meta-verify-token-xyz',
            ],
        ]);
    }

    private function makeMessengerAccount(): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'messenger',
            'provider' => 'meta',
            'display_name' => 'Test Page',
            'credentials' => ['page_access_token' => 'page-token-1'],
            'meta_json' => ['page_id' => '111222333'],
            'status' => 'active',
        ]);
    }

    #[Test]
    public function health_reports_missing_page_subscription(): void
    {
        $this->seedMetaIntegration();
        $account = $this->makeMessengerAccount();

        Http::fake([
            // App-level subscription exists, active, correct callback
            'graph.facebook.com/v20.0/'.self::APP_ID.'/subscriptions*' => Http::response([
                'data' => [[
                    'object' => 'page',
                    'active' => true,
                    'callback_url' => route('webhooks.meta.receive', ['token' => 'meta-verify-token-xyz']),
                    'fields' => [['name' => 'messages']],
                ]],
            ]),
            // Page has NO subscribed apps
            'graph.facebook.com/v20.0/111222333/subscribed_apps' => Http::response(['data' => []]),
        ]);

        $res = $this->actingAs($this->ctx['user'])
            ->getJson(route('client.inbox.setup.webhook-health', ['channelAccount' => $account->id]));

        $res->assertOk()->assertJsonPath('ok', false);

        $checks = collect($res->json('checks'));
        $this->assertTrue($checks->firstWhere('key', 'app_subscription')['ok']);
        $this->assertFalse($checks->firstWhere('key', 'page_subscription')['ok']);
        $this->assertTrue($checks->firstWhere('key', 'app_credentials')['ok']);
    }

    #[Test]
    public function health_reports_callback_url_mismatch(): void
    {
        $this->seedMetaIntegration();
        $account = $this->makeMessengerAccount();

        Http::fake([
            'graph.facebook.com/v20.0/'.self::APP_ID.'/subscriptions*' => Http::response([
                'data' => [[
                    'object' => 'page',
                    'active' => true,
                    'callback_url' => 'https://other-install.example.com/webhooks/meta/old-token',
                    'fields' => [['name' => 'messages']],
                ]],
            ]),
            'graph.facebook.com/v20.0/111222333/subscribed_apps' => Http::response([
                'data' => [['id' => self::APP_ID, 'subscribed_fields' => ['messages']]],
            ]),
        ]);

        $res = $this->actingAs($this->ctx['user'])
            ->getJson(route('client.inbox.setup.webhook-health', ['channelAccount' => $account->id]));

        $res->assertOk()->assertJsonPath('ok', false);

        $appCheck = collect($res->json('checks'))->firstWhere('key', 'app_subscription');
        $this->assertFalse($appCheck['ok']);
        $this->assertStringContainsString('does not match', $appCheck['detail']);
    }

    #[Test]
    public function health_reports_all_green_when_fully_wired(): void
    {
        $this->seedMetaIntegration();
        $account = $this->makeMessengerAccount();

        Http::fake([
            'graph.facebook.com/v20.0/'.self::APP_ID.'/subscriptions*' => Http::response([
                'data' => [[
                    'object' => 'page',
                    'active' => true,
                    'callback_url' => route('webhooks.meta.receive', ['token' => 'meta-verify-token-xyz']),
                    'fields' => [['name' => 'messages']],
                ]],
            ]),
            'graph.facebook.com/v20.0/111222333/subscribed_apps' => Http::response([
                'data' => [['id' => self::APP_ID, 'subscribed_fields' => ['messages']]],
            ]),
        ]);

        $this->actingAs($this->ctx['user'])
            ->getJson(route('client.inbox.setup.webhook-health', ['channelAccount' => $account->id]))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function resubscribe_registers_app_and_page_subscriptions(): void
    {
        $this->seedMetaIntegration();
        $account = $this->makeMessengerAccount();

        Http::fake([
            'graph.facebook.com/v20.0/'.self::APP_ID.'/subscriptions' => Http::response(['success' => true]),
            'graph.facebook.com/v20.0/111222333/subscribed_apps' => Http::response(['success' => true]),
        ]);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.setup.resubscribe', ['channelAccount' => $account->id]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.self::APP_ID.'/subscriptions')
            && $request['object'] === 'page');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/111222333/subscribed_apps'));
    }

    #[Test]
    public function resubscribe_derives_facebook_page_for_legacy_instagram_account(): void
    {
        $this->seedMetaIntegration();

        // Legacy connect: no facebook_page_id stored, only the IG account id.
        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'instagram',
            'provider' => 'meta',
            'display_name' => '@legacy',
            'credentials' => ['access_token' => 'page-token-2', 'instagram_account_id' => '17841400001'],
            'meta_json' => ['instagram_page_id' => '17841400001', 'instagram_account_id' => '17841400001'],
            'status' => 'active',
        ]);

        Http::fake([
            'graph.facebook.com/v20.0/'.self::APP_ID.'/subscriptions' => Http::response(['success' => true]),
            'graph.facebook.com/v20.0/me*' => Http::response(['id' => '444555666']),
            'graph.facebook.com/v20.0/444555666/subscribed_apps' => Http::response(['success' => true]),
        ]);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.setup.resubscribe', ['channelAccount' => $account->id]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('444555666', $account->fresh()->meta_json['facebook_page_id'] ?? null);
    }

    #[Test]
    public function health_is_forbidden_for_other_workspaces_and_non_meta_channels(): void
    {
        $this->seedMetaIntegration();
        $account = $this->makeMessengerAccount();

        $other = $this->createWorkspaceContext();
        $this->actingAs($other['user'])
            ->getJson(route('client.inbox.setup.webhook-health', ['channelAccount' => $account->id]))
            ->assertForbidden();

        $whatsapp = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'WA',
            'credentials' => [],
            'meta_json' => [],
            'status' => 'active',
        ]);

        $this->actingAs($this->ctx['user'])
            ->getJson(route('client.inbox.setup.webhook-health', ['channelAccount' => $whatsapp->id]))
            ->assertForbidden();
    }
}
