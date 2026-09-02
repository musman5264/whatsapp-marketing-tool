import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Key, Activity } from 'lucide-react';

/**
 * Shared tab header for the API Tokens / API Usage pages. These are two separate
 * Inertia routes, not client-side tab state — each has its own server data load.
 */
export default function ApiTokensTabs({ active = 'tokens' }) {
    const { t } = useTranslation();

    const tabs = [
        { key: 'tokens', label: t('api.tab_tokens'), href: route('client.api-tokens.index'), icon: Key },
        { key: 'usage', label: t('api.tab_usage'), href: route('client.api-usage.index'), icon: Activity },
    ];

    return (
        <div className="border-b border-neutral-200 dark:border-neutral-700">
            <nav className="-mb-px flex gap-6">
                {tabs.map(({ key, label, href, icon: Icon }) => {
                    const isActive = key === active;
                    return (
                        <Link
                            key={key}
                            href={href}
                            className={`inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-medium transition-colors ${
                                isActive
                                    ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                                    : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200'
                            }`}
                        >
                            <Icon className="h-4 w-4" />
                            {label}
                        </Link>
                    );
                })}
            </nav>
        </div>
    );
}
