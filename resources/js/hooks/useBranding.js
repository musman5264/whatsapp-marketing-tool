import { usePage } from '@inertiajs/react';

/**
 * Runtime brand values, shared from the server by HandleInertiaRequests.
 *
 * Always prefer this over `import.meta.env.VITE_APP_NAME`: Vite inlines env vars
 * at build time, so a bundled VITE_APP_NAME pins the brand to whatever the assets
 * were compiled with and silently ignores the admin panel's General settings.
 */
export function useBranding() {
    const { branding } = usePage().props;

    return {
        appName: branding?.app_name || '',
        tagline: branding?.app_tagline || '',
        supportEmail: branding?.support_email || '',
        primaryColor: branding?.primary_color || '',
        secondaryColor: branding?.secondary_color || '',
        fontFamily: branding?.font_family || '',
        logoUrl: branding?.logo_url || null,
        faviconUrl: branding?.favicon_url || null,
    };
}

/** Convenience accessor for the most common case. */
export function useAppName() {
    return useBranding().appName;
}
