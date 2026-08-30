import { usePage } from '@inertiajs/react';

export default function ApplicationLogo({ className, style, alt }) {
    let logoUrl = null;
    try {
        // eslint-disable-next-line react-hooks/rules-of-hooks
        logoUrl = usePage().props.branding?.logo_url ?? null;
    } catch {
        logoUrl = null;
    }

    if (logoUrl) {
        // Callers size the SVG fallback as a square glyph (e.g. "h-9 w-9"), but an
        // uploaded logo is usually a wide wordmark: forcing that width distorts it.
        // Keep the caller's height/max bounds, let width follow the aspect ratio,
        // and drop the SVG-only paint classes.
        const imgClassName = [
            ...String(className ?? '')
                .split(/\s+/)
                .filter(Boolean)
                // Compare on the utility itself so variants (dark:, lg:) are matched
                // too. `max-w-*` survives — only a fixed `w-*` would force the ratio.
                .filter((c) => !/^(w|fill|text)-/.test(c.split(':').pop())),
            'w-auto',
            'object-contain',
        ].join(' ');

        return (
            <img
                src={logoUrl}
                alt={alt ?? 'App Logo'}
                className={imgClassName}
                style={style}
            />
        );
    }

    // Fallback brand mark: a generic chat bubble as a single-color silhouette.
    // The two text bars are knocked out via fill-rule="evenodd" so the whole glyph
    // takes a single `fill` — callers recolor it with `fill-current` + a text color
    // (white on the auth gradient, brand-600 in the topbar, etc.).
    return (
        <svg
            className={className}
            style={style}
            viewBox="0 0 512 512"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M136 48H376A72 72 0 0 1 448 120V284A72 72 0 0 1 376 356H284C280 408 262 452 222 459C201 463 186 450 182 427C179 404 178 378 166 356H136A72 72 0 0 1 64 284V120A72 72 0 0 1 136 48ZM219 150H301A21 21 0 0 1 301 192H219A21 21 0 0 1 219 150ZM171 234H341A21 21 0 0 1 341 276H171A21 21 0 0 1 171 234Z"
            />
        </svg>
    );
}
