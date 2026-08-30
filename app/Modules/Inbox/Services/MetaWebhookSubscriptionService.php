<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Owns the two Meta webhook subscriptions an Instagram/Messenger channel needs
 * to receive inbound messages, plus the diagnostics for them:
 *
 *   1. App-level  – POST /{app-id}/subscriptions ties the `page` / `instagram`
 *      object to our /webhooks/meta/{verify_token} callback URL.
 *   2. Page-level – POST /{page-id}/subscribed_apps subscribes the specific
 *      Facebook Page (or the Page linked to the IG account) to the app.
 *
 * Either one missing means Meta silently delivers nothing — the account still
 * "connects" fine, which is exactly the "connected but no messages in the
 * inbox" support case. health() checks both plus the local queue pipeline;
 * resubscribe() re-runs both for an already-connected account.
 */
class MetaWebhookSubscriptionService
{
    private const BASE = 'https://graph.facebook.com/v20.0';

    private const APP_FIELDS = [
        'page' => 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads',
        'instagram' => 'messages,messaging_postbacks,message_reactions',
    ];

    private const PAGE_FIELDS = [
        'page' => 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads',
        'instagram' => 'messages,messaging_postbacks,message_reactions,message_reads',
    ];

    private const WORKER_HINT = 'php artisan queue:work --queue=ai,default,whatsapp,broadcast,social,leads,automation';

