<?php

namespace App\Modules\Social\Services;

use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Social\Exceptions\PermanentPublishException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\Drivers\SocialNetworkInterface;
use App\Modules\Social\Services\Drivers\TikTokDriver;
use App\Modules\Social\Services\Drivers\TwitterDriver;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Support\Facades\Log;

class SocialPublisher
{
    /** Networks whose tokens can be refreshed programmatically at publish time. */
    private const REFRESHABLE_NETWORKS = ['twitter', 'youtube', 'tiktok', 'linkedin'];

    /** @var array<string, SocialNetworkInterface> */
    private array $drivers;

    public function __construct(private readonly OAuthManager $oauth)
    {
        $this->drivers = [
            'facebook' => new FacebookDriver,
            'instagram' => new InstagramSocialDriver,
            'linkedin' => new LinkedInDriver,
            'twitter' => new TwitterDriver,
            'youtube' => new YoutubeDriver,
            'tiktok' => new TikTokDriver,
        ];
    }

    /**
     * Publish a post to all its target accounts.
     *
     * While retry attempts remain ($finalAttempt = false), a transient failure on
     * any account keeps the post in 'publishing' and throws, so the queue retries
     * the job with backoff; accounts whose link row is already 'published' are
     * skipped on the next run. Only on the final attempt (or full success) is the
     * post's status finalized.
     */
    public function publish(SocialPost $post, bool $finalAttempt = true): void
    {
        $post->update(['status' => 'publishing']);

        // Scope accounts to the post's own workspace to prevent cross-workspace publishing.
        $accounts = SocialAccount::where('workspace_id', $post->workspace_id)
            ->whereIn('id', $post->target_accounts ?? [])
            ->get();

        $results = [];
        $retryableFailures = 0;

        foreach ($accounts as $account) {
            $link = SocialPostAccount::firstOrCreate(
                ['post_id' => $post->id, 'social_account_id' => $account->id],
                ['status' => 'pending']
            );

            // On job retry, skip accounts already successfully published.
            if ($link->status === 'published') {
                $results[$account->id] = ['status' => 'published', 'post_id' => $link->platform_post_id];
                continue;
            }

            try {
                $this->assertAccountUsable($account);

                $driver = $this->drivers[$account->network]
                    ?? throw new PermanentPublishException("No driver for network {$account->network}.");

                $platformId = $driver->publish($account, $post->toArray());
                $link->update(['status' => 'published', 'platform_post_id' => $platformId, 'published_at' => now()]);
                $results[$account->id] = ['status' => 'published', 'post_id' => $platformId];
            } catch (PermanentPublishException $e) {
                // Retrying will not fix these — record the reason and move on.
                $link->update(['status' => 'failed', 'error' => $e->getMessage()]);
                $results[$account->id] = ['status' => 'failed', 'error' => $e->getMessage()];
            } catch (\Throwable $e) {
                // Transient (API error, timeout, rate limit) — full details go to the log.
                Log::error('Social publish failed', [
                    'post_id' => $post->id,
                    'account_id' => $account->id,
                    'network' => $account->network,
                    'error' => $e->getMessage(),
                ]);
                $link->update(['status' => 'failed', 'error' => 'Publish failed. See application logs for details.']);
                $results[$account->id] = ['status' => 'failed'];
                $retryableFailures++;
            }
        }

        // Transient failures with retries left: persist progress, keep the post in
        // 'publishing', and throw so the queue retries the job with backoff.
        if ($retryableFailures > 0 && ! $finalAttempt) {
            $post->update(['publish_results' => $results]);

            throw new \RuntimeException("{$retryableFailures} account(s) failed transiently; job will retry.");
        }

        $succeededCount = collect($results)->filter(fn ($r) => $r['status'] === 'published')->count();
        $allFailed = $succeededCount === 0;

        $post->update([
            'status' => $allFailed ? 'failed' : 'published', // partial success still marks published
            'published_at' => $allFailed ? null : now(),
            'publish_results' => $results,
        ]);

        if (! $allFailed) {
            UsageMeter::track($post->workspace_id, 'social_posts');
        }
    }

    /**
     * Fail fast (permanently) on disconnected accounts and expired tokens,
     * refreshing the token inline when the network supports it.
     */
    private function assertAccountUsable(SocialAccount $account): void
    {
        if (! $account->active) {
            throw new PermanentPublishException('Account is disconnected. Reconnect it from Social settings.');
        }

        if (! $account->isTokenExpired()) {
            return;
        }

        if (! in_array($account->network, self::REFRESHABLE_NETWORKS, true) || ! $account->refresh_token) {
            throw new PermanentPublishException('Access token has expired. Reconnect the account from Social settings.');
        }

        try {
            $refreshed = $this->oauth->refresh($account->network, $account->refresh_token);

            $account->update([
                'access_token' => $refreshed['access_token'],
                'refresh_token' => $refreshed['refresh_token'] ?? $account->refresh_token,
                'token_expires_at' => isset($refreshed['expires_in'])
                    ? now()->addSeconds((int) $refreshed['expires_in'])
                    : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Publish-time token refresh failed', [
                'network' => $account->network,
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            throw new PermanentPublishException('Access token expired and could not be refreshed. Reconnect the account from Social settings.');
        }
    }
}
