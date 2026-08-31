<?php

namespace App\Http\Middleware;

use App\Modules\Integrations\Services\CredentialResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // Prevent the browser from caching authenticated pages. Without this, the
        // back button after logout restores a cached/bfcache copy of the dashboard,
        // making it look as if the session is still active. `no-store` forces a
        // server round-trip on back/forward, which redirects to login once logged out.
        if (auth()->guard('web')->check() || auth()->guard('admin')->check()) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        $csp = $this->buildCsp($request);
        if ($csp !== null) {
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }

    private function buildCsp(Request $request): ?string
    {
        // iyzico's subscription checkout is embedded markup that pulls its own scripts and
        // 3-D Secure frames from iyzipay.com. Widen the policy only on the page that renders
        // that form rather than for the whole app.
        $iyzico = $request->routeIs('checkout.iyzico.form') ? ' https://iyzipay.com https://*.iyzipay.com' : '';

        $unsafeEval = config('app.env') !== 'production' ? " 'unsafe-eval'" : '';
        $scriptSrc = "'self' 'unsafe-inline'".$unsafeEval.$this->viteDevSources().$this->thirdPartyScriptSources().$iyzico;
        $styleSrc = "'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com".$this->viteDevSources().$this->thirdPartyStyleSources().$iyzico;
        $fontSrc = "'self' data: https://fonts.bunny.net https://fonts.gstatic.com https://fonts.googleapis.com".$iyzico;

        $frameSrc = "'self'".$this->metaFrameSources().$iyzico;

        $directives = array_filter([
            "default-src 'self'",
            'script-src '.$scriptSrc,
            'script-src-elem '.$scriptSrc,
            'style-src '.$styleSrc,
            'style-src-elem '.$styleSrc,
            "img-src 'self' data: https: blob:",
            'font-src '.$fontSrc,
            "connect-src 'self' ".$this->connectSources().$iyzico,
            'frame-src '.$frameSrc,
            "frame-ancestors 'self'",
        ]);

        return implode('; ', $directives);
    }

    /** Allow Vite dev server (different port) in development so CSP does not block scripts. */
    private function viteDevSources(): string
    {
        if (config('app.env') === 'production') {
            return '';
        }

        // Vite default port 5173. Do not use http://[::1]:5173 — invalid in script-src for Chromium and triggers console noise.
        return ' http://localhost:5173 http://127.0.0.1:5173';
    }

    /** OneSignal (when configured), Meta JS SDK, and Cloudflare Web Analytics / beacon scripts. */
    private function thirdPartyScriptSources(): string
    {
        $extra = ' https://static.cloudflareinsights.com';
        if (filled(config('services.onesignal.app_id'))) {
            // SDK loads from cdn; runtime sync/scripts also come from api.* (see OneSignal v16 CSP docs).
            $extra .= ' https://cdn.onesignal.com https://*.onesignal.com';
        }
        if ($this->metaSdkEnabled()) {
            $extra .= ' https://connect.facebook.net';
        }

        return $extra;
    }

    /** OneSignal injects styles from the apex host (e.g. OneSignalSDK.page.styles.css). */
    private function thirdPartyStyleSources(): string
    {
        if (! filled(config('services.onesignal.app_id'))) {
            return '';
        }

        return ' https://onesignal.com https://*.onesignal.com';
    }

    private function connectSources(): string
    {
        $sources = [];
        if (config('app.env') !== 'production') {
            $sources[] = 'ws:';
            $sources[] = 'wss:';
        }
        $url = config('app.url');
        if ($url) {
            $sources[] = parse_url($url, PHP_URL_HOST) ?: $url;
        }

        // Realtime WebSocket host — the browser opens a wss:// connection here for
        // live inbox updates. Covers self-hosted Reverb and hosted Pusher.
        foreach ($this->realtimeHosts() as $host) {
            $sources[] = 'wss://'.$host;
            $sources[] = 'https://'.$host;
        }
        if (filled(config('services.onesignal.app_id'))) {
            $sources[] = 'https://onesignal.com';
            $sources[] = 'https://*.onesignal.com';
        }
        if ($this->metaSdkEnabled()) {
            $sources[] = 'https://graph.facebook.com';
            $sources[] = 'https://www.facebook.com';
            $sources[] = 'https://web.facebook.com';
        }

        return implode(' ', array_unique($sources));
    }

    /** Allow Meta Login / Embedded Signup dialogs in iframes when the Meta App is configured. */
    private function metaFrameSources(): string
    {
        if (! $this->metaSdkEnabled()) {
            return '';
        }

        return ' https://www.facebook.com https://web.facebook.com https://connect.facebook.net';
    }

    private function metaSdkEnabled(): bool
    {
        try {
            return filled(CredentialResolver::system()->meta()?->appId());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * WebSocket host(s) the frontend connects to for realtime, derived from the
     * active broadcaster.
     *
     * @return list<string>
     */
    private function realtimeHosts(): array
    {
        $hosts = [];
        try {
            $driver = config('broadcasting.default');

            if ($driver === 'reverb') {
                $h = config('broadcasting.connections.reverb.options.host');
                if ($h) {
                    $hosts[] = $h;
                }
            } elseif ($driver === 'pusher') {
                $cluster = config('broadcasting.connections.pusher.options.cluster', 'mt1');
                $custom = config('broadcasting.connections.pusher.options.host');
                $hosts[] = $custom ?: "ws-{$cluster}.pusher.com";
                $hosts[] = "sockjs-{$cluster}.pusher.com";
            }
        } catch (\Throwable) {
            // fall through with an empty list
        }

        return array_values(array_filter(array_unique($hosts)));
    }
}
