<!DOCTYPE html>
@php
    $locale = request()->attributes->get('active_locale', app()->getLocale());
    $htmlDir = request()->attributes->get('is_rtl', false) ? 'rtl' : 'ltr';
@endphp
@php
    // Guarded: the database may be unavailable during first-run install.
    try {
        $serverTheme = auth()->user()?->theme ?? null;
    } catch (\Throwable) {
        $serverTheme = null;
    }
@endphp
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $htmlDir }}">
    <head>
        <script>
            (function() {
                var server = @json($serverTheme);
                var stored = localStorage.getItem('theme');
                var pref = (stored === 'light' || stored === 'dark') ? stored : ((server === 'light' || server === 'dark') ? server : 'light');
                document.documentElement.classList.toggle('dark', pref === 'dark');
            })();
        </script>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="vapid-public-key" content="{{ config('webpush.vapid_public_key') }}">

        {{-- config('app.name') is overridden at boot from the admin-configured
             brand name (see BrandingServiceProvider), so this needs no direct
             SystemSetting lookup. --}}
        <title inertia>{{ config('app.name') }}</title>
        @php
            try {
                $faviconPath = \App\Models\SystemSetting::get('app_favicon_path');
                // Honor the disk the favicon was actually saved to (may be a cloud
                // provider). Hardcoding 'public' produced a wrong/404 URL whenever
                // the active storage disk was not the local public one.
                $faviconDisk = \App\Models\SystemSetting::get('app_favicon_disk', 'public');
                if ($faviconPath) {
                    app(\App\Services\StorageManager::class)->ensureDiskReady($faviconDisk);
                    $faviconUrl = \Illuminate\Support\Facades\Storage::disk($faviconDisk)->url($faviconPath);
                } else {
                    $faviconUrl = null;
                }
            } catch (\Throwable) {
                $faviconUrl = null;
            }
        @endphp
        @if($faviconUrl)
            <link rel="icon" href="{{ $faviconUrl }}">
            <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
        @else
            {{-- Fallback brand icon: SVG for modern browsers, .ico for legacy,
                 PNG apple-touch for iOS home-screen. See public/whatsmine-icon.svg. --}}
            <link rel="icon" type="image/svg+xml" href="/whatsmine-icon.svg">
            <link rel="alternate icon" href="/favicon.ico" sizes="any">
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @endif

        @php
            // Branding: resolved here rather than in a view composer so the <style>
            // override ships in the initial HTML — applying it from JS after hydration
            // would flash the default palette on every page load. Reads the cached
            // branding array (no queries, and safe when the DB is unavailable during
            // a first-run install). A null value means the admin never set one, in
            // which case the hand-tuned defaults in app.css are left alone.
            $branding = \App\Providers\BrandingServiceProvider::cached();

            $brandPrimary   = \App\Support\BrandPalette::isValidHex($branding['primary_color'] ?? null) ? $branding['primary_color'] : null;
            $brandSecondary = \App\Support\BrandPalette::isValidHex($branding['secondary_color'] ?? null) ? $branding['secondary_color'] : null;

            $fonts = config('saas.branding.fonts', []);
            // Reject anything not on the whitelist — the slug is interpolated into a
            // stylesheet URL below, and the family name into a CSS declaration.
            $brandFont  = $branding['font_family'] ?? null;
            $fontSlug   = ($brandFont && array_key_exists($brandFont, $fonts)) ? $brandFont : config('saas.branding.font_family', 'space-grotesk');
            $fontFamily = $fonts[$fontSlug] ?? 'Space Grotesk';
        @endphp

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family={{ urlencode($fontSlug) }}:400,500,600,700&display=swap" rel="stylesheet" />
        {{-- Anek Bangla for Bengali script. The Bengali glyph files are lazy-loaded
             by unicode-range, so they're only fetched when bn text actually renders
             (i.e. html[lang="bn"]); keeping the link unconditional means a client-side
             locale switch picks it up without a full page reload. --}}
        <link href="https://fonts.bunny.net/css?family=anek-bangla:400,500,600,700&display=swap" rel="stylesheet" />

        @if(config('services.onesignal.app_id'))
        <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
        <script>
            window.OneSignalDeferred = window.OneSignalDeferred || [];

            OneSignalDeferred.push(async function (OneSignal) {
                try {
                    await OneSignal.init({
                        appId: "{{ config('services.onesignal.app_id') }}",
                        notifyButton: { enable: false },
                        allowLocalhostAsSecureOrigin: {{ app()->environment('local') ? 'true' : 'false' }},
                    });
                } catch (e) {
                    console.warn('[onesignal] init failed — push notifications disabled:', e?.message ?? e);
                    return;
                }

                // If permission is granted but the subscription has no token, the local
                // OneSignal state is stale (leftover from a previous broken registration).
                // Opt-out and back in to force a fresh SW subscription.
                if (Notification.permission === 'granted') {
                    try {
                        var sub = OneSignal.User?.PushSubscription;
                        if (sub && !sub.token && sub.optedIn) {
                            await sub.optOut();
                            await sub.optIn();
                        }
                    } catch (_) {}
                }

                // Suppress push notification when the user is actively viewing the inbox
                // (Echo already shows the message in real-time there).
                // On every other page the notification is shown as normal.
                try {
                    OneSignal.Notifications.addEventListener('foregroundWillDisplay', function(event) {
                        var p = window.location.pathname;
                        if (p.includes('/inbox')) {
                            event.preventDefault(); // user can see the message live — no popup needed
                        }
                        // else: let OneSignal display the notification
                    });
                } catch(_) {}

                @auth
                // Only login once we have a real push subscription (non-empty token).
                // Calling login() with an empty token causes a 400 from OneSignal.
                var _osUserId = "{{ auth()->id() }}";

                async function osLogin() {
                    try {
                        var sub = OneSignal.User?.PushSubscription;
                        var token = sub?.token;
                        var subId  = sub?.id;
                        // A "local-" prefixed ID means the subscription hasn't been
                        // confirmed by OneSignal's server yet; calling login() in that
                        // state returns 400 "No aliases found".
                        if (!token || (subId && String(subId).startsWith('local-'))) return;
                        await OneSignal.login(_osUserId);
                    } catch (e) {
                        console.warn('[onesignal] login failed:', e?.message ?? e);
                    }
                }
                window.osLogin = osLogin;

                // If permission is already granted, wait for the subscription token
                // to be populated before attempting login.
                if (Notification.permission === 'granted') {
                    var token = OneSignal.User?.PushSubscription?.token;
                    if (token) {
                        osLogin();
                    } else {
                        // Token arrives asynchronously — wait for the subscription change event
                        try {
                            OneSignal.User.PushSubscription.addEventListener('change', function handler(e) {
                                var cur = e.current;
                                if (cur?.token && !(cur?.id && String(cur.id).startsWith('local-'))) {
                                    OneSignal.User.PushSubscription.removeEventListener('change', handler);
                                    osLogin();
                                }
                            });
                        } catch (_) {}
                    }
                }

                // Login when the user grants permission later (e.g. after our prompt).
                try {
                    OneSignal.Notifications.addEventListener('permissionChange', function (granted) {
                        if (granted) {
                            // Give the SW subscription a moment to generate a token
                            setTimeout(osLogin, 1000);
                        }
                    });
                } catch (_) {}
                @endauth
            });

            // Suppress any unhandled SDK rejections so they don't pollute the console.
            window.addEventListener('unhandledrejection', function (ev) {
                var stack = String(ev.reason?.stack ?? ev.reason ?? '');
                if (stack.includes('OneSignal') || stack.includes('onesignal')) ev.preventDefault();
            });
        </script>
        @endif

        <!-- Facebook JS SDK — loaded eagerly when Meta App is configured -->
        <div id="fb-root"></div>
        @php
            // Guarded: integration_configs may be unreadable during first-run install.
            try {
                $metaAppId = \App\Modules\Integrations\Services\CredentialResolver::system()->meta()?->appId();
            } catch (\Throwable) {
                $metaAppId = null;
            }
        @endphp
        @if($metaAppId)
        <script>
            window.fbAsyncInit = function() {
                FB.init({
                    appId: '{{ e($metaAppId) }}',
                    autoLogAppEvents: true,
                    xfbml: false,
                    version: 'v20.0',
                });
                window.__fbSdkReady = true;
            };
        </script>
        <script async defer crossorigin="anonymous"
            src="https://connect.facebook.net/en_US/sdk.js"></script>
        @endif

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', 'resources/js/Pages/' . (isset($page['component']) ? $page['component'] : 'Dashboard') . '.jsx'])

        {{-- Overrides the :root defaults in resources/css/app.css. Only emitted when
             the admin actually configured something, so a stock install keeps the
             hand-tuned palette rather than a generated approximation of it.

             Must stay AFTER @vite: this and app.css both declare these custom
             properties on :root at equal specificity, so whichever comes last wins.
             Emitted above the stylesheet, it silently loses and nothing retints. --}}
        @if($brandPrimary || $brandSecondary || $fontSlug !== 'space-grotesk')
        <style>
            :root {
                @if($fontSlug !== 'space-grotesk') --font-sans: '{{ $fontFamily }}'; @endif
                @if($brandPrimary) {!! \App\Support\BrandPalette::cssVars('brand', $brandPrimary) !!} {!! \App\Support\BrandPalette::surfaceVars($brandPrimary) !!} @endif
                @if($brandSecondary) {!! \App\Support\BrandPalette::cssVars('secondary', $brandSecondary) !!} @endif
            }
        </style>
        @endif

        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
