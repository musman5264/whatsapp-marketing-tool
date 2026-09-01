<?php

namespace App\Providers;

use App\Models\Workspace;
use App\Modules\Shared\Services\ChannelManager;
use App\Services\Billing\BillingGatewayRegistry;
use App\Services\StorageManager;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // On a fresh deploy the database (and its sessions/cache/queue tables)
        // does not exist yet, which would otherwise break the installer's own
        // session + CSRF. Until the app is installed, fall back to filesystem
        // drivers so the setup wizard works against an empty database.
        if (! config('app.installed')) {
            config([
                'session.driver' => 'file',
                'cache.default' => 'array',
                'queue.default' => 'sync',
            ]);
        }

        $this->app->singleton(BillingGatewayRegistry::class, fn () => new BillingGatewayRegistry);
        $this->app->singleton(StorageManager::class);
        $this->app->singleton(ChannelManager::class, fn () => new ChannelManager);
    }

    public function boot(): void
    {
        $this->configureHttpClientSsl();
        $this->forceHttpsForWebhookUrls();

        Gate::define('viewAdmin', fn ($user) => $user?->isAdmin());
        Gate::define('manageAdminSensitive', fn ($user) => $user?->isAdmin());

        // Event → listener wiring is handled by Laravel's automatic listener
        // discovery (it scans app/Listeners/ and binds every method whose first
        // parameter is type-hinted to an event). Do NOT also register those
        // listeners with Event::listen() here — that made every listener fire
        // twice (automations ran twice, auto-replies sent twice, and the
        // "Ask Question" resume ran twice, corrupting parked runs).
        //
        // Listeners covered by discovery:
        //   AutomationTriggerListener, AutoReplyListener,
        //   DispatchOutboundWebhookListener, LogSuccessfulLogin,
        //   SendWelcomeNotification, Send*Notification (all of app/Listeners/).

        // ── Named rate limiters ─────────────────────────────────────────────
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('webhooks', function (Request $request) {
            // Use the real client IP (respects X-Forwarded-For when trusted proxies are set).
            // Limit is intentionally high: a single Meta app services multiple workspaces and
            // all their traffic arrives from a small pool of Meta egress IPs.
            return Limit::perMinute(1000)->by($request->getClientIp());
        });

        RateLimiter::for('ai-runs', function (Request $request) {
            $workspaceId = $request->user()?->current_workspace_id ?? $request->ip();
            $workspace = $workspaceId ? Workspace::with('client.activePlan')->find($workspaceId) : null;
            $perMinute = $workspace?->client?->activePlan?->limits['ai_runs_per_minute'] ?? 10;

            return Limit::perMinute((int) $perMinute)->by((string) $workspaceId);
        });

        // ── Scramble / OpenAPI ──────────────────────────────────────────────
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });

        // Only include /api/v1/* routes in the spec
        Scramble::routes(function (Route $route) {
            return str_starts_with($route->uri(), 'api/v1');
        });

        Vite::prefetch(concurrency: 3);
    }

    /**
     * Point Guzzle at a valid CA bundle when php.ini references a missing file
     * (Windows cURL error 77), or optionally disable verify for local dev only.
     */
    /** Meta webhook callbacks must use HTTPS in production. */
    private function forceHttpsForWebhookUrls(): void
    {
        $appUrl = config('app.url');
        if (
            app()->environment('production')
            && is_string($appUrl)
            && str_starts_with($appUrl, 'https://')
        ) {
            URL::forceScheme('https');
        }
    }

    private function configureHttpClientSsl(): void
    {
        $caPath = config('http.ca_path');

        if (is_string($caPath) && $caPath !== '' && is_file($caPath)) {
            Http::globalOptions(['verify' => $caPath]);

            return;
        }

        // Laragon / Windows PHP builds: cacert.pem next to php.exe (php.ini may still point elsewhere).
        if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') {
            $phpDirBundle = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'
                .DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'cacert.pem';
            if (is_file($phpDirBundle)) {
                Http::globalOptions(['verify' => $phpDirBundle]);

                return;
            }
        }

        if (config('http.verify_ssl') === false) {
            Http::globalOptions(['verify' => false]);
        }
    }
}
