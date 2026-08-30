<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManualSocialAccountConnectTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function manualConnect(string $network, array $payload)
    {
        return $this->actingAs($this->ctx['user'])
            ->post(route('client.social.accounts.manual', $network), $payload);
    }

    /* ─────────────────── Facebook / Instagram (Page-based) ─────────────────── */

    public function test_facebook_manual_connect_stores_page_with_page_token(): void
    {
        Http::fake([
            'graph.facebook.com/v19.0/555000*' => Http::response([
                'id'           => '555000',
                'name'         => 'Test Page',
                'access_token' => 'PAGE_TOKEN_ABC',
                'picture'      => ['data' => ['url' => 'https://example.com/p.png']],
            ]),
        ]);

        $response = $this->manualConnect('facebook', [
            'page_id'      => '555000',
            'access_token' => 'USER_TOKEN',
        ]);

        $response->assertRedirect(route('client.social.accounts.index'));
        $response->assertSessionHas('success');

        $account = SocialAccount::where('workspace_id', $this->ctx['workspace']->id)
            ->where('network', 'facebook')
            ->first();
        $this->assertNotNull($account);
        $this->assertSame('555000', $account->account_id);
        $this->assertSame('Test Page', $account->name);
        // page token preferred over the pasted token; encrypted cast round-trips
        $this->assertSame('PAGE_TOKEN_ABC', $account->access_token);
        $this->assertTrue($account->active);
    }

    public function test_facebook_manual_connect_surfaces_graph_error(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.'],
            ], 401),
        ]);

        $response = $this->manualConnect('facebook', [
            'page_id'      => '555000',
            'access_token' => 'BAD_TOKEN',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Invalid OAuth access token', session('error'));
        $this->assertDatabaseCount('social_media_accounts', 0);
    }

    public function test_instagram_manual_connect_stores_linked_ig_account(): void
    {
        Http::fake([
            'graph.facebook.com/v19.0/555000*' => Http::response([
                'id'                         => '555000',
                'name'                       => 'Test Page',
                'access_token'               => 'PAGE_TOKEN_ABC',
                'instagram_business_account' => [
                    'id'                  => '17840000000',
                    'username'            => 'testbiz',
                    'profile_picture_url' => 'https://example.com/ig.png',
                ],
            ]),
        ]);

        $response = $this->manualConnect('instagram', [
            'page_id'      => '555000',
            'access_token' => 'USER_TOKEN',
        ]);

        $response->assertRedirect(route('client.social.accounts.index'));
        $response->assertSessionHas('success');

        $account = SocialAccount::where('network', 'instagram')->first();
        $this->assertNotNull($account);
        $this->assertSame('17840000000', $account->account_id);
        $this->assertSame('@testbiz', $account->name);
        $this->assertSame('PAGE_TOKEN_ABC', $account->access_token);
    }

    public function test_instagram_manual_connect_requires_linked_ig_account(): void
    {
        Http::fake([
            'graph.facebook.com/v19.0/555000*' => Http::response([
                'id'   => '555000',
                'name' => 'Test Page',
            ]),
        ]);

        $response = $this->manualConnect('instagram', [
            'page_id'      => '555000',
            'access_token' => 'USER_TOKEN',
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('No Instagram Business account', session('error'));
        $this->assertDatabaseCount('social_media_accounts', 0);
    }

    /* ─────────────────── Token-only networks ─────────────────── */

    public function test_twitter_manual_connect_stores_account_and_refresh_token(): void
    {
        Http::fake([
            'api.twitter.com/2/users/me*' => Http::response([
                'data' => ['id' => '42', 'name' => 'Test User', 'profile_image_url' => 'https://example.com/x.png'],
            ]),
        ]);

        $response = $this->manualConnect('twitter', [
            'access_token'  => 'X_TOKEN',
            'refresh_token' => 'X_REFRESH',
        ]);

        $response->assertRedirect(route('client.social.accounts.index'));
        $response->assertSessionHas('success');

        $account = SocialAccount::where('network', 'twitter')->first();
        $this->assertNotNull($account);
        $this->assertSame('42', $account->account_id);
        $this->assertSame('X_TOKEN', $account->access_token);
        $this->assertSame('X_REFRESH', $account->refresh_token);
    }

    public function test_twitter_manual_connect_with_configured_client_refreshes_immediately(): void
    {
        \App\Modules\Integrations\Models\IntegrationConfig::create([
            'provider'    => 'oauth_twitter',
            'label'       => 'X (Twitter) OAuth',
            'mode'        => 'live',
            'enabled'     => true,
            'credentials' => ['client_id' => 'cid', 'client_secret' => 'csec'],
        ]);

        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response([
                'access_token'  => 'FRESH_TOKEN',
                'refresh_token' => 'ROTATED_REFRESH',
                'expires_in'    => 7200,
            ]),
            'api.twitter.com/2/users/me*' => Http::response([
                'data' => ['id' => '42', 'name' => 'Test User'],
            ]),
        ]);

        $this->manualConnect('twitter', [
            'access_token'  => 'X_TOKEN',
            'refresh_token' => 'X_REFRESH',
        ])->assertSessionHas('success');

        // Immediate refresh replaced the pasted token, rotated the refresh token
        // and set a real expiry so RefreshSocialTokensJob keeps the account alive.
        $account = SocialAccount::where('network', 'twitter')->first();
        $this->assertSame('FRESH_TOKEN', $account->access_token);
        $this->assertSame('ROTATED_REFRESH', $account->refresh_token);
        $this->assertNotNull($account->token_expires_at);
        $this->assertTrue($account->token_expires_at->isFuture());
    }

    public function test_reconnect_without_refresh_token_keeps_existing_one(): void
    {
        Http::fake([
            'api.twitter.com/2/users/me*' => Http::response([
                'data' => ['id' => '42', 'name' => 'Test User'],
            ]),
        ]);

        SocialAccount::create([
            'workspace_id'  => $this->ctx['workspace']->id,
            'network'       => 'twitter',
            'account_id'    => '42',
            'name'          => 'Test User',
            'access_token'  => 'OLD_TOKEN',
            'refresh_token' => 'OLD_REFRESH',
            'active'        => true,
        ]);

        $this->manualConnect('twitter', ['access_token' => 'NEW_TOKEN'])
            ->assertSessionHas('success');

        $account = SocialAccount::where('network', 'twitter')->first();
        $this->assertSame('NEW_TOKEN', $account->access_token);
        $this->assertSame('OLD_REFRESH', $account->refresh_token);
        $this->assertDatabaseCount('social_media_accounts', 1);
    }

    public function test_invalid_token_rejected_for_token_only_network(): void
    {
        Http::fake([
            'api.twitter.com/*' => Http::response(['title' => 'Unauthorized'], 401),
        ]);

        $response = $this->manualConnect('twitter', ['access_token' => 'BAD']);

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('social_media_accounts', 0);
    }

    public function test_youtube_and_tiktok_manual_connect(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [['id' => 'UC123', 'snippet' => ['title' => 'My Channel', 'thumbnails' => ['default' => ['url' => 'https://example.com/yt.png']]]]],
            ]),
            'open.tiktokapis.com/v2/user/info*' => Http::response([
                'data' => ['user' => ['open_id' => 'tt-1', 'display_name' => 'TT User', 'avatar_url' => 'https://example.com/tt.png']],
            ]),
        ]);

        $this->manualConnect('youtube', ['access_token' => 'YT_TOKEN', 'refresh_token' => 'YT_REFRESH'])
            ->assertSessionHas('success');
        $this->manualConnect('tiktok', ['access_token' => 'TT_TOKEN'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('social_media_accounts', ['network' => 'youtube', 'account_id' => 'UC123']);
        $this->assertDatabaseHas('social_media_accounts', ['network' => 'tiktok', 'account_id' => 'tt-1']);
    }

    /* ─────────────────── Validation / guards ─────────────────── */

    public function test_page_id_required_for_meta_networks_and_token_always(): void
    {
        $this->manualConnect('facebook', ['access_token' => 'T'])
            ->assertSessionHasErrors(['page_id']);
        $this->manualConnect('twitter', [])
            ->assertSessionHasErrors(['access_token']);
    }

    public function test_unknown_network_is_404(): void
    {
        $this->manualConnect('myspace', ['access_token' => 'T'])->assertNotFound();
    }

    public function test_guests_cannot_manually_connect(): void
    {
        $this->post(route('client.social.accounts.manual', 'twitter'), ['access_token' => 'T'])
            ->assertRedirect(route('login'));
    }
}
