import ClientLayout from '@/Layouts/ClientLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { CreditCard, Package, Download } from 'lucide-react';
import { formatDateTz } from '@/Utils/datetime';

function formatAmount(cents, currency = 'USD') {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency || 'USD',
    }).format((cents || 0) / 100);
}

export default function BillingIndex({ transactions }) {
    const { t } = useTranslation();
    const { timezone } = usePage().props;
    const { url } = usePage();
    const userTz = timezone || 'Asia/Dhaka';
    const formatDate = (iso) => formatDateTz(iso, userTz);
    const hasCheckoutSuccess = url.includes('checkout=success');

    return (
        <ClientLayout title={t('subscription.billing') || 'Billing'}>
            <Head title={t('subscription.billing') || 'Billing'} />
            <div className="space-y-6">
                {hasCheckoutSuccess && (
                    <div className="rounded-xl border border-green-200 bg-green-50 dark:bg-green-900/20 dark:border-green-800 px-4 py-3 text-sm text-green-800 dark:text-green-200">
                        {t('subscription.checkout_success')}
                    </div>
                )}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">
                            {t('subscription.billing') || 'Billing'}
                        </h1>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                            {t('client.billing_history') || 'Payment and invoice history'}
                        </p>
                    </div>
                    <Link
                        href={route('client.pricing')}
                        className="inline-flex items-center gap-2 rounded-soft bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 shadow-soft transition-all duration-150"
                    >
                        <CreditCard className="h-4 w-4" />
                        {t('client.view_plans') || 'View plans'}
                    </Link>
                </div>

                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/50 overflow-hidden">
                    {transactions.data?.length > 0 ? (
                        <table className="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                            <thead className="bg-neutral-50 dark:bg-neutral-800">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase">
                                        {t('client.date') || 'Date'}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase">
                                        {t('client.amount') || 'Amount'}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase">
                                        {t('client.plan') || 'Plan'}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase">
                                        {t('client.status') || 'Status'}
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase">
                                        {t('client.invoice') || 'Invoice'}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-neutral-200 dark:divide-neutral-700">
                                {transactions.data.map((tx) => (
                                    <tr key={tx.id} className="bg-white dark:bg-neutral-800/30">
                                        <td className="px-4 py-3 text-sm text-neutral-900 dark:text-white whitespace-nowrap">
                                            {formatDate(tx.created_at)}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-neutral-900 dark:text-white">
                                            {formatAmount(tx.amount_cents, tx.currency_code)}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">
                                            {tx.plan?.name ?? '–'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                                                    tx.status === 'completed' || tx.status === 'succeeded' || tx.status === 'paid'
                                                        ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200'
                                                        : 'bg-neutral-100 dark:bg-neutral-700 text-neutral-700 dark:text-neutral-300'
                                                }`}
                                            >
                                                {tx.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <a
                                                href={route('client.subscription.invoice', tx.id)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-2.5 py-1.5 text-xs font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-700 transition-colors"
                                            >
                                                <Download className="h-3.5 w-3.5" />
                                                PDF
                                            </a>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <div className="px-6 py-12 text-center text-neutral-500 dark:text-neutral-400">
                            <Package className="mx-auto h-12 w-12 text-neutral-400 mb-3" />
                            <p>{t('client.no_payments') || 'No payments yet'}</p>
                            <Link
                                href={route('client.pricing')}
                                className="mt-2 inline-flex text-sm text-brand-600 dark:text-brand-400 hover:underline"
                            >
                                {t('client.view_plans') || 'View plans'}
                            </Link>
                        </div>
                    )}
                </div>

                {transactions.data?.length > 0 && transactions.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {transactions.prev_page_url && (
                            <Link
                                href={transactions.prev_page_url}
                                className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-700"
                            >
                                {t('pagination.previous') || 'Previous'}
                            </Link>
                        )}
                        {transactions.next_page_url && (
                            <Link
                                href={transactions.next_page_url}
                                className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-700"
                            >
                                {t('pagination.next') || 'Next'}
                            </Link>
                        )}
                    </div>
                )}
            </div>
        </ClientLayout>
    );
}
