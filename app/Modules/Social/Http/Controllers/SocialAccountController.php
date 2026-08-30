<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\Drivers\TikTokDriver;
use App\Modules\Social\Services\Drivers\TwitterDriver;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

class SocialAccountController extends Controller
{
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

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $accounts = SocialAccount::where('workspace_id', $wid)->get()
            ->each->append('needs_reconnect');

        return Inertia::render('Social/Accounts/Index', ['accounts' => $accounts]);
    }

    public function connect(Request $request, string $network): RedirectResponse
    {
        $validNetworks = ['facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'tiktok'];
        abort_unless(in_array($network, $validNetworks, true), 404);

        Session::put('social_oauth_workspace', $this->workspaceId($request));

        $callbackUrl = route('client.social.oauth.callback', $network);

        try {
            $authUrl = $this->oauth->getAuthUrl($network, $this->workspaceId($request), $callbackUrl);
        } catch (\RuntimeException $e) {
            return redirect()->route('client.social.accounts.index')
                ->with('error', "OAuth for {$network} is not configured. Please contact your administrator.");
        }

        return redirect($authUrl);
    }

    /**
     * Manually connect a social account with a token the user pastes from their
     * own developer app — the alternative to the OAuth redirect flow. The token
     * is validated by fetching the account it belongs to before being stored.
     */
    public function manualConnect(Request $request, string $network): RedirectResponse
    {
        $validNetworks = ['facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'tiktok'];
        abort_unless(in_array($network, $validNetworks, true), 404);

        $isMetaPage = in_array($network, ['facebook', 'instagram'], true);

        $validated = $request->validate([
            'access_token'  => ['required', 'string', 'max:2048'],
            'refresh_token' => ['nullable', 'string', 'max:2048'],
            'page_id'       => [$isMetaPage ? 'required' : 'nullable', 'string', 'max:64'],
        ]);

        $wid   = $this->workspaceId($request);
        $token = trim($validated['access_token']);

        if ($isMetaPage) {
            return $this->manualConnectMetaPage($network, $wid, trim((string) $validated['page_id']), $token);
        }

        try {
            $info = $this->drivers[$network]->fetchAccountInfo($token);
        } catch (\Throwable $e) {
            Log::warning('Social manual connect: account info fetch threw', [
                'network'      => $network,
                'workspace_id' => $wid,
                'error'        => $e->getMessage(),
            ]);
            $info = ['account_id' => ''];
        }

        if (empty($info['account_id'])) {
            return back()->with('error', ucfirst($network).' rejected the token — could not fetch your account. Check that the token is valid and has the required scopes.');
        }

        $existing = SocialAccount::where('workspace_id', $wid)
            ->where('network', $network)
            ->where('account_id', $info['account_id'])
            ->first();

        // Keep a previously stored refresh token on re-connect when none is pasted.
        $pastedRefresh = trim((string) ($validated['refresh_token'] ?? ''));
        $refreshToken  = $pastedRefresh !== '' ? $pastedRefresh : $existing?->refresh_token;

        // With a refresh token, attempt an immediate refresh: success proves the
        // platform OAuth client can renew this token and yields the real expiry,
        // which RefreshSocialTokensJob needs to keep the account alive (it only
        // touches rows with a non-null token_expires_at). On failure keep the
        // pasted token with no expiry so the job never wrongly deactivates it.
        $expiresAt = null;
        if ($refreshToken !== null && $refreshToken !== '') {
            try {
                $refreshed = $this->oauth->refresh($network, $refreshToken);
                if (! empty($refreshed['access_token'])) {
                    $token        = $refreshed['access_token'];
                    $refreshToken = $refreshed['refresh_token'] ?? $refreshToken;
                    $expiresAt    = isset($refreshed['expires_in'])
                        ? now()->addSeconds((int) $refreshed['expires_in'])
                        : null;
                }
            } catch (\Throwable $e) {
                Log::info('Social manual connect: immediate token refresh unavailable — storing pasted token without expiry', [
                    'network'      => $network,
                    'workspace_id' => $wid,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        SocialAccount::updateOrCreate(
            ['workspace_id' => $wid, 'network' => $network, 'account_id' => $info['account_id']],
            [
                'name'             => $info['name'] !== '' ? $info['name'] : $info['account_id'],
                'picture_url'      => $info['picture_url'] ?? null,
                'access_token'     => $token,
                'refresh_token'    => $refreshToken,
                'token_expires_at' => $expiresAt,
                'active'           => true,
            ]
        );

        return redirect()->route('client.social.accounts.index')
            ->with('success', ucfirst($network).' account connected.');
    }

    /**
     * Manual connect for Facebook / Instagram: the user provides a Page ID plus
     * an access token (page token, or a user/system token that can read the
     * page). Mirrors the per-page upsert the OAuth callback performs, but for a
     * single page fetched directly instead of paginating /me/accounts.
     */
    private function manualConnectMetaPage(string $network, int $wid, string $pageId, string $token): RedirectResponse
    {
        $fields = $network === 'instagram'
            ? 'id,name,access_token,picture,instagram_business_account{id,name,username,profile_picture_url}'
            : 'id,name,access_token,picture';

        $res = Http::get("https://graph.facebook.com/v19.0/{$pageId}", [
            'access_token' => $token,
            'fields'       => $fields,
        ])->json();

        if (isset($res['error']) || empty($res['id'])) {
            $msg = $res['error']['message'] ?? 'Unknown Graph API error.';

            Log::warning('Social manual connect: page fetch failed', [
                'network'      => $network,
                'workspace_id' => $wid,
                'page_id'      => $pageId,
                'response'     => $res,
            ]);

            return back()->with('error', 'Could not fetch the Facebook Page: '.$msg
                .' Check the Page ID and that the token has the required permissions.');
        }

        // Prefer the page's own token (returned when the pasted token can manage
        // the page) — publishing requires a Page token.
        $pageToken = $res['access_token'] ?? $token;

        if ($network === 'instagram') {
            $ig = $res['instagram_business_account'] ?? null;

            if (! $ig || empty($ig['id'])) {
                return back()->with('error', 'No Instagram Business account is linked to this Facebook Page. Link your Instagram professional account to the Page in Meta Business Suite → Linked Accounts, then try again.');
            }

            $igName = ! empty($ig['username'])
                ? '@'.$ig['username']
                : ($ig['name'] ?? $res['name'] ?? $pageId);

            SocialAccount::updateOrCreate(
                ['workspace_id' => $wid, 'network' => 'instagram', 'account_id' => $ig['id']],
                [
                    'name'             => $igName,
                    'picture_url'      => $ig['profile_picture_url'] ?? ($res['picture']['data']['url'] ?? null),
                    'access_token'     => $pageToken,
                    'refresh_token'    => null,
                    'token_expires_at' => null,
                    'active'           => true,
                ]
            );

            return redirect()->route('client.social.accounts.index')
                ->with('success', 'Instagram account '.$igName.' connected.');
        }

        SocialAccount::updateOrCreate(
            ['workspace_id' => $wid, 'network' => 'facebook', 'account_id' => (string) $res['id']],
            [
                'name'             => $res['name'] ?? $pageId,
                'picture_url'      => $res['picture']['data']['url'] ?? null,
                'access_token'     => $pageToken,
                'refresh_token'    => null,
                'token_expires_at' => null,
                'active'           => true,
            ]
        );

        return redirect()->route('client.social.accounts.index')
            ->with('success', 'Facebook Page '.($res['name'] ?? $pageId).' connected.');
    }

    public function callback(Request $request, string $network): RedirectResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        $wid = Session::get('social_oauth_workspace', $this->workspaceId($request));
        $stored = Session::pull('social_oauth_state', []);

        if ($error || ! $code) {
            return redirect()->route('client.social.accounts.index')->with('error', 'OAuth failed: '.($error ?? 'No code received'));
        }

        // Verify state to prevent OAuth CSRF / account-linking hijack
        if (empty($stored['state']) || ! hash_equals($stored['state'], (string) $state)) {
            return redirect()->route('client.social.accounts.index')->with('error', 'Invalid OAuth state. Please try connecting again.');
        }

        $callbackUrl = route('client.social.oauth.callback', $network);
        $tokens = $this->oauth->exchangeCode($network, $code, $callbackUrl, $stored);

        if (empty($tokens['access_token'])) {
            return redirect()->route('client.social.accounts.index')->with('error', 'Failed to obtain access token.');
        }

        $driver = $this->drivers[$network] ?? null;
        $accountInfo = $driver ? $driver->fetchAccountInfo($tokens['access_token']) : ['account_id' => '', 'name' => '', 'picture_url' => null];

        // For Facebook / Instagram: fetch pages the user manages and upsert each one.
        if (in_array($network, ['facebook', 'instagram'])) {
            $fields = $network === 'instagram'
                ? 'id,name,access_token,picture,instagram_business_account{id,name,username,profile_picture_url}'
                : 'id,name,access_token,picture';

            $proof = $this->oauth->facebookAppSecretProof($tokens['access_token']);

            // /me/accounts is paginated — follow paging.next so users with many
            // Pages are not silently capped at the first batch.
            $pages = [];
            $url = 'https://graph.facebook.com/v19.0/me/accounts';
            $query = array_filter([
                'access_token' => $tokens['access_token'],
                'appsecret_proof' => $proof,
                'fields' => $fields,
                'limit' => 100,
            ]);

            for ($i = 0; $i < 5 && $url; $i++) {
                $pagesResp = Http::get($url, $query)->json();

                // Graph API returned an error — surface it to the user.
                if (isset($pagesResp['error'])) {
                    $msg = $pagesResp['error']['message'] ?? 'Unknown Graph API error.';

                    Log::warning('Social connect: /me/accounts error', [
                        'network' => $network,
                        'workspace_id' => $wid,
                        'response' => $pagesResp,
                    ]);

                    return redirect()->route('client.social.accounts.index')
                        ->with('error', 'Could not fetch your '.ucfirst($network).' pages: '.$msg);
                }

                $pages = array_merge($pages, $pagesResp['data'] ?? []);
                $url = $pagesResp['paging']['next'] ?? null;
                $query = []; // paging.next already carries all query params
            }

            if (empty($pages)) {
                return redirect()->route('client.social.accounts.index')
                    ->with('error', $this->diagnoseEmptyPages($network, $tokens['access_token'], $proof, $wid));
            }

            $connected = 0;

            foreach ($pages as $page) {
                if ($network === 'instagram') {
                    $igAccount = $page['instagram_business_account'] ?? null;
                    if (! $igAccount) {
                        // This page has no linked Instagram Business account — skip it.
                        continue;
                    }

                    $igName = ! empty($igAccount['username'])
                        ? '@'.$igAccount['username']
                        : ($igAccount['name'] ?? $page['name']);

                    SocialAccount::updateOrCreate(
                        ['workspace_id' => $wid, 'network' => 'instagram', 'account_id' => $igAccount['id']],
                        [
                            'name' => $igName,
                            'picture_url' => $igAccount['profile_picture_url'] ?? ($page['picture']['data']['url'] ?? null),
                            'access_token' => $page['access_token'], // page token is used for IG Graph API calls
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'active' => true,
                        ]
                    );
                } else {
                    SocialAccount::updateOrCreate(
                        ['workspace_id' => $wid, 'network' => 'facebook', 'account_id' => $page['id']],
                        [
                            'name' => $page['name'],
                            'picture_url' => $page['picture']['data']['url'] ?? null,
                            'access_token' => $page['access_token'],
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'active' => true,
                        ]
                    );
                }

                $connected++;
            }

            if ($connected === 0 && $network === 'instagram') {
                return redirect()->route('client.social.accounts.index')
                    ->with('error', 'No Instagram Business accounts were found linked to your Facebook Pages. Make sure your Instagram account is set to Business type and connected to a Facebook Page.');
            }

            return redirect()->route('client.social.accounts.index')
                ->with('success', $connected.' '.ucfirst($network).' account(s) connected.');
        }

        SocialAccount::updateOrCreate(
            ['workspace_id' => $wid, 'network' => $network, 'account_id' => $accountInfo['account_id']],
            [
                'name' => $accountInfo['name'],
                'picture_url' => $accountInfo['picture_url'],
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'token_expires_at' => isset($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
                'active' => true,
            ]
        );

        return redirect()->route('client.social.accounts.index')->with('success', ucfirst($network).' account connected.');
    }

    /**
     * /me/accounts returned no Pages — inspect the token's actual granted
     * permissions so the user gets an actionable error instead of a generic one.
     */
    private function diagnoseEmptyPages(string $network, string $accessToken, ?string $proof, int $wid): string
    {
        $required = $network === 'instagram'
            ? ['pages_show_list', 'instagram_basic', 'instagram_content_publish']
            : ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'];

        $perms = Http::get('https://graph.facebook.com/v19.0/me/permissions', array_filter([
            'access_token' => $accessToken,
            'appsecret_proof' => $proof,
        ]))->json('data') ?? [];

        $granted = collect($perms)->where('status', 'granted')->pluck('permission')->all();
        $missing = array_values(array_diff($required, $granted));

        Log::warning('Social connect: no pages returned by /me/accounts', [
            'network' => $network,
            'workspace_id' => $wid,
            'granted_permissions' => $granted,
            'missing_permissions' => $missing,
        ]);

        if ($missing) {
            return 'Facebook did not grant the required permission(s): '.implode(', ', $missing)
                .'. This usually means your Meta App does not have Advanced Access for them '
                .'(request it under App Review → Permissions and Features), your Facebook Login '
                .'for Business configuration is missing them, or they were unchecked during login. '
                .'Please reconnect and keep all requested permissions selected.';
        }

        return 'Facebook granted the permissions but returned no Pages. During login, on the '
            .'"What Pages do you want to use?" screen, make sure at least one Page is selected '
            .'(use "Edit access" to pick Pages), and that your account has full admin access to it. '
            .'If the problem persists, remove the app under Facebook Settings → Business integrations '
            .'and connect again.';
    }

    public function disconnect(Request $request, SocialAccount $account): RedirectResponse
    {
        abort_unless((int) $account->workspace_id === $this->workspaceId($request), 403);
        $account->delete();

        return back()->with('success', 'Account disconnected.');
    }
}
