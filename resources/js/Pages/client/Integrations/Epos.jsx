import ClientLayout from '@/Layouts/ClientLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, CheckCircle2, Link2, Loader2, RefreshCw } from 'lucide-react';
import { formatInTz } from '@/Utils/datetime';
import { pickLabel } from '@/lib/epos';

const fmtOpts = { dateStyle: undefined, timeStyle: undefined, year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: undefined };

function StatusBadge({ status }) {
    const { t } = useTranslation();
    const ok = status === 'connected';
    const Icon = ok ? CheckCircle2 : AlertTriangle;
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${ok
            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
            : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'}`}>
            <Icon className="h-3.5 w-3.5" aria-hidden="true" />
            {ok ? t('epos_status.status_connected') : status === 'unlinked' ? t('epos_status.status_unlinked') : t('epos_status.status_unreachable')}
        </span>
    );
}

function Fact({ label, children }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{label}</dt>
            <dd className="mt-0.5 truncate text-sm text-neutral-900 dark:text-neutral-100">{children}</dd>
        </div>
    );
}

const OUTCOME_STYLE = {
    found: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    not_found: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-700 dark:text-neutral-200',
    no_data: 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300',
};

/** Records what was sent to E-POS and what came back (numbers hidden) for one hour. Off by default. */
function DebugSwitch({ item, when }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    const active = Boolean(item.debug_until);
    const toggle = () => router.post(route('client.integrations.epos.debug', item.id), { on: !active }, {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
    });

    return (
        <div className="flex flex-wrap items-start gap-3 border-t border-neutral-100 px-4 py-3 dark:border-neutral-700">
            <div className="min-w-0 flex-1 basis-64">
                <h3 className="text-sm font-semibold text-neutral-900 dark:text-white">{t('epos_status.debug_title')}</h3>
                <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{t('epos_status.debug_hint')}</p>
                {active && (
                    <p role="status" className="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">
                        {t('epos_status.debug_active', { time: when(item.debug_until) })}
                    </p>
                )}
            </div>
            <button
                type="button"
                onClick={toggle}
                disabled={busy}
                aria-pressed={active}
                className="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-800 hover:bg-neutral-50 disabled:opacity-60 dark:border-neutral-600 dark:text-neutral-100 dark:hover:bg-neutral-700"
            >
                {active ? t('epos_status.debug_off') : t('epos_status.debug_on')}
            </button>
        </div>
    );
}

