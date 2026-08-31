<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Locale;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Services\I18n\I18nFileService;
use App\Services\OnboardingService;
use App\Services\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $parent = parent::share($request);

        try {
            $app = $this->appShare($request);
        } catch (\Throwable $e) {
            Log::channel('single')->error('HandleInertiaRequests::share failed: '.$e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $locale = app()->getLocale();
            $app = [
                'csrf_token' => csrf_token(),
                'flash' => ['success' => null, 'error' => null],
                'auth' => [
                    'user' => $request->user(),
                    'adminUser' => null,
                    'permissions' => [],
                ],
                'currentWorkspace' => null,
                'workspaces' => [],
                'locale' => $locale,
                'dir' => 'ltr',
                'i18n' => [
                    'locale' => $locale,
                    'isRtl' => in_array($locale, ['ar'], true),
                    'locales' => [],
                ],
                'supportedLocales' => ['en' => 'English'],
                'rtlLocales' => ['ar'],
                'currencies' => [],
                'displayCurrency' => 'USD',
                'theme' => 'light',
                'demo_mode' => false,
                'app_version' => env('APP_VERSION', '1.0.0'),
                'onboardingSummary' => null,
            ];
        }

        return array_merge($parent, $app);
    }

    private function firebasePublicConfig(): array
    {
        try {
            $enabled = SystemSetting::get('firebase_enabled', 'false') === 'true';

            return [
                'enabled' => $enabled,
                'apiKey' => SystemSetting::get('firebase_api_key', ''),
                'authDomain' => SystemSetting::get('firebase_auth_domain', ''),
                'projectId' => SystemSetting::get('firebase_project_id', ''),
                'appId' => SystemSetting::get('firebase_app_id', ''),
            ];
        } catch (\Throwable) {
            return ['enabled' => false, 'apiKey' => '', 'authDomain' => '', 'projectId' => '', 'appId' => ''];
        }
    }

    private function oneSignalPublicConfig(): array
    {
        try {
            $appId = config('services.onesignal.app_id', '');

            return [
                'app_id' => $appId,
                'enabled' => filled($appId),
            ];
        } catch (\Throwable) {
            return ['app_id' => '', 'enabled' => false];
        }
    }

    private function pusherPublicConfig(): array
    {
        try {
            // Reverb speaks the Pusher protocol. When it's the active broadcaster,
            // hand the frontend Reverb's key + an explicit ws host/port so
            // laravel-echo connects to our server instead of pusher.com.
            if (config('broadcasting.default') === 'reverb') {
                $key = (string) config('broadcasting.connections.reverb.key');

                return [
                    'key' => $key,
                    'cluster' => '',
                    'enabled' => $key !== '',
                    'wsHost' => (string) config('broadcasting.connections.reverb.options.host'),
                    'wsPort' => (int) config('broadcasting.connections.reverb.options.port', 443),
                    'forceTLS' => config('broadcasting.connections.reverb.options.scheme', 'https') === 'https',
                ];
            }

            $key = SystemSetting::get('pusher_app_key') ?: env('PUSHER_APP_KEY', '');
            $cluster = SystemSetting::get('pusher_app_cluster') ?: env('PUSHER_APP_CLUSTER', 'mt1');
            $dbFlag = SystemSetting::get('pusher_enabled');

            // If the admin panel has explicitly disabled Pusher, respect that.
            // Otherwise (setting absent/null) treat a non-empty key as enabled,
            // so the .env credentials work out-of-the-box without a DB toggle.
            $enabled = $dbFlag === 'false' ? false : ! empty($key);

            return [
                'key' => $key,
                'cluster' => $cluster,
                'enabled' => $enabled,
            ];
        } catch (\Throwable) {
            $key = env('PUSHER_APP_KEY', '');

            return ['key' => $key, 'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'), 'enabled' => ! empty($key)];
        }
    }

    private function brandingShare(): array
    {
        try {
            $logoPath = SystemSetting::get('app_logo_path');
            $faviconPath = SystemSetting::get('app_favicon_path');

            return [
                'app_name' => SystemSetting::get('app_name') ?: config('saas.app_name', config('app.name')),
                'app_tagline' => SystemSetting::get('app_tagline') ?: config('saas.tagline'),
                'support_email' => SystemSetting::get('support_email') ?: config('saas.support_email'),
                'docs_url' => SystemSetting::get('docs_url') ?: config('saas.docs_url'),
                'primary_color' => SystemSetting::get('primary_color') ?: config('saas.branding.primary_color', '#467235'),
                'secondary_color' => SystemSetting::get('secondary_color') ?: config('saas.branding.secondary_color', '#283f24'),
                'font_family' => SystemSetting::get('font_family') ?: config('saas.branding.font_family', 'space-grotesk'),
                'logo_url' => $logoPath ? $this->assetUrl($logoPath, SystemSetting::get('app_logo_disk', 'public')) : null,
                'favicon_url' => $faviconPath ? $this->assetUrl($faviconPath, SystemSetting::get('app_favicon_disk', 'public')) : null,
            ];
        } catch (\Throwable) {
            return [
                'app_name' => config('saas.app_name', config('app.name')),
                'app_tagline' => config('saas.tagline'),
                'support_email' => config('saas.support_email'),
                'docs_url' => config('saas.docs_url'),
                'primary_color' => config('saas.branding.primary_color', '#467235'),
                'secondary_color' => config('saas.branding.secondary_color', '#283f24'),
                'font_family' => config('saas.branding.font_family', 'space-grotesk'),
                'logo_url' => null,
                'favicon_url' => null,
            ];
        }
    }

    private function workspaceUsage(?int $workspaceId, mixed $plan): array
    {
        if (! $workspaceId) {
            return [];
        }

        $limits = $plan?->limits ?? [];
        if (empty($limits)) {
            return [];
        }

        $metricsMap = [
            'campaigns_per_month' => 'campaigns',
            'whatsapp_messages_per_month' => 'whatsapp_messages',
            'social_posts_per_month' => 'social_posts',
            'lead_credits_per_month' => 'lead_credits',
        ];

        $usage = [];
        foreach ($limits as $limitKey => $limit) {
            if ($limit === null) {
                continue;
            }
            $meterKey = $metricsMap[$limitKey] ?? $limitKey;
            $current = UsageMeter::current($workspaceId, $meterKey);
            $usage[$limitKey] = [
                'current' => $current,
                'limit' => $limit,
                'percent' => $limit > 0 ? min(100, round(($current / $limit) * 100)) : 0,
            ];
        }

        return $usage;
    }

    private function appShare(Request $request): array
    {
        $locale = app()->getLocale();
        $isRtl = (bool) $request->attributes->get('is_rtl', false);
        $dir = $isRtl ? 'rtl' : 'ltr';

        $i18nLocales = [];
        try {
            $list = Locale::forSwitcher();
            foreach ($list as $loc) {
                $i18nLocales[] = [
                    'code' => $loc->code,
                    'name' => $loc->name,
                    'native_name' => $loc->native_name ?? $loc->name,
                    'is_rtl' => (bool) $loc->is_rtl,
                    'flag' => $loc->flag,
                ];
            }
        } catch (\Throwable $e) {
            $i18nLocales = [
                ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_rtl' => false, 'flag' => null],
            ];
        }

        $user = $request->user();
        $adminUser = $request->user('admin');
        $isAdminRoute = $request->routeIs('admin.*') && ! $request->routeIs('admin.login');

        $displayCurrency = $user?->display_currency
            ?? ($user?->workspace?->currency_code ?? null)
            ?? $request->session()->get('display_currency')
            ?? Currency::defaultCode()
            ?? 'USD';

        $currencies = Currency::where('enabled', true)
            ->orderByRaw('is_default DESC')
            ->orderBy('code')
            ->get(['code', 'symbol', 'decimals', 'exchange_rate'])
            ->map(fn ($c) => ['code' => $c->code, 'symbol' => $c->symbol, 'decimals' => $c->decimals, 'exchange_rate' => (float) $c->exchange_rate]);

        $currentWorkspace = null;
        $workspacesForSwitcher = [];
        $workspaceId = null;
        $plan = null;
        // Only real client users have workspaces. On admin routes the default guard
        // is `admin`, so $request->user() is an AdminUser — calling the workspace
        // helpers (isAccessibleBy/accessibleWorkspaces, type-hinted to User) with it
        // throws a TypeError, which crashes share() and drops the page to the
        // fallback props (no translations, empty permissions → blank admin panel).
        // This only surfaced when the session carried a leftover current_workspace_id.
        if ($user instanceof User) {
            $workspaceId = $request->session()->get('current_workspace_id') ?? $user->workspace_id;
            if ($workspaceId) {
                $workspace = Workspace::with('client')->find($workspaceId);
                if ($workspace && $workspace->isAccessibleBy($user)) {
                    $currentWorkspace = ['id' => $workspace->id, 'name' => $workspace->name];
                    $plan = $workspace->client?->activePlan();
                }
            }
            try {
                $workspacesForSwitcher = $user->accessibleWorkspaces()->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->values()->all();
            } catch (\Throwable $e) {
                $workspacesForSwitcher = [];
            }
        }

        $auth = [
            'user' => $user,
            'adminUser' => null,
            'permissions' => [],
        ];
        if ($isAdminRoute && $adminUser) {
            $auth['adminUser'] = [
                'id' => $adminUser->id,
                'name' => $adminUser->name,
                'email' => $adminUser->email,
                'status' => $adminUser->status,
            ];
            $auth['permissions'] = $adminUser->permissionKeys();
        }

        $impersonation = null;
        if ($user && $request->session()->get('impersonating') && $request->session()->get('impersonated_client_id')) {
            $client = Client::find($request->session()->get('impersonated_client_id'));
            $impersonation = [
                'active' => true,
                'clientName' => $client?->name ?? 'Unknown',
                'returnUrl' => route('admin.impersonation.stop'),
            ];
        }

        $supportedLocalesMap = [];

        foreach ($i18nLocales as $loc) {
            $supportedLocalesMap[$loc['code']] = $loc['native_name'];
        }
        if (empty($supportedLocalesMap)) {
            $supportedLocalesMap = ['en' => 'English'];
        }

        $unreadNotificationsCount = $user ? $user->unreadNotifications()->count() : 0;

        $onboardingSummary = null;
        if ($user && ! $isAdminRoute && ($request->routeIs('client.*') || $request->routeIs('reports.exports.*'))) {
            try {
                $progress = app(OnboardingService::class)->getProgress($user);
                $onboardingSummary = [
                    'done' => $progress['done'],
                    'total' => $progress['total'],
                    'percent' => $progress['percent'],
                    'is_complete' => $progress['is_complete'],
                ];
            } catch (\Throwable) {
                $onboardingSummary = null;
            }
        }

        return [
            // Current CSRF token, re-shared on every Inertia response so the SPA can
            // keep its axios header + <meta> tag in sync. Without this a long-lived
            // page keeps the boot-time token, which goes stale when the session token
            // rotates (e.g. on impersonation) and causes 419s until a hard reload.
            'csrf_token' => csrf_token(),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'openEditPlanId' => $request->session()->get('openEditPlanId'),
                'upgrade_required' => $request->session()->get('upgrade_required'),
                'upgrade_reason' => $request->session()->get('upgrade_reason'),
            ],
            'auth' => $auth,
            'unreadNotificationsCount' => $unreadNotificationsCount,
            'impersonation' => $impersonation,
            'theme' => $user?->theme ?? 'light',
            'timezone' => $user?->timezone ?? 'UTC',
            'currentWorkspace' => $currentWorkspace,
            'workspaces' => $workspacesForSwitcher,
            'locale' => $locale,
            'dir' => $dir,
            'i18n' => [
                'locale' => $locale,
                'isRtl' => $isRtl,
                'locales' => $i18nLocales,
                'translations' => app(I18nFileService::class)->getFlatDictionary($locale),
            ],
            'supportedLocales' => $supportedLocalesMap,
            'rtlLocales' => array_values(array_column(array_filter($i18nLocales, fn ($l) => ! empty($l['is_rtl'])), 'code')) ?: ['ar'],
            'currencies' => $currencies,
            'displayCurrency' => $displayCurrency,
            'demo_mode' => config('app.demo_mode', false),
            'current_workspace_usage' => $this->workspaceUsage($workspaceId ?? null, $plan ?? null),
            'app_version' => env('APP_VERSION', '1.0.0'),
            'onboardingSummary' => $onboardingSummary,
            'landingPageEnabled' => SystemSetting::get('landing.page_enabled', '1') === '1',
            'branding' => $this->brandingShare(),
            'pusher' => $this->pusherPublicConfig(),
            'onesignal' => $this->oneSignalPublicConfig(),
            'firebase' => $this->firebasePublicConfig(),
            'metaAppId' => $this->metaAppId(),
        ];
    }

    private function metaAppId(): ?string
    {
        try {
            return CredentialResolver::system()->meta()?->appId() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assetUrl(string $path, string $disk): string
    {
        app(StorageManager::class)->ensureDiskReady($disk);

        return Storage::disk($disk)->url($path);
    }
}
