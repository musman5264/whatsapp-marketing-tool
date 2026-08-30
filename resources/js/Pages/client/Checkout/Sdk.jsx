import ClientLayout from '@/Layouts/ClientLayout';
import { Button, Card } from '@/Components/ui';
import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Loader2, AlertCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * Interstitial checkout page for gateways that have no hosted redirect URL and must launch
 * their JS SDK from a session id (currently Cashfree). It loads the SDK, starts the
 * authorization flow, and — after the customer authorizes — the gateway redirects back to
 * the return URL configured server-side (the billing page).
 */
const SDK_SRC = {
    cashfree: 'https://sdk.cashfree.com/js/v3/cashfree.js',
};

function loadScript(src) {
    return new Promise((resolve, reject) => {
        const existing = document.querySelector(`script[src="${src}"]`);
        if (existing) {
            if (existing.dataset.loaded === 'true') return resolve();
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', () => reject(new Error('Failed to load payment SDK.')));
            return;
        }
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => {
            script.dataset.loaded = 'true';
            resolve();
        };
        script.onerror = () => reject(new Error('Failed to load payment SDK.'));
        document.body.appendChild(script);
    });
}

async function launchCashfree({ session_id, mode }) {
    if (typeof window.Cashfree !== 'function') {
        throw new Error('Cashfree SDK unavailable.');
    }
    const cashfree = window.Cashfree({ mode: mode === 'production' ? 'production' : 'sandbox' });
    const options = { subscriptionSessionId: session_id, redirectTarget: '_self' };

    // Prefer the subscriptions flow; fall back to the generic checkout method across SDK builds.
    if (typeof cashfree.subscriptionsCheckout === 'function') {
        return cashfree.subscriptionsCheckout(options);
    }
    if (typeof cashfree.checkout === 'function') {
        return cashfree.checkout({ paymentSessionId: session_id, redirectTarget: '_self' });
    }
    throw new Error('Cashfree checkout method not found.');
}

export default function CheckoutSdk({ checkout = {}, plan_name = '', pricing_url = '/app/pricing' }) {
    const { t } = useTranslation();
    const [error, setError] = useState(null);
    const startedRef = useRef(false);

    useEffect(() => {
        if (startedRef.current) return;
        startedRef.current = true;

        const fallback = t('pricing.checkout_unavailable', 'Checkout could not be started.');

        const run = async () => {
            const provider = checkout?.provider;
            const src = SDK_SRC[provider];
            try {
                if (!src || !checkout?.session_id) {
                    throw new Error(fallback);
                }
                await loadScript(src);
                if (provider === 'cashfree') {
                    const result = await launchCashfree(checkout);
                    if (result?.error) {
                        throw new Error(result.error.message || fallback);
                    }
                }
            } catch (e) {
                setError(e?.message || fallback);
            }
        };

        run();
    }, [checkout, t]);

    return (
        <ClientLayout title={t('pricing.title')}>
            <Head title={t('pricing.redirecting', 'Redirecting…')} />
            <div className="mx-auto max-w-md py-16">
                <Card>
                    <Card.Body className="flex flex-col items-center gap-4 py-10 text-center">
                        {error ? (
                            <>
                                <AlertCircle className="h-10 w-10 text-coral-500" />
                                <h2 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                                    {t('pricing.checkout_failed', 'Checkout could not be started')}
                                </h2>
                                <p className="text-sm text-neutral-500 dark:text-neutral-400">{error}</p>
                                <Link href={pricing_url}>
                                    <Button variant="primary" size="sm">{t('common.back', 'Back to pricing')}</Button>
                                </Link>
                            </>
                        ) : (
                            <>
                                <Loader2 className="h-10 w-10 animate-spin text-brand-500" />
                                <h2 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                                    {t('pricing.redirecting', 'Redirecting to secure checkout…')}
                                </h2>
                                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                    {plan_name
                                        ? t('pricing.completing_for', { name: plan_name, defaultValue: `Completing your subscription to ${plan_name}.` })
                                        : t('pricing.do_not_close', 'Please do not close this window.')}
                                </p>
                            </>
                        )}
                    </Card.Body>
                </Card>
            </div>
        </ClientLayout>
    );
}
