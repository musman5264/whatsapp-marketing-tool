<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshSocialTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * OAuth error markers that mean the refresh token itself is dead and the
     * user must reconnect. Anything else (network blip, provider 5xx) is
     * transient — keep the account active and retry on the next run.
     */
    private const PERMANENT_ERROR_MARKERS = ['invalid_grant', 'invalid_request', 'invalid_client', 'unauthorized'];

    public function handle(OAuthManager $oauthManager): void
    {
        // Networks that support programmatic refresh
        $refreshable = ['twitter', 'youtube', 'tiktok', 'linkedin'];

        // Runs every 15 minutes; refresh anything expiring within the next 45
        // (or already expired — refresh tokens outlive access tokens). Twitter
        // and Google access tokens last only 1-2 hours, so a daily pass leaves
        // them expired most of the day.
        SocialAccount::where('active', true)
            ->whereIn('network', $refreshable)
            ->where('token_expires_at', '<', now()->addMinutes(45))
            ->whereNotNull('refresh_token')
            ->chunkById(100, function ($accounts) use ($oauthManager) {
                foreach ($accounts as $account) {
                    try {
                        $refreshed = $oauthManager->refresh($account->network, $account->refresh_token);

                        $account->update([
                            'access_token'     => $refreshed['access_token'],
                            'refresh_token'    => $refreshed['refresh_token'] ?? $account->refresh_token,
                            'token_expires_at' => isset($refreshed['expires_in'])
                                ? now()->addSeconds((int) $refreshed['expires_in'])
                                : null,
                        ]);

                        Log::info('Social token refreshed', ['network' => $account->network, 'account_id' => $account->id]);
                    } catch (\Throwable $e) {
                        $permanent = $this->isPermanentFailure($e);

                        Log::warning('Social token refresh failed', [
                            'network'    => $account->network,
                            'account_id' => $account->id,
                            'permanent'  => $permanent,
                            'error'      => $e->getMessage(),
                        ]);

                        // Only a dead refresh token warrants deactivating the
                        // account; a transient failure will be retried in 15 min.
                        if ($permanent) {
                            $account->update(['active' => false]);
                        }
                    }
                }
            });
    }

    private function isPermanentFailure(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (self::PERMANENT_ERROR_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }
}
