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
            {ok ? t('epos_status.status_connected') : t('epos_status.status_unreachable')}
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
