import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import ApiTokensTabs from '@/Components/client/ApiTokensTabs';
import Pagination from '@/Components/ui/Pagination';
import { DatePicker } from '@/Components/ui';
import { formatInTz } from '@/Utils/datetime';
import { useTranslation } from 'react-i18next';
import { X } from 'lucide-react';

const STATUS_BADGE = (status) => {
    if (status >= 500) return 'text-red-700 bg-red-50 dark:bg-red-900/20 dark:text-red-300';
    if (status >= 400) return 'text-amber-700 bg-amber-50 dark:bg-amber-900/20 dark:text-amber-300';
    if (status >= 300) return 'text-blue-700 bg-blue-50 dark:bg-blue-900/20 dark:text-blue-300';
    return 'text-green-700 bg-green-50 dark:bg-green-900/20 dark:text-green-300';
};

function StatTile({ label, value }) {
    return (
        <div className="rounded-soft border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 px-4 py-3">
            <div className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{label}</div>
            <div className="mt-1 text-lg font-semibold text-neutral-900 dark:text-white tabular-nums truncate">{value}</div>
        </div>
    );
}

function prettyJson(raw) {
    if (raw == null) return null;
    try { return JSON.stringify(JSON.parse(raw), null, 2); } catch { return String(raw); }
}

function DetailPanel({ log, onClose, t, formatDate }) {
    const [tab, setTab] = useState('request');
    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-black/40" onClick={onClose}>
            <div
                className="h-full w-full max-w-2xl overflow-y-auto bg-white dark:bg-neutral-900 shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-neutral-200 dark:border-neutral-700 px-6 py-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-sm font-semibold">{log.method}</span>
                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_BADGE(log.status)}`}>{log.status}</span>
                            <span className="text-xs text-neutral-500">{log.duration_ms} ms</span>
                        </div>
                        <div className="mt-1 truncate font-mono text-xs text-neutral-600 dark:text-neutral-400">/{log.path}</div>
                    </div>
                    <button onClick={onClose} className="p-1 text-neutral-400 hover:text-neutral-600"><X className="h-5 w-5" /></button>
                </div>

                <div className="px-6 py-3 text-xs text-neutral-500 dark:text-neutral-400 space-y-1">
                    <div>{formatDate(log.created_at)} · {log.ip || '—'} · {log.token_name || t('api.detail_deleted_token')}</div>
                    {log.error_class && <div className="text-red-600 dark:text-red-400">{t('api.detail_error_class')}: <span className="font-mono">{log.error_class}</span></div>}
                    {log.response_size_bytes != null && <div>{t('api.detail_response_size')}: {log.response_size_bytes} B</div>}
                </div>

                <div className="border-b border-neutral-200 dark:border-neutral-700 px-6">
                    <nav className="-mb-px flex gap-6">
                        {['request', 'response'].map((k) => (
                            <button
                                key={k}
                                onClick={() => setTab(k)}
                                className={`border-b-2 px-1 py-2 text-sm font-medium ${tab === k ? 'border-brand-500 text-brand-600 dark:text-brand-400' : 'border-transparent text-neutral-500'}`}
                            >
                                {k === 'request' ? t('api.detail_request') : t('api.detail_response')}
                            </button>
                        ))}
                    </nav>
                </div>

                <div className="px-6 py-4 space-y-4">
                    {tab === 'request' && (
                        <>
                            {log.query && Object.keys(log.query).length > 0 && (
                                <Section title={t('api.detail_query')}>
                                    <Pre>{JSON.stringify(log.query, null, 2)}</Pre>
                                </Section>
                            )}
                            <Section title={t('api.detail_headers')}>
                                <Pre>{JSON.stringify(log.request_headers ?? {}, null, 2)}</Pre>
                            </Section>
                            <Section title={t('api.detail_body')}>
                                {log.request_body
                                    ? <Pre>{prettyJson(log.request_body)}</Pre>
                                    : <Empty>{t('api.detail_no_body')}</Empty>}
                            </Section>
                        </>
                    )}
                    {tab === 'response' && (
                        <Section title={t('api.detail_body')}>
                            {log.response_body
                                ? <Pre>{prettyJson(log.response_body)}</Pre>
                                : <Empty>{t('api.detail_no_body')}</Empty>}
                        </Section>
                    )}
                </div>
            </div>
        </div>
    );
}

const Section = ({ title, children }) => (
    <div>
        <div className="mb-1 text-xs font-semibold uppercase text-neutral-500 dark:text-neutral-400">{title}</div>
        {children}
    </div>
);
const Pre = ({ children }) => (
    <pre className="overflow-x-auto rounded-soft bg-neutral-50 dark:bg-neutral-800 p-3 text-xs text-neutral-800 dark:text-neutral-200 whitespace-pre-wrap break-all">{children}</pre>
);
const Empty = ({ children }) => (
    <p className="rounded-soft bg-neutral-50 dark:bg-neutral-800 p-3 text-xs italic text-neutral-500">{children}</p>
);

export default function ApiUsage({ logs, filters = {}, tokens = [], stats, selected = null }) {
    const { t } = useTranslation();
    const userTz = usePage().props.timezone || 'Asia/Dhaka';
    const formatDate = (iso) => iso
        ? formatInTz(iso, userTz, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })
        : '—';

    const [form, setForm] = useState({
        token_id: filters.token_id ?? '',
        method: filters.method ?? '',
        status: filters.status ?? '',
        path: filters.path ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    const submit = (e) => {
        e?.preventDefault();
        const params = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v != null));
        router.get(route('client.api-usage.index'), params, { preserveState: true, preserveScroll: true });
    };

    const clear = () => {
        setForm({ token_id: '', method: '', status: '', path: '', from: '', to: '' });
        router.get(route('client.api-usage.index'), {}, { preserveState: true, preserveScroll: true });
    };

    const openRow = (id) => router.get(route('client.api-usage.show', id), {}, {
        preserveState: true, preserveScroll: true, only: ['selected'],
    });
    const closeRow = () => router.get(route('client.api-usage.index'),
        Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
        { preserveState: true, preserveScroll: true, only: ['selected', 'logs'] });

    const topPath = stats?.top_paths?.[0];

    return (
        <ClientLayout title={t('api.usage_title')}>
            <Head title={t('api.usage_title')} />
            <div className="space-y-6 max-w-5xl">
                <ApiTokensTabs active="usage" />

                <div>
                    <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">{t('api.usage_title')}</h1>
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('api.usage_subtitle')}</p>
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <StatTile label={t('api.stat_calls_24h')} value={stats?.calls_24h ?? 0} />
                    <StatTile label={t('api.stat_error_rate')} value={`${Math.round((stats?.error_rate ?? 0) * 100)}%`} />
                    <StatTile label={t('api.stat_p95')} value={`${stats?.p95_ms ?? 0} ms`} />
                    <StatTile label={t('api.stat_top_path')} value={topPath ? `/${topPath.path}` : '—'} />
                </div>

                <form onSubmit={submit} className="flex flex-wrap items-end gap-2 rounded-soft border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-3">
                    <label className="text-xs">
                        <span className="block text-neutral-500 dark:text-neutral-400">{t('api.tab_tokens')}</span>
                        <select value={form.token_id} onChange={(e) => setForm({ ...form, token_id: e.target.value })} className="mt-1 rounded-soft border border-neutral-300 dark:border-neutral-600 px-2 py-1.5 text-sm dark:bg-neutral-700">
                            <option value="">{t('api.usage_all_tokens')}</option>
                            {tokens.map((tk) => <option key={tk.id} value={tk.id}>{tk.name}</option>)}
                        </select>
                    </label>
                    <label className="text-xs">
                        <span className="block text-neutral-500 dark:text-neutral-400">{t('api.filter_method')}</span>
                        <select value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })} className="mt-1 rounded-soft border border-neutral-300 dark:border-neutral-600 px-2 py-1.5 text-sm dark:bg-neutral-700">
                            <option value="">—</option>
                            {['GET', 'POST', 'PATCH', 'PUT', 'DELETE'].map((m) => <option key={m} value={m}>{m}</option>)}
                        </select>
                    </label>
                    <label className="text-xs">
                        <span className="block text-neutral-500 dark:text-neutral-400">{t('api.filter_status')}</span>
                        <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className="mt-1 rounded-soft border border-neutral-300 dark:border-neutral-600 px-2 py-1.5 text-sm dark:bg-neutral-700">
                            <option value="">{t('api.status_any')}</option>
                            <option value="2xx">{t('api.status_2xx')}</option>
                            <option value="3xx">{t('api.status_3xx')}</option>
                            <option value="4xx">{t('api.status_4xx')}</option>
                            <option value="5xx">{t('api.status_5xx')}</option>
                        </select>
                    </label>
                    <label className="text-xs">
                        <span className="block text-neutral-500 dark:text-neutral-400">{t('api.filter_path')}</span>
                        <input value={form.path} onChange={(e) => setForm({ ...form, path: e.target.value })} className="mt-1 rounded-soft border border-neutral-300 dark:border-neutral-600 px-2 py-1.5 text-sm dark:bg-neutral-700" placeholder="api/v1/contacts" />
                    </label>
                    <label className="text-xs">
                        <span className="block text-neutral-500 dark:text-neutral-400">{t('api.filter_from')}</span>
                        <div className="mt-1"><DatePicker value={form.from} onChange={(v) => setForm({ ...form, from: v })} /></div>
                    </label>
                    <label className="text-xs">
                        <span className="block text-neutral-500 dark:text-neutral-400">{t('api.filter_to')}</span>
                        <div className="mt-1"><DatePicker value={form.to} onChange={(v) => setForm({ ...form, to: v })} /></div>
                    </label>
                    <button type="submit" className="rounded-soft bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">{t('api.filter_apply')}</button>
                    <button type="button" onClick={clear} className="rounded-soft border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm text-neutral-600 dark:text-neutral-300">{t('api.filter_clear')}</button>
                </form>

                <div className="rounded-soft border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 overflow-hidden">
                    {logs.data.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="bg-neutral-50 dark:bg-neutral-700/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('api.col_time')}</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('api.col_token')}</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('api.col_method')}</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('api.col_path')}</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('api.col_status')}</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('api.col_duration')}</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('api.col_ip')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700">
                                    {logs.data.map((row) => (
                                        <tr key={row.id} onClick={() => openRow(row.id)} className="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-700/40">
                                            <td className="px-4 py-3 whitespace-nowrap text-xs text-neutral-500 dark:text-neutral-400">{formatDate(row.created_at)}</td>
                                            <td className="px-4 py-3 text-xs text-neutral-600 dark:text-neutral-300 max-w-[10rem] truncate">{row.token_name || t('api.detail_deleted_token')}</td>
                                            <td className="px-4 py-3 font-mono text-xs">{row.method}</td>
                                            <td className="px-4 py-3 font-mono text-xs text-neutral-700 dark:text-neutral-300 max-w-[16rem] truncate">/{row.path}</td>
                                            <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_BADGE(row.status)}`}>{row.status}</span></td>
                                            <td className="px-4 py-3 text-xs tabular-nums text-neutral-600 dark:text-neutral-400">{row.duration_ms} ms</td>
                                            <td className="px-4 py-3 text-xs text-neutral-500 dark:text-neutral-400">{row.ip || '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="py-10 text-center text-neutral-400">{t('api.usage_no_rows')}</div>
                    )}
                    {logs.data.length > 0 && <Pagination data={logs} className="px-4" />}
                </div>

                <p className="rounded-soft border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/50 p-3 text-xs text-neutral-500 dark:text-neutral-400">
                    {t('api.usage_retention_note')}
                </p>
            </div>

            {selected && <DetailPanel log={selected} onClose={closeRow} t={t} formatDate={formatDate} />}
        </ClientLayout>
    );
}