/** The last 50 calls. A row has "Details" only when debug recording was on for it. */
function CallsTable({ calls, tz, labelFor }) {
    const { t } = useTranslation();
    const time = (iso) => formatInTz(iso, tz, { ...fmtOpts, second: '2-digit' });

    return (
        <div className="border-t border-neutral-100 px-4 py-3 dark:border-neutral-700">
            <h3 className="text-sm font-semibold text-neutral-900 dark:text-white">{t('epos_status.calls_title')}</h3>
            {calls.length === 0 ? (
                <p className="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{t('epos_status.calls_empty')}</p>
            ) : (
                <div className="mt-2 overflow-x-auto">
                    <table className="w-full min-w-[34rem] text-start text-xs">
                        <thead>
                            <tr className="text-neutral-500 dark:text-neutral-400">
                                {['col_time', 'col_question', 'col_result', 'col_status', 'col_took'].map((c) => (
                                    <th key={c} scope="col" className="py-1.5 pe-3 text-start font-medium">{t(`epos_status.${c}`)}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700">
                            {calls.map((c) => (
                                <tr key={c.id} className="align-top text-neutral-800 dark:text-neutral-100">
                                    <td className="whitespace-nowrap py-1.5 pe-3">{time(c.created_at)}</td>
                                    <td className="py-1.5 pe-3" dir="auto">
                                        {labelFor(c.capability)}
                                        {c.message && <div className="text-neutral-500 dark:text-neutral-400" dir="auto">{c.message}</div>}
                                        {(c.request || c.response) && (
                                            <details className="mt-1">
                                                <summary className="cursor-pointer font-medium text-brand-600">{t('epos_status.details')}</summary>
                                                <div dir="ltr" className="mt-1 space-y-2">
                                                    {c.request && <div><div className="font-semibold">{t('epos_status.request_sent')}</div><pre className="max-h-48 overflow-auto whitespace-pre-wrap break-all rounded bg-neutral-100 p-2 font-mono dark:bg-neutral-900">{c.request}</pre></div>}
                                                    {c.response && <div><div className="font-semibold">{t('epos_status.answer_received')}</div><pre className="max-h-48 overflow-auto whitespace-pre-wrap break-all rounded bg-neutral-100 p-2 font-mono dark:bg-neutral-900">{c.response}</pre></div>}
                                                    <p className="text-neutral-500 dark:text-neutral-400">{t('epos_status.details_note')}</p>
                                                </div>
                                            </details>
                                        )}
                                    </td>
                                    <td className="py-1.5 pe-3"><span className={`rounded-full px-2 py-0.5 font-semibold ${OUTCOME_STYLE[c.outcome] ?? OUTCOME_STYLE.not_found}`}>{t(`epos_status.outcome_${c.outcome}`)}</span></td>
                                    <td className="py-1.5 pe-3 font-mono">{c.status}</td>
                                    <td className="whitespace-nowrap py-1.5 pe-3 font-mono">{c.ms} ms</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function IntegrationCard({ item, tz }) {
    const { t, i18n } = useTranslation();
    const [checking, setChecking] = useState(false);
    const unreachable = item.status === 'unreachable';
    const when = (iso) => (iso ? formatInTz(iso, tz, fmtOpts) : t('epos_status.never'));

    const check = () => router.post(route('client.integrations.epos.check', item.id), {}, {
        preserveScroll: true,
        onStart: () => setChecking(true),
        onFinish: () => setChecking(false),
    });

    return (
        <section className="rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-800/50" aria-labelledby={`epos-${item.id}`}>
            {unreachable && (
                <div role="alert" className="flex items-start gap-2 rounded-t-xl border-b border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-900/20 dark:text-red-200">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{t('automation.epos_unreachable_banner')}</span>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-3 p-4">
                <h2 id={`epos-${item.id}`} className="min-w-0 truncate text-base font-semibold text-neutral-900 dark:text-white" dir="auto">{item.name}</h2>
                <span className="shrink-0 rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{t(`settings.linked_system_${item.type}`)}</span>
                <StatusBadge status={item.status} />
                <button
                    type="button"
                    onClick={check}
                    disabled={checking}
                    className="ms-auto inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-60 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
                >
                    {checking ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <RefreshCw className="h-4 w-4" aria-hidden="true" />}
                    {checking ? t('epos_status.checking') : t('epos_status.check_now')}
                </button>
            </div>

            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 border-t border-neutral-100 px-4 py-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-neutral-700">
                <Fact label={t('epos_status.host')}><span className="font-mono text-xs">{item.host}</span></Fact>
                <Fact label={t('epos_status.version')}><span className="font-mono text-xs">{item.manifest_version ?? '—'}</span></Fact>
                <Fact label={t('epos_status.last_read')}>{when(item.manifest_fetched_at)}</Fact>
                <Fact label={t('epos_status.questions')}>{item.capabilities.length}</Fact>
                {unreachable && <Fact label={t('epos_status.retry_at')}>{when(item.next_retry_at)}</Fact>}
                {item.consecutive_failures > 0 && <Fact label={t('epos_status.failures')}>{item.consecutive_failures}</Fact>}
            </dl>

            {item.capabilities.length > 0 && (
                <details className="border-t border-neutral-100 px-4 py-3 dark:border-neutral-700">
                    <summary className="cursor-pointer text-sm font-medium text-neutral-700 dark:text-neutral-200">{t('epos_status.questions_list')}</summary>
                    <ul className="mt-3 grid gap-1.5 sm:grid-cols-2">
                        {item.capabilities.map((c) => (
                            <li key={c.key} className="flex items-center justify-between gap-2 rounded-lg bg-neutral-50 px-3 py-2 text-sm dark:bg-neutral-900/40">
                                <span className="min-w-0 truncate" dir="auto">{pickLabel(c.label, i18n.language)}</span>
                                <span className="shrink-0 rounded bg-neutral-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300">
                                    {c.kind === 'action' ? t('epos_status.action') : t('epos_status.query')}
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            <DebugSwitch item={item} when={when} />
            <CallsTable
                calls={item.calls ?? []}
                tz={tz}
                labelFor={(key) => {
                    if (key === 'manifest') return t('epos_status.manifest_row');
                    const cap = item.capabilities.find((c) => c.key === key);
                    return cap ? pickLabel(cap.label, i18n.language) : key;
                }}
            />

            {item.issues.length > 0 && (
                <div className="border-t border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/50 dark:bg-amber-900/10">
                    <h3 className="text-sm font-semibold text-amber-900 dark:text-amber-200">{t('epos_status.issues_title')}</h3>
                    <p className="mt-0.5 text-xs text-amber-800 dark:text-amber-300">{t('epos_status.issues_hint')}</p>
                    <ul className="mt-2 space-y-1">
                        {item.issues.map((issue, i) => (
                            <li key={`${issue.key}-${i}`} className="text-xs text-amber-900 dark:text-amber-200">
                                <span className="font-mono font-semibold">{issue.key}</span>
                                <span className="text-amber-700 dark:text-amber-400"> — {t('epos_status.reason')}: {issue.reason}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}

export default function EposStatus({ integrations = [] }) {
    const { t } = useTranslation();
    const { timezone } = usePage().props;
    const tz = timezone || 'Asia/Dhaka';

    return (
        <ClientLayout title={t('epos_status.title')}>
            <Head title={t('epos_status.title')} />
            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">{t('epos_status.title')}</h1>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{t('epos_status.subtitle')}</p>
                </div>

                {integrations.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-neutral-300 p-10 text-center dark:border-neutral-600">
                        <Link2 className="mx-auto h-8 w-8 text-neutral-400" aria-hidden="true" />
                        <h2 className="mt-3 text-base font-semibold text-neutral-900 dark:text-white">{t('epos_status.empty_title')}</h2>
                        <p className="mx-auto mt-1 max-w-md text-sm text-neutral-500 dark:text-neutral-400">{t('epos_status.empty_desc')}</p>
                    </div>
                ) : (
                    integrations.map((item) => <IntegrationCard key={item.id} item={item} tz={tz} />)
                )}
            </div>
        </ClientLayout>
    );
}
