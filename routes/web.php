<?php

use App\Http\Controllers\CmsPageController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\I18nController;
use App\Http\Controllers\IyzicoCheckoutController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\StorageFileController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\WebhookController;
use App\Models\CmsPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Home / Landing
Route::get('/', [LandingController::class, 'index'])->name('home');

// Auth routes
require __DIR__.'/auth.php';

// Locale / currency / theme
Route::put('/locale', [LocaleController::class, 'update'])->name('locale.update');
Route::get('/i18n/{locale}', [I18nController::class, 'show'])->name('i18n.show');
Route::put('/currency', [CurrencyController::class, 'update'])->name('currency.update');
Route::post('/theme/update', [ThemeController::class, 'update'])->name('theme.update');

// Public marketing pages
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store');

// Public marketing landing pages
Route::get('/pricing', [LandingController::class, 'pricing'])->name('pricing');
Route::get('/faq', [LandingController::class, 'faq'])->name('faq');
Route::get('/use-cases', [LandingController::class, 'useCases'])->name('use-cases');
Route::get('/about', [LandingController::class, 'about'])->name('about');
Route::get('/integrations', [LandingController::class, 'integrations'])->name('integrations');

// CMS pages (e.g. /p/privacy, /p/terms)
Route::get('/p/{slug}', [CmsPageController::class, 'show'])->name('cms-page.show');

// Sitemap & robots.txt
Route::get('/sitemap.xml', function () {
    $landingEnabled = true;
    try {
        $landingEnabled = \App\Models\SystemSetting::get('landing.page_enabled', '1') === '1';
    } catch (Throwable $e) {
        // table may not exist yet
    }
    $urls = $landingEnabled
        ? [url('/'), url('/pricing'), url('/faq'), url('/use-cases'), url('/about'), url('/integrations'), url('/contact'), route('login'), route('register')]
        : [route('login'), route('register')];
    try {
        $cmsPages = CmsPage::where('published', true)->get();
        foreach ($cmsPages as $page) {
            $urls[] = route('cms-page.show', $page->slug);
        }
    } catch (Throwable $e) {
        // table may not exist yet
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $url) {
        $xml .= '<url><loc>'.htmlspecialchars($url).'</loc></url>';
    }
    $xml .= '</urlset>';

    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap');

Route::get('/robots.txt', function () {
    $sitemap = route('sitemap');

    return response(
        "User-agent: *\nDisallow: /admin/\nDisallow: /app/\nSitemap: {$sitemap}",
        200
    )->header('Content-Type', 'text/plain');
})->name('robots');

// Webhooks (no auth, verified by gateway signature)
Route::middleware('throttle:webhooks')->group(function () {
    Route::post('/webhooks/stripe', [WebhookController::class, 'stripe'])->name('webhooks.stripe');
    Route::post('/webhooks/paypal', [WebhookController::class, 'paypal'])->name('webhooks.paypal');
    Route::post('/webhooks/paddle', [WebhookController::class, 'paddle'])->name('webhooks.paddle');
    Route::post('/webhooks/razorpay', [WebhookController::class, 'razorpay'])->name('webhooks.razorpay');
    Route::post('/webhooks/cashfree', [WebhookController::class, 'cashfree'])->name('webhooks.cashfree');
    Route::post('/webhooks/tap', [WebhookController::class, 'tap'])->name('webhooks.tap');
    Route::post('/webhooks/paystack', [WebhookController::class, 'paystack'])->name('webhooks.paystack');
    Route::post('/webhooks/xendit', [WebhookController::class, 'xendit'])->name('webhooks.xendit');
    Route::post('/webhooks/paymob', [WebhookController::class, 'paymob'])->name('webhooks.paymob');
    Route::post('/webhooks/myfatoorah', [WebhookController::class, 'myfatoorah'])->name('webhooks.myfatoorah');
    Route::post('/webhooks/mollie', [WebhookController::class, 'mollie'])->name('webhooks.mollie');
    Route::post('/webhooks/square', [WebhookController::class, 'square'])->name('webhooks.square');
    Route::post('/webhooks/iyzico', [WebhookController::class, 'iyzico'])->name('webhooks.iyzico');
    Route::post('/webhooks/mercadopago', [WebhookController::class, 'mercadopago'])->name('webhooks.mercadopago');
});

// iyzico checkout. Its subscription API returns embedded form HTML rather than a hosted
// page, so we host the form ourselves; iyzico then posts the result token back to the
// callback. The callback is a cross-site POST, so it carries no session cookie and is
// authenticated by the unguessable token alone.
Route::get('/billing/iyzico/form/{token}', [IyzicoCheckoutController::class, 'form'])
    ->middleware(['auth', 'throttle:60,1'])
    ->name('checkout.iyzico.form');
Route::post('/billing/iyzico/callback', [IyzicoCheckoutController::class, 'callback'])
    ->middleware('throttle:webhooks')
    ->name('checkout.iyzico.callback');

// ─── Health / readiness probes ───────────────────────────────────────────────
// Protected by a shared secret token (HEALTHZ_TOKEN env var). Set to a random
// string in production and pass via Authorization: Bearer <token> header.
Route::middleware('throttle:30,1')->group(function () {
    $guardHealthz = function (Illuminate\Http\Request $request): bool {
        $token = config('app.healthz_token');

        return ! filled($token) || hash_equals($token, $request->bearerToken() ?? '');
    };

    Route::get('/healthz/db', function () use ($guardHealthz) {
        if (! $guardHealthz(request())) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }
        try {
            DB::selectOne('SELECT 1');

            return response()->json(['status' => 'ok', 'db' => 'connected']);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'db' => 'database error'], 503);
        }
    })->name('healthz.db');

    Route::get('/healthz/redis', function () use ($guardHealthz) {
        if (! $guardHealthz(request())) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }
        try {
            Redis::ping();

            return response()->json(['status' => 'ok', 'redis' => 'connected']);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'redis' => 'redis error'], 503);
        }
    })->name('healthz.redis');

    Route::get('/healthz/queue', function () use ($guardHealthz) {
        if (! $guardHealthz(request())) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }
        try {
            $size = Queue::size('default');

            return response()->json(['status' => 'ok', 'queue_driver' => config('queue.default'), 'default_size' => $size]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'queue' => 'queue error'], 503);
        }
    })->name('healthz.queue');
});

/*
 * Fallback serving of local public-disk files (uploads, branding).
 *
 * When the public/storage symlink works the web server serves these directly
 * and this route is never hit. On hosts where the symlink can't be created
 * (common on cPanel/LiteSpeed) the request falls through to Laravel and this
 * route delivers the file. Registered last so it never shadows a real route,
 * and constrained to paths that don't collide with the app (no dots-only, etc).
 */
Route::get('/storage/{path}', [StorageFileController::class, 'show'])
    ->where('path', '.*')
    ->middleware('throttle:240,1')
    ->name('storage.file');
