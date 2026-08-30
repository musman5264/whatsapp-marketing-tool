import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';

const STORAGE_KEY = 'cookie_consent';

export default function CookieConsent() {
    const { t } = useTranslation();
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (!localStorage.getItem(STORAGE_KEY)) {
            setVisible(true);
        }
    }, []);

    const accept = () => {
        localStorage.setItem(STORAGE_KEY, 'accepted');
        setVisible(false);
    };

    const decline = () => {
        localStorage.setItem(STORAGE_KEY, 'declined');
        setVisible(false);
    };

    if (!visible) return null;

    return (
        <div className="fixed bottom-0 left-0 right-0 z-50 flex items-center justify-between gap-4 bg-neutral-900 text-white px-6 py-4 text-sm shadow-lg">
            <p className="flex-1">
                {t('ui.cookie_consent_message')}{' '}
                <a href="/p/privacy" className="underline text-brand-400 hover:text-brand-300">{t('ui.cookie_privacy_policy')}</a>
            </p>
            <div className="flex gap-2 shrink-0">
                <button
                    onClick={decline}
                    className="px-3 py-1.5 rounded-soft border border-neutral-600 text-neutral-300 hover:bg-neutral-700 text-xs"
                >
                    {t('ui.cookie_decline')}
                </button>
                <button
                    onClick={accept}
                    className="px-3 py-1.5 rounded-soft bg-brand-500 text-white hover:bg-brand-600 text-xs font-medium"
                >
                    {t('ui.cookie_accept')}
                </button>
            </div>
        </div>
    );
}
