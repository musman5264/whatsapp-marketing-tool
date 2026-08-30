import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Keeps the browser-tab favicon in sync with the admin-configured branding.
 *
 * The favicon <link> is rendered once in app.blade.php's <head>, which is NOT
 * part of the Inertia-managed body — so after an admin uploads a new favicon
 * (an Inertia POST, no full reload) the tab icon never updated until a hard
 * refresh. The server already shares `branding.favicon_url` on every response;
 * this applies it to the live <link> tags so the change shows immediately.
 * Renders nothing. Mount once at app root.
 */
export default function BrandingFavicon() {
    const faviconUrl = usePage().props?.branding?.favicon_url;

    useEffect(() => {
        if (!faviconUrl) return;
        const selectors = ['link[rel="icon"]', 'link[rel="apple-touch-icon"]'];
        selectors.forEach((selector) => {
            let link = document.head.querySelector(selector);
            if (!link) {
                link = document.createElement('link');
                link.rel = selector.includes('apple') ? 'apple-touch-icon' : 'icon';
                document.head.appendChild(link);
            }
            // Drop the type/sizes hints from the SVG fallback so the browser
            // doesn't keep favoring the old default icon.
            link.removeAttribute('type');
            link.removeAttribute('sizes');
            link.href = faviconUrl;
        });
    }, [faviconUrl]);

    return null;
}