    /**
     * Register the app-level webhook subscription for the given object
     * ('page' for Messenger, 'instagram' for Instagram). Idempotent on Meta.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function registerAppWebhook(string $object): array
    {
        $meta = CredentialResolver::system()->meta();
        $appId = $meta?->appId();
        $appSecret = $meta?->appSecret();
        $verifyToken = $meta?->verifyToken();

        if (! $appId || ! $appSecret || ! $verifyToken) {
            Log::warning('Meta webhook: cannot register app subscription — missing app id/secret/verify token', [
                'object' => $object,
                'has_app_id' => (bool) $appId,
                'has_app_secret' => (bool) $appSecret,
                'has_verify_token' => (bool) $verifyToken,
            ]);

            return ['ok' => false, 'error' => 'Meta App ID, App Secret or Verify Token is not configured in Admin → Integrations → Meta App.'];
        }

        $callbackUrl = route('webhooks.meta.receive', ['token' => $verifyToken]);

        try {
            $res = Http::post(self::BASE."/{$appId}/subscriptions", [
                'access_token' => $appId.'|'.$appSecret,
                'object' => $object,
                'callback_url' => $callbackUrl,
                'verify_token' => $verifyToken,
                'fields' => self::APP_FIELDS[$object] ?? 'messages',
            ]);

            if (! $res->successful()) {
                Log::warning('Meta webhook: app subscription registration failed', [
                    'object' => $object,
                    'callback_url' => $callbackUrl,
                    'status' => $res->status(),
                    'response' => $res->json(),
                ]);

                return ['ok' => false, 'error' => (string) ($res->json('error.message') ?? 'Meta rejected the webhook registration. Make sure the app URL is public HTTPS.')];
            }

            Log::info('Meta webhook: app subscription registered', [
                'object' => $object,
                'callback_url' => $callbackUrl,
                'response' => $res->json(),
            ]);

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('Meta webhook: app subscription registration exception', [
                'object' => $object,
                'callback_url' => $callbackUrl,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Subscribe a Facebook Page to the app for the given object's messaging
     * fields. Required per page — without it Meta delivers no events for that
     * page (or its linked Instagram account).
     *
     * @return array{ok: bool, error: ?string}
     */
    public function subscribePage(string $pageId, string $pageToken, string $object): array
    {
        if ($pageId === '' || $pageToken === '') {
            return ['ok' => false, 'error' => 'Missing page id or page access token.'];
        }

        try {
            $res = Http::withToken($pageToken)
                ->post(self::BASE."/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => self::PAGE_FIELDS[$object] ?? 'messages',
                ]);

            if (! $res->successful()) {
                Log::warning('Meta webhook: page subscription failed', [
                    'object' => $object,
                    'page_id' => $pageId,
                    'status' => $res->status(),
                    'response' => $res->json(),
                ]);

                return ['ok' => false, 'error' => (string) ($res->json('error.message') ?? 'Meta rejected the page subscription.')];
            }

            Log::info('Meta webhook: page subscribed for messaging', [
                'object' => $object,
                'page_id' => $pageId,
                'response' => $res->json(),
            ]);

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('Meta webhook: page subscription exception', [
                'object' => $object,
                'page_id' => $pageId,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Re-run both subscriptions for an already-connected account. Fixes
     * accounts connected by older versions that never subscribed the page,
     * and re-registers the callback URL after an APP_URL / verify-token change.
     *
     * @return array{ok: bool, steps: array<int, array{key: string, ok: bool, detail: string}>}
     */
    public function resubscribe(ChannelAccount $account): array
    {
        $object = $account->channel === 'instagram' ? 'instagram' : 'page';
        $steps = [];

        $app = $this->registerAppWebhook($object);
        $steps[] = [
            'key' => 'app_subscription',
            'ok' => $app['ok'],
            'detail' => $app['ok'] ? 'App webhook subscription registered.' : (string) $app['error'],
        ];

        [$pageId, $pageToken, $pageError] = $this->resolvePageContext($account);

        if ($pageId === null) {
            $steps[] = ['key' => 'page_subscription', 'ok' => false, 'detail' => (string) $pageError];
        } else {
            $page = $this->subscribePage($pageId, $pageToken, $object);
            $steps[] = [
                'key' => 'page_subscription',
                'ok' => $page['ok'],
                'detail' => $page['ok'] ? "Page {$pageId} subscribed to messaging webhooks." : (string) $page['error'],
            ];
        }

        return ['ok' => collect($steps)->every(fn ($s) => $s['ok']), 'steps' => $steps];
    }

    /**
     * Diagnose why an account might not be receiving messages. Each check:
     * key + ok + severity (error|warning|info) + human-readable detail.
     *
     * @return array{ok: bool, checks: array<int, array{key: string, ok: bool, severity: string, detail: string}>}
     */
    public function health(ChannelAccount $account): array
    {
        $meta = CredentialResolver::system()->meta();
        $appId = $meta?->appId();
        $appSecret = $meta?->appSecret();
        $verifyToken = $meta?->verifyToken();
        $object = $account->channel === 'instagram' ? 'instagram' : 'page';

        $checks = [];

        // 1. Platform credentials
        $credsOk = $appId && $appSecret && $verifyToken;
        $checks[] = [
            'key' => 'app_credentials',
            'ok' => (bool) $credsOk,
            'severity' => 'error',
            'detail' => $credsOk
                ? 'Meta App credentials are configured.'
                : 'Meta App ID, App Secret or Verify Token missing — configure them in Admin → Integrations → Meta App.',
        ];

        // 2. App-level subscription (callback URL registered for this object)
        if ($credsOk) {
            $checks[] = $this->checkAppSubscription($appId, $appSecret, $verifyToken, $object);
        }

        // 3. Page-level subscription
        [$pageId, $pageToken, $pageError] = $this->resolvePageContext($account);
        if ($pageId === null) {
            $checks[] = ['key' => 'page_subscription', 'ok' => false, 'severity' => 'error', 'detail' => (string) $pageError];
        } else {
            $checks[] = $this->checkPageSubscription($pageId, $pageToken, (string) $appId);
        }

        // 4. Queue pipeline — inbound jobs land on the "whatsapp" queue; if they
        //    sit unreserved past 5 minutes no worker is consuming that queue.
        $checks[] = $this->checkQueueWorker();

        // 5. Last inbound message (informational)
        $lastIn = Message::where('direction', 'in')
            ->whereIn('conversation_id', $account->conversations()->select('id'))
            ->max('created_at');
        $checks[] = [
            'key' => 'last_inbound',
            'ok' => true,
            'severity' => 'info',
            'detail' => $lastIn
                ? 'Last inbound message received at '.$lastIn.'.'
                : 'No inbound message has ever been stored for this account.',
        ];

        $ok = collect($checks)->every(fn ($c) => $c['ok'] || $c['severity'] === 'info');

        return ['ok' => $ok, 'checks' => $checks];
    }

    /** @return array{key: string, ok: bool, severity: string, detail: string} */
    private function checkAppSubscription(string $appId, string $appSecret, string $verifyToken, string $object): array
    {
        $expectedUrl = route('webhooks.meta.receive', ['token' => $verifyToken]);

        try {
            $res = Http::get(self::BASE."/{$appId}/subscriptions", [
                'access_token' => $appId.'|'.$appSecret,
            ]);

            if (! $res->successful()) {
                return [
                    'key' => 'app_subscription',
                    'ok' => false,
                    'severity' => 'error',
                    'detail' => 'Could not read the app webhook subscriptions: '.($res->json('error.message') ?? 'unknown error'),
                ];
            }

            $entry = collect($res->json('data', []))->firstWhere('object', $object);

            if (! $entry) {
                return [
                    'key' => 'app_subscription',
                    'ok' => false,
                    'severity' => 'error',
                    'detail' => "No '{$object}' webhook subscription exists on the Meta App — use Re-subscribe to register it.",
                ];
            }

            if (($entry['active'] ?? false) !== true) {
                return [
                    'key' => 'app_subscription',
                    'ok' => false,
                    'severity' => 'error',
                    'detail' => "The '{$object}' webhook subscription exists but is not active — use Re-subscribe.",
                ];
            }

            $registeredUrl = (string) ($entry['callback_url'] ?? '');
            if ($registeredUrl !== '' && $registeredUrl !== $expectedUrl) {
                return [
                    'key' => 'app_subscription',
                    'ok' => false,
                    'severity' => 'error',
                    'detail' => "The registered callback URL ({$registeredUrl}) does not match this installation ({$expectedUrl}) — use Re-subscribe.",
                ];
            }

            $fields = collect($entry['fields'] ?? [])->map(fn ($f) => is_array($f) ? ($f['name'] ?? '') : $f);
            if ($fields->isNotEmpty() && ! $fields->contains('messages')) {
                return [
                    'key' => 'app_subscription',
                    'ok' => false,
                    'severity' => 'error',
                    'detail' => "The '{$object}' subscription does not include the 'messages' field — use Re-subscribe.",
                ];
            }

            return [
                'key' => 'app_subscription',
                'ok' => true,
                'severity' => 'error',
                'detail' => "App '{$object}' webhook subscription is active and points to this installation.",
            ];
        } catch (\Throwable $e) {
            return [
                'key' => 'app_subscription',
                'ok' => false,
                'severity' => 'error',
                'detail' => 'Could not reach Meta to verify the app subscription: '.$e->getMessage(),
            ];
        }
    }

    /** @return array{key: string, ok: bool, severity: string, detail: string} */
    private function checkPageSubscription(string $pageId, string $pageToken, string $appId): array
    {
        try {
            $res = Http::withToken($pageToken)->get(self::BASE."/{$pageId}/subscribed_apps");

            if (! $res->successful()) {
                $code = (int) $res->json('error.code');
                $detail = $code === 190
                    ? 'The stored page access token has expired or been invalidated — reconnect the account.'
                    : 'Could not read the page subscriptions: '.($res->json('error.message') ?? 'unknown error');

                return ['key' => 'page_subscription', 'ok' => false, 'severity' => 'error', 'detail' => $detail];
            }

            $apps = collect($res->json('data', []));
            $ours = $appId !== '' ? $apps->firstWhere('id', $appId) : $apps->first();

            if (! $ours) {
                return [
                    'key' => 'page_subscription',
                    'ok' => false,
                    'severity' => 'error',
                    'detail' => "The Facebook Page ({$pageId}) is not subscribed to the app — use Re-subscribe. Without this Meta delivers no messages for the page.",
                ];
            }

            $fields = collect($ours['subscribed_fields'] ?? []);
            if ($fields->isNotEmpty() && ! $fields->contains('messages')) {
                return [
                    'key' => 'page_subscription',
                    'ok' => false,
                    'severity' => 'error',
                    'detail' => "The page subscription is missing the 'messages' field — use Re-subscribe.",
                ];
            }

            return [
                'key' => 'page_subscription',
                'ok' => true,
                'severity' => 'error',
                'detail' => "Page {$pageId} is subscribed to the app's messaging webhooks.",
            ];
        } catch (\Throwable $e) {
            return [
                'key' => 'page_subscription',
                'ok' => false,
                'severity' => 'error',
                'detail' => 'Could not reach Meta to verify the page subscription: '.$e->getMessage(),
            ];
        }
    }

    /** @return array{key: string, ok: bool, severity: string, detail: string} */
    private function checkQueueWorker(): array
    {
        try {
            if (config('queue.default') === 'sync') {
                return ['key' => 'queue_worker', 'ok' => true, 'severity' => 'warning', 'detail' => 'Queue runs synchronously (QUEUE_CONNECTION=sync).'];
            }

            if (config('queue.default') !== 'database') {
                return ['key' => 'queue_worker', 'ok' => true, 'severity' => 'info', 'detail' => 'Queue driver: '.config('queue.default').' — backlog not inspectable here.'];
            }

            $stale = DB::table('jobs')
                ->where('queue', 'whatsapp')
                ->whereNull('reserved_at')
                ->where('created_at', '<', now()->subMinutes(5)->getTimestamp())
                ->count();

            if ($stale > 0) {
                return [
                    'key' => 'queue_worker',
                    'ok' => false,
                    'severity' => 'warning',
                    'detail' => "{$stale} job(s) have been waiting in the 'whatsapp' queue for over 5 minutes — no worker is consuming it. Start one with: ".self::WORKER_HINT,
                ];
            }

            return ['key' => 'queue_worker', 'ok' => true, 'severity' => 'warning', 'detail' => "No stuck jobs in the 'whatsapp' queue."];
        } catch (\Throwable $e) {
            return ['key' => 'queue_worker', 'ok' => true, 'severity' => 'info', 'detail' => 'Queue backlog check skipped: '.$e->getMessage()];
        }
    }

    /**
     * Resolve the Facebook Page id + page token to subscribe for this account.
     *
     * Messenger accounts store them directly. Instagram accounts connected by
     * current versions store facebook_page_id; older connects only stored the
     * IG account id, so derive the page from the stored page token via /me and
     * backfill meta_json for next time.
     *
     * @return array{0: ?string, 1: string, 2: ?string} [pageId, pageToken, error]
     */
    private function resolvePageContext(ChannelAccount $account): array
    {
        $meta = $account->meta_json ?? [];
        $creds = $account->credentials ?? [];

        if ($account->channel === 'messenger') {
            $pageId = (string) ($meta['page_id'] ?? '');
            $token = (string) ($creds['page_access_token'] ?? '');

            if ($pageId === '' || $token === '') {
                return [null, '', 'The stored connection is missing the page id or page access token — reconnect the account.'];
            }

            return [$pageId, $token, null];
        }

        // instagram
        $token = (string) ($creds['access_token'] ?? '');
        if ($token === '') {
            return [null, '', 'The stored connection has no access token — reconnect the account.'];
        }

        $pageId = (string) ($meta['facebook_page_id'] ?? '');
        if ($pageId !== '') {
            return [$pageId, $token, null];
        }

        // Legacy connect: only the IG account id was stored. The stored token is
        // the Page token, so /me resolves to the page itself.
        try {
            $res = Http::withToken($token)->get(self::BASE.'/me', ['fields' => 'id']);
            $pageId = (string) ($res->json('id') ?? '');

            if ($res->successful() && $pageId !== '' && $pageId !== (string) ($meta['instagram_page_id'] ?? '')) {
                $account->update(['meta_json' => array_merge($meta, ['facebook_page_id' => $pageId])]);

                return [$pageId, $token, null];
            }
        } catch (\Throwable) {
            // fall through to the error below
        }

        return [null, '', 'Could not determine the linked Facebook Page for this Instagram account — reconnect the account to repair it.'];
    }
}
