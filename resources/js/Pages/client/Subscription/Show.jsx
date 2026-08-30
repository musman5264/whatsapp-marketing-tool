import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Package, ArrowRightCircle, CreditCard, ChevronDown, FileText, RefreshCw } from 'lucide-react';
import { formatDateTz } from '@/Utils/datetime';

function formatCurrency(cents, currency = 'USD') {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: (currency ?? 'USD').toUpperCase() }).format(cents / 100);
}

function ChangePlanModal({ subscription, plans, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({
        plan_id: subscription?.plan?.id ?? '',
        billing_cycle: subscription?.billing_cycle ?? 'month',
    });

    const [couponCode, setCouponCode] = useState('');
    const [couponStatus, setCouponStatus] = useState(null);

    const checkCoupon = async () => {
        if (!couponCode) return;
        const res = await fetch(route('client.coupon.check'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
            body: JSON.stringify({ code: couponCode, plan_id: data.plan_id }),
        });
        const json = await res.json();
        setCouponStatus(json);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('client.subscription.change-plan'), { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div className="bg-white dark:bg-neutral-800 rounded-xl p-6 w-full max-w-md shadow-xl">
                <h2 className="text-lg font-semibold text-neutral-900 dark:text-white mb-4">{t('subscription.change_plan')}</h2>
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('subscription.plan_label')}</label>
                        <select
                            value={data.plan_id}
                            onChange={e => setData('plan_id', e.target.value)}
                            className="w-full border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white"
                        >
                            {plans.map(p => (
                                <option key={p.id} value={p.id}>{p.name}</option>
                            ))}
                        </select>
                        {errors.plan_id && <p className="text-coral-600 text-xs mt-1">{errors.plan_id}</p>}
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('subscription.billing_cycle')}</label>
                        <div className="flex gap-2">
                            {['month', 'year'].map(cycle => (
                                <button
                                    key={cycle}
                                    type="button"
                                    onClick={() => setData('billing_cycle', cycle)}
                                    className={`flex-1 py-2 px-3 text-sm rounded-soft border ${data.billing_cycle === cycle ? 'border-brand-600 bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300' : 'border-neutral-300 dark:border-neutral-600'}`}
                                >
                                    {cycle === 'month' ? t('subscription.cycle_monthly') : t('subscription.cycle_annual')}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('subscription.coupon_code_label')}</label>
                        <div className="flex gap-2">
                            <input
                                type="text"
                                value={couponCode}
                                onChange={e => { setCouponCode(e.target.value.toUpperCase()); setCouponStatus(null); }}
                                className="flex-1 border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white font-mono"
                                placeholder="SAVE20"
                            />
                            <button type="button" onClick={checkCoupon} className="px-3 py-2 text-sm bg-neutral-100 dark:bg-neutral-700 border border-neutral-300 dark:border-neutral-600 rounded-lg hover:bg-neutral-200 dark:hover:bg-neutral-600">
                                {t('subscription.apply')}
                            </button>
                        </div>
                        {couponStatus && (
                            <p className={`text-xs mt-1 ${couponStatus.valid ? 'text-green-600 dark:text-green-400' : 'text-coral-600'}`}>
                                {couponStatus.valid ? t('subscription.coupon_applied', { amount: couponStatus.kind === 'percent' ? couponStatus.amount + '%' : '$' + (couponStatus.amount / 100).toFixed(2) }) : couponStatus.message}
                            </p>
                        )}
                    </div>
                    <p className="text-xs text-neutral-500 dark:text-neutral-400">
                        {t('subscription.proration_note')}
                    </p>
                    <div className="flex justify-end gap-2 pt-2">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-sm border border-neutral-300 dark:border-neutral-600 rounded-lg hover:bg-neutral-50 dark:hover:bg-neutral-700">
                            {t('common.cancel')}
                        </button>
                        <button type="submit" disabled={processing} className="px-4 py-2 text-sm bg-brand-500 text-white rounded-soft hover:bg-brand-600 shadow-soft disabled:opacity-50 transition-all duration-150">
                            {t('subscription.confirm_change')}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

export default function SubscriptionShow({ subscription, canCancel, canUpgrade, plans = [], transactions = [] }) {
    const { t } = useTranslation();
    const { flash, timezone } = usePage().props;
    const userTz = timezone || 'Asia/Dhaka';
    const formatDate = (iso) => formatDateTz(iso, userTz);
    const [showChangePlan, setShowChangePlan] = useState(false);

    const handleCancel = () => {
        if (!confirm(t('client.cancel_subscription_confirm') || 'Are you sure you want to cancel your subscription?')) return;
        router.delete(route('client.subscription.destroy'));
    };

    return (
        <ClientLayout title={t('subscription.page_title') || 'Subscription'}>
            <Head title={t('subscription.page_title') || 'Subscription'} />
            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">
                        {t('subscription.page_title') || 'Subscription'}
                    </h1>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('client.subscription_subtitle') || 'Your current plan and billing'}
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-200 px-4 py-3 text-sm">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-lg bg-coral-50 dark:bg-coral-900/20 text-coral-800 dark:text-coral-200 px-4 py-3 text-sm">
                        {flash.error}
                    </div>
                )}

                {/* Current Plan Card */}
                {subscription ? (
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/50 p-6">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <span className="flex h-12 w-12 items-center justify-center rounded-soft-lg bg-brand-50 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400">
                                    <Package className="h-6 w-6" />
                                </span>
                                <div>
                                    <h2 className="text-lg font-semibold text-neutral-900 dark:text-white">
                                        {subscription.plan.name}
                                        {subscription.billing_cycle && (
                                            <span className="ml-2 text-xs font-normal text-neutral-500 dark:text-neutral-400 capitalize">
                                                ({subscription.billing_cycle}ly)
                                            </span>
                                        )}
                                    </h2>
                                    <div className="flex flex-wrap items-center gap-2 mt-0.5">
                                        {subscription.status === 'trialing' ? (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 dark:bg-amber-900/30 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                                {t('subscription.free_trial')}
                                            </span>
                                        ) : subscription.status === 'active' ? (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-green-100 dark:bg-green-900/30 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-300">
                                                {t('common.active')}
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-neutral-100 dark:bg-neutral-700 px-2 py-0.5 text-xs font-medium text-neutral-600 dark:text-neutral-400 capitalize">
                                                {subscription.status}
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">
                                        {subscription.status === 'trialing' && subscription.trial_ends_at &&
                                            t('subscription.trial_ends', { date: formatDate(subscription.trial_ends_at) })}
                                        {subscription.status !== 'trialing' && subscription.renews_at &&
                                            t('subscription.renews_on', { date: formatDate(subscription.renews_at) })}
                                        {subscription.ends_at && !subscription.renews_at && subscription.status !== 'trialing' &&
                                            t('subscription.ends_on', { date: formatDate(subscription.ends_at) })}
                                    </p>
                                    {subscription.managed_by_admin && (
                                        <p className="text-xs text-amber-600 dark:text-amber-400 mt-1">
                                            {t('client.managed_by_admin') || 'Managed by your organization admin'}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {canUpgrade && !subscription.managed_by_admin && (
                                    <button
                                        type="button"
                                        onClick={() => setShowChangePlan(true)}
                                        className="inline-flex items-center gap-2 rounded-soft bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 shadow-soft transition-all duration-150"
                                    >
                                        <RefreshCw className="h-4 w-4" />
                                        {t('client.change_plan') || 'Change Plan'}
                                    </button>
                                )}
                                {canUpgrade && !subscription.managed_by_admin && (
                                    <Link
                                        href={route('client.pricing')}
                                        className="inline-flex items-center gap-2 rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-700"
                                    >
                                        {t('client.view_plans') || 'View all plans'}
                                        <ArrowRightCircle className="h-4 w-4" />
                                    </Link>
                                )}
                                {canCancel && (
                                    <button
                                        type="button"
                                        onClick={handleCancel}
                                        className="inline-flex items-center rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-700"
                                    >
                                        {t('client.cancel_subscription') || 'Cancel subscription'}
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/50 p-6 text-center">
                        <p className="text-neutral-500 dark:text-neutral-400 mb-4">
                            {t('client.no_plan') || 'No active subscription'}
                        </p>
                        <Link
                            href={route('client.pricing')}
                            className="inline-flex items-center gap-2 rounded-soft bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 shadow-soft transition-all duration-150"
                        >
                            <CreditCard className="h-4 w-4" />
                            {t('client.view_plans') || 'View plans'}
                        </Link>
                    </div>
                )}

                {/* Billing History */}
                {transactions.length > 0 && (
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/50 overflow-hidden">
                        <div className="px-6 py-4 border-b border-neutral-200 dark:border-neutral-700">
                            <h3 className="text-base font-semibold text-neutral-900 dark:text-white">{t('subscription.billing_history')}</h3>
                        </div>
                        <table className="w-full text-sm">
                            <thead className="bg-neutral-50 dark:bg-neutral-700/50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('subscription.col_date')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('subscription.col_amount')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('subscription.col_status')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('subscription.col_invoice')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700">
                                {transactions.map(tx => (
                                    <tr key={tx.id}>
                                        <td className="px-4 py-3 text-neutral-700 dark:text-neutral-300">
                                            {formatDate(tx.created_at)}
                                        </td>
                                        <td className="px-4 py-3 text-neutral-700 dark:text-neutral-300">
                                            {formatCurrency(tx.amount_cents, tx.currency)}
                                            {tx.refunded_cents && (
                                                <span className="ml-2 text-xs text-coral-600">
                                                    {t('subscription.refunded_note', { amount: formatCurrency(tx.refunded_cents, tx.currency) })}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium capitalize ${
                                                tx.status === 'paid' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' :
                                                tx.status === 'refunded' ? 'bg-coral-100 text-coral-700 dark:bg-coral-900/30 dark:text-coral-400' :
                                                'bg-neutral-100 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-400'
                                            }`}>
                                                {tx.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {tx.invoice_url ? (
                                                <a href={tx.invoice_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-brand-600 dark:text-brand-400 hover:underline text-xs">
                                                    <FileText className="h-3.5 w-3.5" />
                                                    PDF
                                                </a>
                                            ) : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="px-4 py-3 border-t border-neutral-100 dark:border-neutral-700">
                            <Link href={route('client.billing.index')} className="text-sm text-brand-600 dark:text-brand-400 hover:underline">
                                {t('subscription.view_full_billing')} →
                            </Link>
                        </div>
                    </div>
                )}

                {transactions.length === 0 && (
                    <Link
                        href={route('client.billing.index')}
                        className="inline-flex items-center gap-2 text-sm text-brand-600 dark:text-brand-400 hover:underline"
                    >
                        <CreditCard className="h-4 w-4" />
                        {t('client.view_billing_history') || 'View billing history'}
                    </Link>
                )}
            </div>

            {showChangePlan && (
                <ChangePlanModal
                    subscription={subscription}
                    plans={plans}
                    onClose={() => setShowChangePlan(false)}
                />
            )}
        </ClientLayout>
    );
}
