<?php

namespace App\Modules\Social\Services\OAuth;

use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

/**
 * Centralized OAuth helper for all social networks.
 *
 * Each network's OAuth credentials (client_id, client_secret) are retrieved via
 * the CredentialResolver (workspace override → system default stored in integration_configs).
 *
 * Supported networks:
 *   facebook  – FB Login / Graph API
 *   instagram – Instagram Basic Display / Graph API (via FB App)
 *   linkedin  – LinkedIn OAuth 2.0
 *   twitter   – X/Twitter OAuth 2.0 PKCE
 *   youtube   – Google OAuth 2.0
 *   tiktok    – TikTok Content Posting API OAuth 2.0
 */
class OAuthManager
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    public function getAuthUrl(string $network, int $workspaceId, string $callbackUrl): string
    {
        $creds = $this->credentials->oauth($network);

        if ($creds === null) {
            throw new \RuntimeException("OAuth credentials for [{$network}] are not configured or disabled.");
        }

        return match ($network) {
            'facebook', 'instagram' => $this->facebookAuthUrl($creds, $callbackUrl, $network),
            'linkedin' => $this->linkedinAuthUrl($creds, $callbackUrl),
            'twitter' => $this->twitterAuthUrl($creds, $callbackUrl),
            'youtube' => $this->googleAuthUrl($creds, $callbackUrl),
            'tiktok' => $this->tiktokAuthUrl($creds, $callbackUrl),
            default => throw new \InvalidArgumentException("Unsupported network: {$network}"),
        };
    }

    /**
     * @param  array  $storedState  The already-validated session state data (passed in by the controller
     *                              after it verified the `state` query param — avoids re-reading the session).
     */
    public function exchangeCode(string $network, string $code, string $callbackUrl, array $storedState = []): array
    {
        $creds = $this->credentials->oauth($network);

        if ($creds === null) {
            throw new \RuntimeException("OAuth credentials for [{$network}] are not configured or disabled.");
        }

        return match ($network) {
            'facebook', 'instagram' => $this->facebookExchange($creds, $code, $callbackUrl),
            'linkedin' => $this->linkedinExchange($creds, $code, $callbackUrl),
            'twitter' => $this->twitterExchange($creds, $code, $callbackUrl, $storedState),
            'youtube' => $this->googleExchange($creds, $code, $callbackUrl),
            'tiktok' => $this->tiktokExchange($creds, $code, $callbackUrl),
            default => throw new \InvalidArgumentException("Unsupported network: {$network}"),
        };
    }

    /**
     * Refresh an access token using a refresh_token.
     * Returns ['access_token', 'refresh_token' (if rotated), 'expires_in'] or throws on failure.
     */
    public function refresh(string $network, string $refreshToken): array
    {
        $creds = $this->credentials->oauth($network);

        return match ($network) {
            'twitter' => $this->twitterRefresh($creds, $refreshToken),
            'youtube' => $this->googleRefresh($creds, $refreshToken),
            'tiktok' => $this->tiktokRefresh($creds, $refreshToken),
            'linkedin' => $this->linkedinRefresh($creds, $refreshToken),
            'facebook',
            'instagram' => throw new \RuntimeException('Facebook/Instagram tokens are long-lived; use token extension instead.'),
            default => throw new \InvalidArgumentException("Unsupported network for refresh: {$network}"),
        };
    }

    // ── Facebook / Instagram ────────────────────────────────────────────────

    private function facebookAuthUrl($creds, string $redirect, string $network): string
    {
        $state = $this->storeState(['network' => $network]);

        $params = [
            'client_id' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'state' => $state,
            'response_type' => 'code',
            // Re-prompt for permissions the user previously declined; without this
            // Facebook silently skips the consent screen on reconnect attempts.
            'auth_type' => 'rerequest',
        ];

        // Business-type Meta apps (required for WhatsApp embedded signup) only
        // support Facebook Login for Business: the scope parameter is ignored and a
        // Login Configuration (config_id) must be sent instead — otherwise the
        // dialog completes but grants no page permissions and /me/accounts comes
        // back empty. Fall back to classic scoped login for consumer-type apps.
        $configId = $this->credentials->meta()?->configIdSocial();

        if ($configId) {
            $params['config_id'] = $configId;
        } else {
            $params['scope'] = $network === 'instagram'
                ? 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement,business_management'
                : 'pages_manage_posts,pages_read_engagement,pages_show_list,business_management';
        }

        return 'https://www.facebook.com/v19.0/dialog/oauth?'.http_build_query($params);
    }

    private function facebookExchange($creds, string $code, string $redirect): array
    {
        $res = Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'redirect_uri' => $redirect,
            'code' => $code,
        ])->json();

        $token = $res['access_token'] ?? null;
        $expiresIn = $res['expires_in'] ?? null;

        // Upgrade to a long-lived user token (~60 days). Page tokens derived from a
        // short-lived user token expire within 1-2 hours, which would silently break
        // scheduled publishing shortly after connecting.
        if ($token) {
            $long = Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $creds->clientId() ?? '',
                'client_secret' => $creds->clientSecret() ?? '',
                'fb_exchange_token' => $token,
            ])->json();

            if (! empty($long['access_token'])) {
                $token = $long['access_token'];
                $expiresIn = $long['expires_in'] ?? $expiresIn;
            }
        }

        return [
            'access_token' => $token,
            'expires_in' => $expiresIn,
            'token_type' => $res['token_type'] ?? 'bearer',
        ];
    }

    /**
     * appsecret_proof for server-side Graph API calls — required when the Meta App
     * has "Require App Secret" enabled, recommended by Meta otherwise.
     */
    public function facebookAppSecretProof(string $accessToken): ?string
    {
        $secret = $this->credentials->oauth('facebook')?->clientSecret();

        return $secret ? hash_hmac('sha256', $accessToken, $secret) : null;
    }

    // ── LinkedIn ────────────────────────────────────────────────────────────

    private function linkedinAuthUrl($creds, string $redirect): string
    {
        $state = $this->storeState(['network' => 'linkedin']);

        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'scope' => 'openid profile email w_member_social',
            'state' => $state,
        ]);
    }

    private function linkedinExchange($creds, string $code, string $redirect): array
    {
        $res = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect,
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
        ])->json();

        return ['access_token' => $res['access_token'] ?? null, 'expires_in' => $res['expires_in'] ?? null];
    }

    // ── Twitter / X ─────────────────────────────────────────────────────────

    private function twitterAuthUrl($creds, string $redirect): string
    {
        // X OAuth 2.0 with PKCE S256
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(base64_encode(hash('sha256', $verifier, true)), '=');
        $challenge = strtr($challenge, '+/', '-_');
        $state = $this->storeState(['network' => 'twitter', 'verifier' => $verifier]);

        return 'https://twitter.com/i/oauth2/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'scope' => 'tweet.read tweet.write users.read offline.access',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    private function twitterExchange($creds, string $code, string $redirect, array $storedState = []): array
    {
        $verifier = $storedState['verifier'] ?? '';
        $res = Http::withBasicAuth($creds->clientId() ?? '', $creds->clientSecret() ?? '')
            ->asForm()->post('https://api.twitter.com/2/oauth2/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirect,
                'code_verifier' => $verifier,
            ])->json();

        return ['access_token' => $res['access_token'] ?? null, 'refresh_token' => $res['refresh_token'] ?? null, 'expires_in' => $res['expires_in'] ?? null];
    }

    // ── Google / YouTube ────────────────────────────────────────────────────

    private function googleAuthUrl($creds, string $redirect): string
    {
        $state = $this->storeState(['network' => 'youtube']);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly',
            'access_type' => 'offline',
            'state' => $state,
        ]);
    }

    private function googleExchange($creds, string $code, string $redirect): array
    {
        $res = Http::post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
        ])->json();

        return ['access_token' => $res['access_token'] ?? null, 'refresh_token' => $res['refresh_token'] ?? null, 'expires_in' => $res['expires_in'] ?? null];
    }

    // ── TikTok ──────────────────────────────────────────────────────────────

    private function tiktokAuthUrl($creds, string $redirect): string
    {
        $state = $this->storeState(['network' => 'tiktok']);

        return 'https://www.tiktok.com/v2/auth/authorize?'.http_build_query([
            'client_key' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'user.info.basic,video.publish',
            'state' => $state,
        ]);
    }

    private function tiktokExchange($creds, string $code, string $redirect): array
    {
        $res = Http::post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'grant_type' => 'authorization_code',
            'auth_code' => $code,
            'redirect_uri' => $redirect,
        ])->json();

        return ['access_token' => $res['data']['access_token'] ?? null, 'refresh_token' => $res['data']['refresh_token'] ?? null, 'expires_in' => $res['data']['expires_in'] ?? null];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function twitterRefresh($creds, string $refreshToken): array
    {
        $res = Http::withBasicAuth($creds->clientId() ?? '', $creds->clientSecret() ?? '')
            ->asForm()->post('https://api.twitter.com/2/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ])->json();

        if (empty($res['access_token'])) {
            throw new \RuntimeException('Twitter token refresh failed: '.json_encode($res));
        }

        return ['access_token' => $res['access_token'], 'refresh_token' => $res['refresh_token'] ?? $refreshToken, 'expires_in' => $res['expires_in'] ?? null];
    }

    private function googleRefresh($creds, string $refreshToken): array
    {
        $res = Http::post('https://oauth2.googleapis.com/token', [
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ])->json();

        if (empty($res['access_token'])) {
            throw new \RuntimeException('Google token refresh failed: '.json_encode($res));
        }

        return ['access_token' => $res['access_token'], 'refresh_token' => $refreshToken, 'expires_in' => $res['expires_in'] ?? 3600];
    }

    private function tiktokRefresh($creds, string $refreshToken): array
    {
        $res = Http::post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ])->json();

        $token = $res['data']['access_token'] ?? null;
        if (! $token) {
            throw new \RuntimeException('TikTok token refresh failed: '.json_encode($res));
        }

        return ['access_token' => $token, 'refresh_token' => $res['data']['refresh_token'] ?? $refreshToken, 'expires_in' => $res['data']['expires_in'] ?? null];
    }

    private function linkedinRefresh($creds, string $refreshToken): array
    {
        $res = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
        ])->json();

        if (empty($res['access_token'])) {
            throw new \RuntimeException('LinkedIn token refresh failed: '.json_encode($res));
        }

        return ['access_token' => $res['access_token'], 'refresh_token' => $res['refresh_token'] ?? $refreshToken, 'expires_in' => $res['expires_in'] ?? null];
    }

    private function storeState(array $data): string
    {
        $state = bin2hex(random_bytes(16));
        Session::put('social_oauth_state', array_merge($data, ['state' => $state]));

        return $state;
    }
}
