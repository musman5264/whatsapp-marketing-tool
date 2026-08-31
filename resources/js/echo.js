import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

function readInertiaProps() {
    try {
        const el = document.getElementById('app');
        if (el?.dataset?.page) {
            const page = JSON.parse(el.dataset.page);
            window.__INERTIA_PAGE_PROPS__ = page.props ?? {};
        }
    } catch {}
}

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function initEcho() {
    if (window.Echo) return; // already initialised

    readInertiaProps();

    // Pusher config is injected by the server via Inertia shared props
    const pusherConfig = window.__INERTIA_PAGE_PROPS__?.pusher
        ?? window.__pusherConfig__
        ?? {};

    const key     = pusherConfig.key     || import.meta.env.VITE_PUSHER_APP_KEY     || '';
    const cluster = pusherConfig.cluster || import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1';
    const enabled = pusherConfig.enabled !== undefined ? pusherConfig.enabled : !!key;

    // When a self-hosted server (Laravel Reverb / soketi) is used, the backend
    // sends an explicit ws host/port. Reverb speaks the Pusher protocol, so we
    // still use the 'pusher' broadcaster — just pointed at our own server.
    const wsHost   = pusherConfig.wsHost || '';
    const wsPort   = pusherConfig.wsPort || undefined;
    const forceTLS = pusherConfig.forceTLS !== undefined ? pusherConfig.forceTLS : true;

    if (!key || !enabled) {
        // eslint-disable-next-line no-console
        console.warn('[echo] Realtime disabled or key missing — live updates off.', { enabled, hasKey: !!key });
        return;
    }

    const csrf = getCsrfToken();
    if (!csrf) {
        // eslint-disable-next-line no-console
        console.warn('[echo] CSRF meta tag missing — broadcasting/auth will likely fail.');
    }

    window.Echo = new Echo({
        broadcaster:       'pusher',
        key,
        cluster,
        forceTLS,
        disableStats:      true,
        enabledTransports: ['ws', 'wss'],
        // Self-hosted server (Reverb/soketi): pin the ws endpoint.
        ...(wsHost ? {
            wsHost,
            wsPort:  wsPort ?? (forceTLS ? 443 : 80),
            wssPort: wsPort ?? (forceTLS ? 443 : 80),
        } : {}),
        // Explicitly use absolute auth endpoint + send CSRF + cookies so the
        // session is always present on POST /broadcasting/auth.
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        },
        // Pusher uses an XHR for the auth call; ensure cookies are sent.
        authTransport: 'ajax',
    });

    // Log auth failures so we can see which channel + what status.
    window.Echo.connector.pusher.connection.bind('error', (err) => {
        // eslint-disable-next-line no-console
        console.warn('[echo] pusher connection error', err);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEcho, { once: true });
} else {
    initEcho();
}
