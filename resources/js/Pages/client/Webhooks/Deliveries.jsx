import { Fragment, useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, ChevronDown, ChevronUp, X } from 'lucide-react';
import { formatInTz } from '@/Utils/datetime';
import { useTranslation } from 'react-i18next';

// Shows JSON indented for reading. The stored bytes are kept exactly as sent; this only changes the display.
function prettyBody(raw) {
    try {
        return JSON.stringify(JSON.parse(raw), null, 2);
    } catch {
        return raw;
    }
}

const preClass = 'mt-1 max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-50 dark:bg-gray-900 p-3 text-xs text-gray-800 dark:text-gray-200';
const labelClass = 'text-xs font-medium text-gray-500 dark:text-gray-400';

export default function WebhookDeliveries({ endpoint, deliveries }) {
    const { t } = useTranslation();
    const userTz = usePage().props.timezone || 'Asia/Dhaka';
    const [openId, setOpenId] = useState(null);

    return (
        <ClientLayout title={t('webhook.deliveries_title')}>
            <Head title={t('webhook.deliveries_title')} />
            <div className="space-y-6 max-w-4xl">
                <div className="flex items-center gap-3">
                    <Link href={route('client.webhooks.index')} className="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <div>
                        <h1 className="text-xl font-bold text-gray-900 dark:text-white">{t('webhook.deliveries_heading')}</h1>
                        <p className="text-sm text-gray-500 dark:text-gray-400 font-mono truncate">{endpoint.url}</p>
                    </div>
                </div>

                <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{t('webhook.col_status')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{t('webhook.col_event')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{t('webhook.col_http')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{t('webhook.col_attempts')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{t('webhook.col_date')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{t('webhook.col_details')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                {deliveries.data?.map(d => (
                                    <Fragment key={d.id}>
                                        <tr>
                                            <td className="px-4 py-3">
                                                {d.response_status >= 200 && d.response_status < 300
                                                    ? <span className="inline-flex items-center gap-1 text-green-600 text-xs"><Check className="h-3.5 w-3.5" /> {t('webhook.status_ok')}</span>
                                                    : <span className="inline-flex items-center gap-1 text-red-500 text-xs"><X className="h-3.5 w-3.5" /> {t('webhook.status_failed')}</span>
                                                }
                                            </td>
                                            <td className="px-4 py-3"><code className="text-xs">{d.event}</code></td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-gray-400">{d.response_status ?? '—'}</td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-gray-400">{d.attempts}</td>
                                            <td className="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">{formatInTz(d.created_at, userTz)}</td>
                                            <td className="px-4 py-3">
                                                <button
                                                    type="button"
                                                    onClick={() => setOpenId(openId === d.id ? null : d.id)}
                                                    aria-expanded={openId === d.id}
                                                    className="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                >
                                                    {openId === d.id ? <ChevronUp className="h-3.5 w-3.5" /> : <ChevronDown className="h-3.5 w-3.5" />}
                                                    {openId === d.id ? t('webhook.hide_details') : t('webhook.show_details')}
                                                </button>
                                            </td>
                                        </tr>
                                        {openId === d.id && (
                                            <tr>
                                                <td colSpan={6} className="px-4 py-4 bg-gray-50/60 dark:bg-gray-900/40">
                                                    <div className="space-y-4">
                                                        <div>
                                                            <div className={labelClass}>{t('webhook.request_headers')}</div>
                                                            <pre className={preClass}>{JSON.stringify(d.request_headers ?? {}, null, 2)}</pre>
                                                        </div>
                                                        <div>
                                                            <div className={labelClass}>{t('webhook.request_body')}</div>
                                                            <pre className={preClass}>{d.request_body ? prettyBody(d.request_body) : t('webhook.no_body')}</pre>
                                                        </div>
                                                        <div>
                                                            <div className={labelClass}>
                                                                {t('webhook.response_body')}{d.response_status ? ` (${d.response_status})` : ''}
                                                            </div>
                                                            <pre className={preClass}>{d.response_body || t('webhook.no_body')}</pre>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                                {!deliveries.data?.length && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-8 text-center text-gray-400 dark:text-gray-500">{t('webhook.no_deliveries')}</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </ClientLayout>
    );
}
