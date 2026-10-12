<?php

use App\Http\Controllers\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\LicenseController;
use App\Http\Middleware\BroadcastingAuthDebug;
use App\Http\Middleware\CheckApiAbility;
use App\Http\Middleware\EnforceLimit;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureClientScope;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureLicensed;
use App\Http\Middleware\EnsureNotDemoMode;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfAdminAuthenticated;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecureHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Web setup wizard (guest). Reachable on a fresh deploy until the app
            // is marked installed; EnsureInstalled redirects everything else here.
            Route::middleware(['web'])
                ->prefix('install')
                ->name('install.')
                ->group(function () {
                    Route::get('/', [InstallController::class, 'show'])->name('show');
                    Route::post('test-database', [InstallController::class, 'testDatabase'])->name('test-database');
                    Route::post('activate-license', [InstallController::class, 'activateLicense'])->name('activate-license');
                    Route::post('/', [InstallController::class, 'run'])->name('run');
                });

            // Standalone license re-activation page (guest). EnsureLicensed
            // redirects an unlicensed admin panel here; whitelisted from the
            // license check so it stays reachable.
            Route::middleware(['web'])
                ->prefix('license')
                ->name('license.')
                ->group(function () {
                    Route::get('/', [LicenseController::class, 'show'])->name('show');
                    Route::post('activate', [LicenseController::class, 'activate'])->name('activate');
                });

            // Webhook intake routes (no CSRF, no auth – signature-verified inside controllers)
            Route::middleware(['web'])
                ->group(base_path('routes/webhooks.php'));

            Route::middleware(['web', 'auth', 'role:client', 'client.scope', 'demo'])
                ->prefix('app')
                ->name('client.')
                ->group(base_path('routes/client.php'));

            // Reports & CSV exports
            Route::middleware(['auth', 'role:client', 'client.scope'])
                ->group(base_path('routes/reports.php'));

            // Admin sign-in is unified onto the main /login page (the
            // AuthenticatedSessionController tries the admin guard first). We
            // keep the `admin.login` route name so the many existing redirects
            // to it (RequirePermission, install flow, admin logout, the
            // auth-exception handler) still resolve — it now just forwards to
            // /login. `redirect.if.admin` still bounces an already-signed-in
            // admin to the dashboard.
            Route::middleware(['web', 'redirect.if.admin'])
                ->get('admin/login', fn () => redirect()->route('login'))
                ->name('admin.login');

            // Admin logout (authenticated admin only)
            Route::post('admin/logout', [AdminLoginController::class, 'destroy'])
                ->middleware(['web', 'auth:admin'])
                ->name('admin.logout');

            // Impersonation stop: callable by impersonated user (web guard), no admin auth required
            Route::post('admin/impersonation/stop', [ImpersonationController::class, 'stop'])
                ->middleware(['web', 'auth'])
                ->name('admin.impersonation.stop');

            // Admin panel (authenticated admin only; RBAC applied per-route).
            // `licensed` blocks the panel and redirects to /license when the
            // copy's license is missing/invalid.
            Route::middleware(['web', 'auth:admin', 'licensed', 'demo'])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // Runs before the DB-querying middleware below so a fresh deploy is
            // redirected to /install without touching the (empty) database.
            EnsureInstalled::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            SecureHeaders::class,
            RequestIdMiddleware::class,
            // Diagnostics for Pusher's auth POST. Read-only; always passes.
            BroadcastingAuthDebug::class,
        ]);
        // Let first-party browser requests (the SPA on a SANCTUM_STATEFUL_DOMAINS
        // host) authenticate to /api/* with the session cookie + CSRF token,
        // not just a Bearer token. Needed so the API Tokens page can mint your
        // first token. Bearer-token and cross-origin API clients are unaffected.
        $middleware->statefulApi();
        $middleware->alias([
            'demo' => EnsureNotDemoMode::class,
            'admin' => EnsureAdminRole::class,
            'admin.super' => EnsureSuperAdmin::class,
            'role' => EnsureUserRole::class,
            'permission' => RequirePermission::class,
            'redirect.if.admin' => RedirectIfAdminAuthenticated::class,
            'client.scope' => EnsureClientScope::class,
            'licensed' => EnsureLicensed::class,
            'limit' => EnforceLimit::class,
            'api.ability' => CheckApiAbility::class,
        ]);
        // Shared middleware stack for all client module routes (mirrors routes/client.php).
        $middleware->appendToGroup('client-app', [
            'auth',
            'verified',
            'role:client',
            EnsureClientScope::class,
            EnsureNotDemoMode::class,
        ]);
        // Trust all proxies so X-Forwarded-For is used for real client IPs.
        // In production, restrict to your actual load balancer IPs via TRUSTED_PROXIES env var.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

        // Gateways sign their webhooks; each handler verifies that signature itself. They
        // are cross-site POSTs with no session, so CSRF validation can only reject them.
        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
            'webhooks/paypal',
            'webhooks/paddle',
            'webhooks/razorpay',
            'webhooks/cashfree',
            'webhooks/tap',
            'webhooks/paystack',
            'webhooks/xendit',
            'webhooks/paymob',
            'webhooks/myfatoorah',
            'webhooks/mollie',
            'webhooks/square',
            'webhooks/iyzico',
            'webhooks/mercadopago',
            // iyzico posts the checkout result token here from the customer's browser.
            'billing/iyzico/callback',
            'webhooks/whatsapp/*',
            'webhooks/whatsapp-web/*',
            'webhooks/meta/*',
            'webhooks/sms/*',
            'webhooks/automation/*',
            'webhooks/ecommerce/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->reportable(function (Throwable $e) {
            Log::channel('errors')->error($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        });

        // Optional Sentry integration — only active when SENTRY_LARAVEL_DSN is set
        if (config('sentry.dsn')) {
            $exceptions->reportable(function (Throwable $e) {
                if (function_exists('\Sentry\configureScope')) {
                    \Sentry\configureScope(function (Scope $scope) {
                        $user = auth()->user();
                        if ($user) {
                            $scope->setTag('workspace_id', (string) ($user->current_workspace_id ?? $user->workspace_id ?? 'unknown'));
                            $scope->setUser(['id' => $user->id, 'email' => $user->email]);
                        }
                    });
                }
                Integration::captureUnhandledException($e);
            });
        }

        $exceptions->renderable(function (AuthenticationException $e, $request) {
            if (in_array('admin', $e->guards(), true)) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Unauthenticated.'], 401)
                    : redirect()->route('admin.login');
            }

            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect()->route('login');
        });
    })->create();
