<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManualChannelConnectTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
        Queue::fake();
    }

    /* ─────────────────── WhatsApp ─────────────────── */

    private function fakeWhatsappGraph(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/111222333/phone_numbers*' => Http::response([
                'data' => [[
                    'id' => '999888777',
                    'display_phone_number' => '+1 555-0100',
                    'verified_name' => 'Test Biz',
                ]],
            ]),
            'graph.facebook.com/v20.0/999888777/register' => Http::response(['success' => true]),
            'graph.facebook.com/v20.0/999888777*' => Http::response([
                'id' => '999888777',
                'display_phone_number' => '+1 555-0100',
                'verified_name' => 'Test Biz',
                'quality_rating' => 'GREEN',
            ]),
            'graph.facebook.com/v20.0/111222333/subscribed_apps' => Http::response(['success' => true]),
            'graph.facebook.com/v20.0/111222333*' => Http::response([
                'id' => '111222333',
                'name' => 'Manual WABA',
                'currency' => 'USD',
                'timezone_id' => '1',
            ]),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);
    }

    public function test_whatsapp_manual_connect_creates_waba_and_channel_account(): void
    {
        $this->fakeWhatsappGraph();

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'MANUAL_TOKEN_123',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true, 'waba_id' => '111222333', 'phone_count' => 1])
            ->assertJsonStructure(['webhook_url', 'webhook_verify_token']);

        $waba = WhatsappBusinessAccount::where('waba_id', '111222333')->first();
        $this->assertNotNull($waba);
        $this->assertSame($this->ctx['workspace']->id, $waba->workspace_id);
        // encrypted:array cast must round-trip the pasted token
        $this->assertSame('MANUAL_TOKEN_123', $waba->credentials['system_user_token']);
        $this->assertSame('manual', $waba->credentials['token_source']);
        $this->assertSame('manual', $waba->meta_json['connected_via']);

        $this->assertDatabaseHas('channel_accounts', [
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'phone_number_id' => '999888777',
            'business_account_id' => '111222333',
            'status' => 'active',
        ]);
    }

    public function test_whatsapp_manual_connect_rejects_invalid_token(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.'],
            ], 401),
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'BAD_TOKEN',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Invalid OAuth access token', $response->json('message'));
        $this->assertDatabaseMissing('whatsapp_business_accounts', ['waba_id' => '111222333']);
    }

    public function test_whatsapp_manual_connect_rejects_waba_owned_by_other_workspace(): void
    {
        $this->fakeWhatsappGraph();

        $other = $this->createWorkspaceContext();
        WhatsappBusinessAccount::create([
            'workspace_id' => $other['workspace']->id,
            'waba_id' => '111222333',
            'credentials' => ['system_user_token' => 'x'],
            'webhook_verify_token' => str_repeat('a', 48),
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'MANUAL_TOKEN_123',
        ]);

        $response->assertStatus(409);
    }

    /* ─────────────────── Instagram ─────────────────── */

    public function test_instagram_manual_connect_creates_channel_account(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/555000/subscribed_apps*' => Http::response(['success' => true]),
            'graph.facebook.com/v20.0/555000*' => Http::response([
                'id' => '555000',
                'name' => 'Test Page',
                'access_token' => 'PAGE_TOKEN_ABC',
                'instagram_business_account' => ['id' => '17840000000', 'username' => 'testbiz'],
            ]),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.inbox.setup.manual.instagram'), [
            'page_id' => '555000',
            'access_token' => 'USER_TOKEN',
        ]);

        $response->assertOk()->assertJson(['success' => true, 'connected' => 1, 'name' => 'testbiz']);

        $account = ChannelAccount::where('workspace_id', $this->ctx['workspace']->id)
            ->where('channel', 'instagram')
            ->first();
        $this->assertNotNull($account);
        $this->assertSame('testbiz', $account->display_name);
        // page token (from the page node) must be stored, decrypted via cast
        $this->assertSame('PAGE_TOKEN_ABC', $account->credentials['access_token']);
        $this->assertSame('17840000000', $account->credentials['instagram_account_id']);
        $this->assertSame('17840000000', $account->meta_json['instagram_page_id']);
        $this->assertSame('manual', $account->meta_json['connected_via']);
    }

    public function test_instagram_manual_connect_requires_linked_ig_account(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/555000*' => Http::response([
                'id' => '555000',
                'name' => 'Test Page',
            ]),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.inbox.setup.manual.instagram'), [
            'page_id' => '555000',
            'access_token' => 'USER_TOKEN',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('No Instagram Business account', $response->json('message'));
        $this->assertDatabaseMissing('channel_accounts', ['channel' => 'instagram']);
    }

    /* ─────────────────── Messenger ─────────────────── */

    public function test_messenger_manual_connect_accepts_page_token(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/me*' => Http::response(['id' => '555000']),
            'graph.facebook.com/v20.0/555000/subscribed_apps*' => Http::response(['success' => true]),
            'graph.facebook.com/v20.0/555000*' => Http::response([
                'id' => '555000',
                'name' => 'Test Page',
                // page node fetched with its own token does not echo access_token
            ]),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.inbox.setup.manual.messenger'), [
            'page_id' => '555000',
            'access_token' => 'PAGE_TOKEN_XYZ',
        ]);

        $response->assertOk()->assertJson(['success' => true, 'connected' => 1, 'name' => 'Test Page']);

        $account = ChannelAccount::where('workspace_id', $this->ctx['workspace']->id)
            ->where('channel', 'messenger')
            ->first();
        $this->assertNotNull($account);
        $this->assertSame('PAGE_TOKEN_XYZ', $account->credentials['page_access_token']);
        $this->assertSame('555000', $account->meta_json['page_id']);
        $this->assertSame('manual', $account->meta_json['connected_via']);
    }

    public function test_messenger_manual_connect_rejects_non_page_token(): void
    {
        Http::fake([
            // /me resolves to a user id, not the page — token is not a Page token
            'graph.facebook.com/v20.0/me*' => Http::response(['id' => '42']),
            'graph.facebook.com/v20.0/555000*' => Http::response([
                'id' => '555000',
                'name' => 'Test Page',
            ]),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.inbox.setup.manual.messenger'), [
            'page_id' => '555000',
            'access_token' => 'USER_TOKEN',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Page Access Token', $response->json('message'));
        $this->assertDatabaseMissing('channel_accounts', ['channel' => 'messenger']);
    }

    /* ─────────────────── Validation / auth ─────────────────── */

    public function test_manual_endpoints_validate_required_fields(): void
    {
        $user = $this->ctx['user'];

        $this->actingAs($user)->postJson(route('client.whatsapp.setup.manual'), [])
            ->assertStatus(422)->assertJsonValidationErrors(['waba_id', 'access_token']);

        $this->actingAs($user)->postJson(route('client.inbox.setup.manual.instagram'), [])
            ->assertStatus(422)->assertJsonValidationErrors(['page_id', 'access_token']);

        $this->actingAs($user)->postJson(route('client.inbox.setup.manual.messenger'), [])
            ->assertStatus(422)->assertJsonValidationErrors(['page_id', 'access_token']);
    }

    public function test_guests_cannot_use_manual_endpoints(): void
    {
        $this->postJson(route('client.whatsapp.setup.manual'), [])->assertStatus(401);
        $this->postJson(route('client.inbox.setup.manual.instagram'), [])->assertStatus(401);
        $this->postJson(route('client.inbox.setup.manual.messenger'), [])->assertStatus(401);
    }
}
