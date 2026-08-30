<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Jobs\RefreshSocialTokensJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function makeAccount(array $attrs = []): SocialAccount
    {
        return SocialAccount::create(array_merge([
            'workspace_id' => $this->ctx['workspace']->id,
            'network' => 'twitter',
            'account_id' => 'acct_'.fake()->unique()->numerify('######'),
            'name' => 'Test Account',
            'access_token' => 'old-token',
            'refresh_token' => 'refresh-abc',
            'token_expires_at' => now()->addMinutes(10),
            'active' => true,
        ], $attrs));
    }

    /* ───────────────────────── Refresh job ───────────────────────── */

    public function test_token_expiring_soon_is_refreshed(): void
    {
        $account = $this->makeAccount();
        $this->mock(OAuthManager::class, function ($mock) {
            $mock->shouldReceive('refresh')
                ->once()
                ->with('twitter', 'refresh-abc')
                ->andReturn(['access_token' => 'new-token', 'refresh_token' => 'refresh-new', 'expires_in' => 7200]);
        });

        (new RefreshSocialTokensJob)->handle(app(OAuthManager::class));

        $account->refresh();
        $this->assertSame('new-token', $account->access_token);
        $this->assertSame('refresh-new', $account->refresh_token);
        $this->assertTrue($account->token_expires_at->gt(now()->addHour()));
    }

    public function test_already_expired_token_is_still_refreshed(): void
    {
        // Refresh tokens outlive access tokens — an already-expired access
        // token must not be skipped (this is the state most Twitter/Google
        // accounts were stuck in with the old daily-only schedule).
        $account = $this->makeAccount(['token_expires_at' => now()->subHours(20)]);
        $this->mock(OAuthManager::class, function ($mock) {
            $mock->shouldReceive('refresh')
                ->once()
                ->andReturn(['access_token' => 'new-token', 'expires_in' => 7200]);
        });

        (new RefreshSocialTokensJob)->handle(app(OAuthManager::class));

        $this->assertSame('new-token', $account->fresh()->access_token);
    }

    public function test_token_with_distant_expiry_is_not_touched(): void
    {
        $this->makeAccount(['network' => 'linkedin', 'token_expires_at' => now()->addDays(30)]);
        $this->mock(OAuthManager::class, function ($mock) {
            $mock->shouldReceive('refresh')->never();
        });

        (new RefreshSocialTokensJob)->handle(app(OAuthManager::class));
    }

    public function test_transient_refresh_failure_keeps_account_active(): void
    {
        $account = $this->makeAccount();
        $this->mock(OAuthManager::class, function ($mock) {
            $mock->shouldReceive('refresh')
                ->once()
                ->andThrow(new \RuntimeException('cURL error 28: Connection timed out'));
        });

        (new RefreshSocialTokensJob)->handle(app(OAuthManager::class));

        $this->assertTrue($account->fresh()->active, 'Network blip must not deactivate the account.');
    }

    public function test_dead_refresh_token_deactivates_account(): void
    {
        $account = $this->makeAccount();
        $this->mock(OAuthManager::class, function ($mock) {
            $mock->shouldReceive('refresh')
                ->once()
                ->andThrow(new \RuntimeException('Twitter token refresh failed: {"error":"invalid_grant"}'));
        });

        (new RefreshSocialTokensJob)->handle(app(OAuthManager::class));

        $this->assertFalse($account->fresh()->active);
    }

    /* ───────────────────────── needs_reconnect ───────────────────────── */

    public function test_needs_reconnect_flag_logic(): void
    {
        $fresh = $this->makeAccount();
        $expiredWithRefresh = $this->makeAccount(['token_expires_at' => now()->subHour()]);
        $expiredNoRefresh = $this->makeAccount(['token_expires_at' => now()->subHour(), 'refresh_token' => null]);
        $deactivated = $this->makeAccount(['active' => false]);
        $metaNoExpiry = $this->makeAccount(['network' => 'facebook', 'token_expires_at' => null, 'refresh_token' => null]);

        $this->assertFalse($fresh->needs_reconnect);
        $this->assertFalse($expiredWithRefresh->needs_reconnect, 'Auto-refreshable token must not demand reconnect.');
        $this->assertTrue($expiredNoRefresh->needs_reconnect);
        $this->assertTrue($deactivated->needs_reconnect);
        $this->assertFalse($metaNoExpiry->needs_reconnect);
    }

    public function test_accounts_index_exposes_needs_reconnect(): void
    {
        $this->makeAccount(['token_expires_at' => now()->subHour()]);

        $response = $this->actingAs($this->ctx['user'])->get(route('client.social.accounts.index'));

        $response->assertOk();
        $accounts = $response->viewData('page')['props']['accounts'];
        $this->assertArrayHasKey('needs_reconnect', $accounts[0]);
        $this->assertFalse($accounts[0]['needs_reconnect']);
    }
}
