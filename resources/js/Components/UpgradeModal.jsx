import { useEffect, useState } from 'react';
import { usePage, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function UpgradeModal() {
    const { t } = useTranslation();
    const { flash } = usePage().props;
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (flash?.upgrade_required) {
            setOpen(true);
        }
    }, [flash?.upgrade_required]);

    if (!open || !flash?.upgrade_required) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div className="bg-white dark:bg-neutral-900 rounded-2xl shadow-soft-xl border border-neutral-200 dark:border-neutral-800 max-w-md w-full p-6">
                <div className="flex items-start gap-4 mb-4">
                    <div className="flex-shrink-0 w-12 h-12 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                        <svg className="w-6 h-6 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                        </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                        <h2 className="text-lg font-semibold text-neutral-900 dark:text-white">
                            {t('ui.upgrade_modal_title')}
                        </h2>
                        {flash.upgrade_reason && (
                            <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                {flash.upgrade_reason}
                            </p>
                        )}
                    </div>
                    <button
                        onClick={() => setOpen(false)}
                        className="flex-shrink-0 text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition"
                        aria-label={t('common.dismiss')}
                    >
                        <svg className="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                        </svg>
                    </button>
                </div>

                <p className="text-sm text-neutral-600 dark:text-neutral-300 mb-6">
                    {t('ui.upgrade_modal_body')}
                </p>

                <div className="flex gap-3">
                    <Link
                        href={route('client.pricing')}
                        className="flex-1 inline-flex justify-center items-center px-4 py-2.5 bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium rounded-soft transition-all duration-150 shadow-soft"
                        onClick={() => setOpen(false)}
                    >
                        {t('ui.view_plans')}
                    </Link>
                    <button
                        onClick={() => setOpen(false)}
                        className="flex-1 inline-flex justify-center items-center px-4 py-2.5 bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-700 dark:text-neutral-200 text-sm font-medium rounded-soft transition-colors"
                    >
                        {t('ui.maybe_later')}
                    </button>
                </div>
            </div>
        </div>
    );
}
