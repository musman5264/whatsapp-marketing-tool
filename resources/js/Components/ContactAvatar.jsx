import { useState } from 'react';

/** Round contact picture with the first letter of the name as the fallback (no photo, or the link no longer loads). */
export default function ContactAvatar({ url, name, className = '' }) {
    const [broken, setBroken] = useState(false);
    const base = `h-9 w-9 rounded-full ${className}`;

    if (url && !broken) {
        return <img src={url} alt="" loading="lazy" onError={() => setBroken(true)} className={`${base} object-cover bg-brand-100 dark:bg-brand-900/30`} />;
    }

    return (
        <div className={`${base} bg-brand-100 dark:bg-brand-900/30 flex items-center justify-center text-sm font-semibold text-brand-700 dark:text-brand-300`}>
            {name?.[0]?.toUpperCase() ?? '?'}
        </div>
    );
}
