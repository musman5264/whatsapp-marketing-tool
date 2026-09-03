<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Services\MetaWebhookSubscriptionService;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\EngineManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class InboxSetupController extends Controller
{
    public function __construct(private MetaWebhookSubscriptionService $webhooks) {}

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        // WhatsApp WABAs
        $wabas = WhatsappBusinessAccount::where('workspace_id', $workspaceId)
            ->with('phoneNumbers')
            ->get();

        $whatsappChannelAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'whatsapp')
            ->whereNotNull('phone_number_id')
            ->get(['id', 'phone_number_id', 'display_name', 'status', 'meta_json', 'business_account_id']);

        $webhookTokensByWaba = [];
        $channelAccountPhoneIdsByWaba = [];
        $channelAccountsByWaba = [];
        foreach ($wabas as $waba) {
            $webhookTokensByWaba[$waba->id] = $waba->makeVisible('webhook_verify_token')->webhook_verify_token;
            $accounts = $whatsappChannelAccounts->where('business_account_id', $waba->waba_id)->values();
            $channelAccountPhoneIdsByWaba[$waba->id] = $accounts->pluck('phone_number_id')->all();
            $channelAccountsByWaba[$waba->id] = $accounts->map(fn ($a) => [
                'id' => $a->id,
                'phone_number_id' => $a->phone_number_id,
                'display_name' => $a->display_name,
                'status' => $a->status,
                'ai_chatbot_id' => $a->meta_json['ai_chatbot_id'] ?? null,
            ])->all();
        }

        // Instagram / Messenger
        $instagramAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'instagram')
            ->get(['id', 'display_name', 'status', 'meta_json', 'created_at'])
            ->map(fn ($a) => array_merge($a->toArray(), ['ai_chatbot_id' => $a->meta_json['ai_chatbot_id'] ?? null]));

        $messengerAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'messenger')
            ->get(['id', 'display_name', 'status', 'meta_json', 'created_at'])
            ->map(fn ($a) => array_merge($a->toArray(), ['ai_chatbot_id' => $a->meta_json['ai_chatbot_id'] ?? null]));

        $chatbots = AiChatbot::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->get(['id', 'name']);

        $meta = CredentialResolver::system()->meta();
        $metaWebhookUrl = $meta ? url('/webhooks/meta/'.$meta->verifyToken()) : null;

        $metaCreds = CredentialResolver::system()->meta();

        // WhatsApp Web (QR / personal number, unofficial engine)
        $whatsappWebEnabled = app(EngineManager::class)->enabled();
        $webSession = WhatsappWebSession::where('workspace_id', $workspaceId)->first();

        return Inertia::render('Inbox/Setup', [
            'wabas' => $wabas,
            'whatsappWebEnabled' => $whatsappWebEnabled,
            'whatsappWebSession' => $webSession ? [
                'status' => $webSession->status,
                'phone_e164' => $webSession->phone_e164,
                'push_name' => $webSession->push_name,
                'settings' => $webSession->only(['auto_reject_calls', 'call_reject_message', 'send_receipts']),
            ] : null,
            'whatsappWebhookUrl' => url('/webhooks/whatsapp'),
            'whatsappWebhookGlobalUrl' => route('webhooks.whatsapp.global.receive'),
            'webhookTokensByWaba' => $webhookTokensByWaba,
            'channelAccountPhoneIdsByWaba' => $channelAccountPhoneIdsByWaba,
            'channelAccountsByWaba' => $channelAccountsByWaba,
            'instagramAccounts' => $instagramAccounts,
            'messengerAccounts' => $messengerAccounts,
            'chatbots' => $chatbots,
            'metaWebhookUrl' => $metaWebhookUrl,
            'metaAppId' => $metaCreds?->appId() ?: null,
            'metaConfigIdWhatsapp' => $metaCreds?->configIdWhatsapp() ?: null,
            'metaConfigIdSocial' => $metaCreds?->configIdSocial() ?: null,
        ]);
    }

    public function embeddedSignupInstagram(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        if (! CredentialResolver::system()->meta()?->appId()) {
            return response()->json(['message' => 'Meta App credentials are not configured. Please ask your administrator to configure them in Admin → Integrations → Meta App.'], 422);
        }

        // Ensure the Meta App delivers `instagram` webhook events to our endpoint.
        // Without this app-level subscription Meta has no callback URL for the
        // instagram object, so inbound Instagram messages never reach the server.
        $warnings = [];
        $appSub = $this->webhooks->registerAppWebhook('instagram');
        if (! $appSub['ok']) {
            $warnings[] = 'Webhook registration with Meta failed — inbound messages will NOT arrive until it succeeds: '.$appSub['error'];
        }

        $accessToken = $this->exchangeCodeForToken($validated['code']);
        if (! $accessToken) {
            return response()->json(['message' => 'Failed to exchange authorization code with Meta.'], 422);
        }

        $longToken = $this->exchangeForLongLivedToken($accessToken);

        // Fetch pages the user manages with Instagram accounts connected
        $pagesRes = Http::withToken($longToken)
            ->get('https://graph.facebook.com/v20.0/me/accounts', [
                'fields' => 'id,name,access_token,instagram_business_account{id,name,username}',
                'limit' => 50,
            ]);

        if (! $pagesRes->successful()) {
            Log::warning('Instagram embedded signup: pages fetch failed', [
                'workspace_id' => $workspaceId,
                'response' => $pagesRes->json(),
            ]);

            return response()->json(['message' => 'Could not fetch your Facebook pages: '.($pagesRes->json('error.message') ?? 'unknown error')], 422);
        }

        $pages = $pagesRes->json('data', []);
        $connected = 0;

        Log::info('Instagram embedded signup: pages fetched', [
            'workspace_id' => $workspaceId,
            'page_count' => count($pages),
            'pages' => collect($pages)->map(fn ($p) => [
                'id' => $p['id'] ?? null,
                'name' => $p['name'] ?? null,
                'has_instagram' => isset($p['instagram_business_account']['id']),
            ])->all(),
        ]);

        foreach ($pages as $page) {
            $igAccount = $page['instagram_business_account'] ?? null;
            if (! $igAccount || empty($igAccount['id'])) {
                continue;
            }

            $pageToken = $page['access_token'] ?? $longToken;
            $pageId = (string) ($page['id'] ?? '');
            $igId = (string) $igAccount['id'];
            $name = $igAccount['username'] ?? $igAccount['name'] ?? $page['name'] ?? $igId;

            // Subscribe the Page to Instagram messaging webhooks. Without this Meta
            // never delivers inbound Instagram messages to our /webhooks/meta endpoint.
            $pageSub = $this->webhooks->subscribePage($pageId, $pageToken, 'instagram');
            if (! $pageSub['ok']) {
                $warnings[] = "Page {$pageId} could not be subscribed to messaging webhooks — inbound messages for it will NOT arrive: ".$pageSub['error'];
            }

            // Persisted shape mirrors InboxDemoSeeder + what InstagramDriver expects:
            // - credentials.instagram_account_id / access_token  → used by send()
            // - meta_json.instagram_page_id (= IG account id, matches webhook entry.id)
            // - meta_json.instagram_account_id / facebook_page_id → diagnostics + lookup
            $credentials = ['access_token' => $pageToken, 'instagram_account_id' => $igId];
            $metaJson = [
                'instagram_page_id' => $igId,
                'instagram_account_id' => $igId,
                'facebook_page_id' => $pageId,
            ];

            $alreadyExists = ChannelAccount::where('workspace_id', $workspaceId)
                ->where('channel', 'instagram')
                ->whereJsonContains('meta_json->instagram_page_id', $igId)
                ->exists();

            if ($alreadyExists) {
                // Update the token on re-connect (preserve any extra meta_json keys
                // such as an assigned ai_chatbot_id by merging rather than replacing).
                $existing = ChannelAccount::where('workspace_id', $workspaceId)
                    ->where('channel', 'instagram')
                    ->whereJsonContains('meta_json->instagram_page_id', $igId)
                    ->first();

                $existing?->update([
                    'credentials' => $credentials,
                    'meta_json' => array_merge($existing->meta_json ?? [], $metaJson),
                    'status' => 'active',
                ]);
            } else {
                ChannelAccount::create([
                    'workspace_id' => $workspaceId,
                    'channel' => 'instagram',
                    'provider' => 'meta',
                    'display_name' => mb_substr((string) $name, 0, 128),
                    'credentials' => $credentials,
                    'meta_json' => $metaJson,
                    'status' => 'active',
                ]);
            }

            Log::info('Instagram embedded signup: account connected', [
                'workspace_id' => $workspaceId,
                'facebook_page_id' => $pageId,
                'instagram_account_id' => $igId,
                'reconnect' => $alreadyExists,
            ]);

            $connected++;
        }

        if ($connected === 0) {
            $pageCount = count($pages);
            $message = $pageCount === 0
                ? 'No Facebook Pages were returned. Make sure you granted page access during authorization and your Meta App has the pages_show_list permission in its Social config.'
                : 'No Instagram Business accounts were found on your '.$pageCount.' authorized page(s). To fix this: (1) Go to Meta Business Suite → your Facebook Page → Linked Accounts → link your Instagram account. (2) Make sure your Instagram is a Professional (Business or Creator) account. (3) Ensure your Social Embedded Signup config includes the instagram_basic permission.';

            return response()->json(['message' => $message], 422);
        }

        return response()->json(['success' => true, 'connected' => $connected, 'warnings' => $warnings]);
    }

    public function embeddedSignupMessenger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        if (! CredentialResolver::system()->meta()?->appId()) {
            return response()->json(['message' => 'Meta App credentials are not configured. Please ask your administrator to configure them in Admin → Integrations → Meta App.'], 422);
        }

        // Ensure the Meta App delivers `page` (Messenger) webhook events to our
        // endpoint. Without this app-level subscription Meta has no callback URL for
        // the page object, so inbound Messenger messages never reach the server.
        $warnings = [];
        $appSub = $this->webhooks->registerAppWebhook('page');
        if (! $appSub['ok']) {
            $warnings[] = 'Webhook registration with Meta failed — inbound messages will NOT arrive until it succeeds: '.$appSub['error'];
        }

        $accessToken = $this->exchangeCodeForToken($validated['code']);
        if (! $accessToken) {
            return response()->json(['message' => 'Failed to exchange authorization code with Meta.'], 422);
        }

        $longToken = $this->exchangeForLongLivedToken($accessToken);

        $pagesRes = Http::withToken($longToken)
            ->get('https://graph.facebook.com/v20.0/me/accounts', [
                'fields' => 'id,name,access_token',
                'limit' => 50,
            ]);

        if (! $pagesRes->successful()) {
            Log::warning('Messenger embedded signup: pages fetch failed', [
                'workspace_id' => $workspaceId,
                'response' => $pagesRes->json(),
            ]);

            return response()->json(['message' => 'Could not fetch your Facebook pages: '.($pagesRes->json('error.message') ?? 'unknown error')], 422);
        }

        $pages = $pagesRes->json('data', []);
        $connected = 0;

        Log::info('Messenger embedded signup: pages fetched', [
            'workspace_id' => $workspaceId,
            'page_count' => count($pages),
            'pages' => collect($pages)->map(fn ($p) => [
                'id' => $p['id'] ?? null,
                'name' => $p['name'] ?? null,
            ])->all(),
        ]);

        foreach ($pages as $page) {
            $pageId = (string) ($page['id'] ?? '');
            $pageName = $page['name'] ?? $pageId;
            $pageToken = $page['access_token'] ?? null;

            if (! $pageId) {
                continue;
            }

            // A PAGE access token is mandatory: it is what authorises send() and the
            // User Profile API (name/picture). If /me/accounts didn't include one,
            // fetch it explicitly. Never fall back to the user token — a user token
            // cannot resolve page-scoped PSIDs and yields Graph error 100.
            if (! $pageToken) {
                $tokenRes = Http::withToken($longToken)
                    ->get("https://graph.facebook.com/v20.0/{$pageId}", ['fields' => 'access_token']);
                $pageToken = $tokenRes->json('access_token');
            }

            if (! $pageToken) {
                Log::warning('Messenger embedded signup: no page access token — page skipped', [
                    'workspace_id' => $workspaceId,
                    'page_id' => $pageId,
                    'page_name' => $pageName,
                ]);

                continue;
            }

            // Subscribe the page to Messenger webhooks
            $pageSub = $this->webhooks->subscribePage($pageId, $pageToken, 'page');
            if (! $pageSub['ok']) {
                $warnings[] = "Page {$pageId} ({$pageName}) could not be subscribed to messaging webhooks — inbound messages for it will NOT arrive: ".$pageSub['error'];
            }

            $existing = ChannelAccount::where('workspace_id', $workspaceId)
                ->where('channel', 'messenger')
                ->whereJsonContains('meta_json->page_id', $pageId)
                ->first();
            $alreadyExists = $existing !== null;

            if ($existing) {
                // Update through the model instance (NOT the query builder) so the
                // `encrypted:array` cast runs and the page token is stored encrypted.
                // A query-builder update() bypasses casts and corrupts credentials,
                // which then fail to decrypt — breaking send() and the profile fetch.
                // Merge meta_json so an assigned ai_chatbot_id is preserved.
                $existing->update([
                    'credentials' => ['page_access_token' => $pageToken],
                    'meta_json' => array_merge($existing->meta_json ?? [], ['page_id' => $pageId]),
                    'status' => 'active',
                ]);
            } else {
                ChannelAccount::create([
                    'workspace_id' => $workspaceId,
                    'channel' => 'messenger',
                    'provider' => 'meta',
                    'display_name' => mb_substr((string) $pageName, 0, 128),
                    'credentials' => ['page_access_token' => $pageToken],
                    'meta_json' => ['page_id' => $pageId],
                    'status' => 'active',
                ]);
            }

            Log::info('Messenger embedded signup: account connected', [
                'workspace_id' => $workspaceId,
                'page_id' => $pageId,
                'reconnect' => $alreadyExists,
            ]);

            $connected++;
        }

        if ($connected === 0) {
            return response()->json([
                'message' => 'No Facebook Pages found on your account. Make sure you manage at least one Facebook Page.',
            ], 422);
        }

        return response()->json(['success' => true, 'connected' => $connected, 'warnings' => $warnings]);
    }

    /**
     * Manually connect an Instagram Business account using a Page ID and an
     * access token the user pastes from their own Meta App. Mirrors the
     * embedded-signup flow but validates a single page instead of /me/accounts.
     */
    public function manualInstagram(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page_id' => ['required', 'string', 'max:64'],
            'access_token' => ['required', 'string', 'max:1024'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $pageId = trim($validated['page_id']);
        $token = $this->exchangeForLongLivedToken(trim($validated['access_token']));

        $pageRes = Http::withToken($token)
            ->get("https://graph.facebook.com/v20.0/{$pageId}", [
                'fields' => 'id,name,access_token,instagram_business_account{id,name,username}',
            ]);

        if (! $pageRes->successful()) {
            Log::warning('Instagram manual connect: page fetch failed', [
                'workspace_id' => $workspaceId,
                'page_id' => $pageId,
                'response' => $pageRes->json(),
            ]);

            return response()->json([
                'message' => 'Could not fetch the Facebook Page: '.($pageRes->json('error.message') ?? 'unknown error')
                    .' — check the Page ID and that the token has the pages_show_list, instagram_basic and instagram_manage_messages permissions.',
            ], 422);
        }

        $page = $pageRes->json();
        $igAccount = $page['instagram_business_account'] ?? null;

        if (! $igAccount || empty($igAccount['id'])) {
            return response()->json([
                'message' => 'No Instagram Business account is linked to this Facebook Page. Link your Instagram professional account to the Page in Meta Business Suite → Linked Accounts, then try again.',
            ], 422);
        }

        $pageToken = $page['access_token'] ?? $token;
        $igId = (string) $igAccount['id'];
        $name = $igAccount['username'] ?? $igAccount['name'] ?? $page['name'] ?? $igId;

        // Webhook wiring — same as the embedded flow, but failures are surfaced
        // to the user as warnings instead of only being logged.
        $warnings = [];
        $appSub = $this->webhooks->registerAppWebhook('instagram');
        if (! $appSub['ok']) {
            $warnings[] = 'Webhook registration with Meta failed — inbound messages will NOT arrive until it succeeds: '.$appSub['error'];
        }
        $pageSub = $this->webhooks->subscribePage($pageId, $pageToken, 'instagram');
        if (! $pageSub['ok']) {
            $warnings[] = "Page {$pageId} could not be subscribed to messaging webhooks — inbound messages for it will NOT arrive: ".$pageSub['error'];
        }

        $credentials = ['access_token' => $pageToken, 'instagram_account_id' => $igId];
        $metaJson = [
            'instagram_page_id' => $igId,
            'instagram_account_id' => $igId,
            'facebook_page_id' => $pageId,
            'connected_via' => 'manual',
        ];

        $existing = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'instagram')
            ->whereJsonContains('meta_json->instagram_page_id', $igId)
            ->first();

        if ($existing) {
            $existing->update([
                'credentials' => $credentials,
                'meta_json' => array_merge($existing->meta_json ?? [], $metaJson),
                'status' => 'active',
            ]);
        } else {
            ChannelAccount::create([
                'workspace_id' => $workspaceId,
                'channel' => 'instagram',
                'provider' => 'meta',
                'display_name' => mb_substr((string) $name, 0, 128),
                'credentials' => $credentials,
                'meta_json' => $metaJson,
                'status' => 'active',
            ]);
        }

        Log::info('Instagram manual connect: account connected', [
            'workspace_id' => $workspaceId,
            'facebook_page_id' => $pageId,
            'instagram_account_id' => $igId,
            'reconnect' => $existing !== null,
        ]);

        return response()->json(['success' => true, 'connected' => 1, 'name' => $name, 'warnings' => $warnings]);
    }

    /**
     * Manually connect a Facebook Page for Messenger using a Page ID and an
     * access token the user pastes from their own Meta App.
     */
    public function manualMessenger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page_id' => ['required', 'string', 'max:64'],
            'access_token' => ['required', 'string', 'max:1024'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $pageId = trim($validated['page_id']);
        $token = $this->exchangeForLongLivedToken(trim($validated['access_token']));

        $pageRes = Http::withToken($token)
            ->get("https://graph.facebook.com/v20.0/{$pageId}", [
                'fields' => 'id,name,access_token',
            ]);

        if (! $pageRes->successful()) {
            Log::warning('Messenger manual connect: page fetch failed', [
                'workspace_id' => $workspaceId,
                'page_id' => $pageId,
                'response' => $pageRes->json(),
            ]);

            return response()->json([
                'message' => 'Could not fetch the Facebook Page: '.($pageRes->json('error.message') ?? 'unknown error')
                    .' — check the Page ID and that the token has the pages_show_list and pages_messaging permissions.',
            ], 422);
        }

        $page = $pageRes->json();
        $pageName = $page['name'] ?? $pageId;
        $pageToken = $page['access_token'] ?? null;

        // A PAGE access token is mandatory for send() and the User Profile API.
        // If the page node didn't expose one, the pasted token may itself be a
        // Page token — accept it only when /me resolves to this page.
        if (! $pageToken) {
            $meRes = Http::withToken($token)
                ->get('https://graph.facebook.com/v20.0/me', ['fields' => 'id']);
            if ($meRes->successful() && (string) $meRes->json('id') === $pageId) {
                $pageToken = $token;
            }
        }

        if (! $pageToken) {
            return response()->json([
                'message' => 'The token provided is not a Page Access Token for this Page and no Page token could be derived from it. Generate a Page Access Token for this Page and try again.',
            ], 422);
        }

        // Webhook wiring — same as the embedded flow, but failures are surfaced
        // to the user as warnings instead of only being logged.
        $warnings = [];
        $appSub = $this->webhooks->registerAppWebhook('page');
        if (! $appSub['ok']) {
            $warnings[] = 'Webhook registration with Meta failed — inbound messages will NOT arrive until it succeeds: '.$appSub['error'];
        }
        $pageSub = $this->webhooks->subscribePage($pageId, $pageToken, 'page');
        if (! $pageSub['ok']) {
            $warnings[] = "Page {$pageId} could not be subscribed to messaging webhooks — inbound messages for it will NOT arrive: ".$pageSub['error'];
        }

        $existing = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'messenger')
            ->whereJsonContains('meta_json->page_id', $pageId)
            ->first();

        if ($existing) {
            // Update through the model instance so the encrypted:array cast runs.
            $existing->update([
                'credentials' => ['page_access_token' => $pageToken],
                'meta_json' => array_merge($existing->meta_json ?? [], ['page_id' => $pageId, 'connected_via' => 'manual']),
                'status' => 'active',
            ]);
        } else {
            ChannelAccount::create([
                'workspace_id' => $workspaceId,
                'channel' => 'messenger',
                'provider' => 'meta',
                'display_name' => mb_substr((string) $pageName, 0, 128),
                'credentials' => ['page_access_token' => $pageToken],
                'meta_json' => ['page_id' => $pageId, 'connected_via' => 'manual'],
                'status' => 'active',
            ]);
        }

        Log::info('Messenger manual connect: account connected', [
            'workspace_id' => $workspaceId,
            'page_id' => $pageId,
            'reconnect' => $existing !== null,
        ]);

        return response()->json(['success' => true, 'connected' => 1, 'name' => $pageName, 'warnings' => $warnings]);
    }

    /**
     * Diagnose webhook wiring for a connected Instagram/Messenger account:
     * app-level subscription, page-level subscription, queue backlog and the
     * last stored inbound message. Read-only.
     */
    public function webhookHealth(Request $request, ChannelAccount $channelAccount): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $channelAccount->workspace_id === (int) $workspaceId, 403);
        abort_unless(in_array($channelAccount->channel, ['instagram', 'messenger'], true), 403);

        return response()->json($this->webhooks->health($channelAccount));
    }

    /**
     * Re-run the app-level and page-level webhook subscriptions for an
     * already-connected account. Repairs accounts connected by older versions
     * (which never subscribed the page) and callback-URL changes.
     */
    public function resubscribeWebhooks(Request $request, ChannelAccount $channelAccount): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $channelAccount->workspace_id === (int) $workspaceId, 403);
        abort_unless(in_array($channelAccount->channel, ['instagram', 'messenger'], true), 403);

        return response()->json($this->webhooks->resubscribe($channelAccount));
    }

    private function exchangeCodeForToken(string $code): ?string
    {
        $meta = CredentialResolver::system()->meta();
        if (! $meta?->appId() || ! $meta?->appSecret()) {
            return null;
        }

        $res = Http::get('https://graph.facebook.com/v20.0/oauth/access_token', [
            'client_id' => $meta->appId(),
            'client_secret' => $meta->appSecret(),
            'code' => $code,
            'redirect_uri' => '',
        ]);

        if (! $res->successful() || ! $res->json('access_token')) {
            Log::warning('Meta embedded signup: code exchange failed', [
                'response' => $res->json(),
            ]);

            return null;
        }

        return $res->json('access_token');
    }

    private function exchangeForLongLivedToken(string $shortToken): string
    {
        $meta = CredentialResolver::system()->meta();
        if (! $meta?->appId() || ! $meta?->appSecret()) {
            return $shortToken;
        }

        $res = Http::get('https://graph.facebook.com/v20.0/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $meta->appId(),
            'client_secret' => $meta->appSecret(),
            'fb_exchange_token' => $shortToken,
        ]);

        return ($res->successful() && $res->json('access_token')) ? $res->json('access_token') : $shortToken;
    }

    public function assignChatbot(Request $request, ChannelAccount $channelAccount): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $channelAccount->workspace_id === (int) $workspaceId, 403);

        $validated = $request->validate([
            'chatbot_id' => ['nullable', 'integer'],
        ]);

        $chatbotId = $validated['chatbot_id'] ?? null;

        if ($chatbotId !== null) {
            $exists = AiChatbot::where('id', $chatbotId)
                ->where('workspace_id', $workspaceId)
                ->where('enabled', true)
                ->exists();
            abort_unless($exists, 422, 'Chatbot not found or not enabled.');
        }

        $meta = $channelAccount->meta_json ?? [];
        if ($chatbotId === null) {
            unset($meta['ai_chatbot_id']);
        } else {
            $meta['ai_chatbot_id'] = $chatbotId;
        }
        $channelAccount->update(['meta_json' => $meta]);

        $label = $chatbotId ? 'Chatbot assigned.' : 'Chatbot removed.';

        return back()->with('success', $label);
    }

    public function destroy(Request $request, ChannelAccount $channelAccount): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        abort_unless((int) $channelAccount->workspace_id === (int) $workspaceId, 403);
        abort_unless(in_array($channelAccount->channel, ['instagram', 'messenger'], true), 403);

        $channelAccount->delete();

        return back()->with('success', 'Account disconnected.');
    }
}
