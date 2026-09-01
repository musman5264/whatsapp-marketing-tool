import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft, CheckCircle, XCircle, Clock, SkipForward, ChevronRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Pagination from '@/Components/ui/Pagination';
import { formatInTz } from '@/Utils/datetime';

const STATUS_ICONS = {
    completed: <CheckCircle className="h-4 w-4 text-green-500" />,
    failed:    <XCircle className="h-4 w-4 text-red-500" />,
    running:   <Clock className="h-4 w-4 text-blue-500" />,
    pending:   <Clock className="h-4 w-4 text-yellow-500" />,
    cancelled: <SkipForward className="h-4 w-4 text-neutral-400" />,
};

const STATUS_BADGE = {
    completed: 'text-green-700 bg-green-50 dark:bg-green-900/20 dark:text-green-300',
    failed:    'text-red-700 bg-red-50 dark:bg-red-900/20 dark:text-red-300',
    running:   'text-blue-700 bg-blue-50 dark:bg-blue-900/20 dark:text-blue-300',
    pending:   'text-yellow-700 bg-yellow-50 dark:bg-yellow-900/20 dark:text-yellow-300',
    cancelled: 'text-neutral-500 bg-neutral-100 dark:bg-neutral-800 dark:text-neutral-400',
};

const LOG_COLORS = {
    ok:      'text-green-700 bg-green-50 dark:bg-green-900/20 dark:text-green-300',
    error:   'text-red-700 bg-red-50 dark:bg-red-900/20 dark:text-red-300',
    skipped: 'text-neutral-500 bg-neutral-50 dark:bg-neutral-800 dark:text-neutral-400',
};

function RunRow({ run, formatDate, t }) {
    const [open, setOpen] = useState(false);
    const hasLogs = (run.logs?.length ?? 0) > 0;
    const contactName = run.contact?.full_name?.trim() || run.contact?.phone_e164 || '–';

    return (
        <>
            <tr
                className={`bg-white dark:bg-neutral-900 ${hasLogs ? 'cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/60' : ''}`}
                onClick={() => hasLogs && setOpen(v => !v)}
            >
                <td className="px-4 py-3 w-8">
                    {hasLogs && (
                        <ChevronRight className={`h-4 w-4 text-neutral-400 transition-transform ${open ? 'rotate-90' : ''}`} />
                    )}
                </td>
                <td className="px-4 py-3 whitespace-nowrap font-medium text-neutral-900 dark:text-neutral-100 text-sm">
                    {t('automation.run_number', { id: run.id })}
                </td>
                <td className="px-4 py-3 whitespace-nowrap">
                    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[run.status] ?? STATUS_BADGE.pending}`}>
                        {STATUS_ICONS[run.status]}
                        {t(`automation.run_status_${run.status}`, { defaultValue: run.status })}
                    </span>
                </td>
                <td className="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-300 max-w-[12rem] truncate">
                    {contactName}
                </td>
                <td className="px-4 py-3 whitespace-nowrap text-xs text-neutral-500 dark:text-neutral-400">
                    {formatDate(run.started_at ?? run.created_at)}
                </td>
                <td className="px-4 py-3 whitespace-nowrap text-sm text-neutral-600 dark:text-neutral-400 tabular-nums">
                    {run.logs_count ?? run.logs?.length ?? 0}
                </td>
                <td className="px-4 py-3 text-xs text-red-600 dark:text-red-400 max-w-[16rem] truncate">
                    {run.error || ''}
                </td>
            </tr>
            {open && hasLogs && (
                <tr className="bg-neutral-50 dark:bg-neutral-800/40">
                    <td />
                    <td colSpan={6} className="px-4 py-3">
                        <div className="space-y-1">
                            {run.logs.map(log => (
                                <div key={log.id} className={`rounded px-3 py-1.5 text-xs flex items-start gap-2 ${LOG_COLORS[log.result] ?? ''}`}>
                                    <span className="font-mono font-semibold w-28 shrink-0">{log.node_type}</span>
                                    <span>{log.message}</span>
                                </div>
                            ))}
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

export default function AutomationRuns({ automation, runs }) {
    const { t } = useTranslation();
    const userTz = usePage().props.timezone || 'Asia/Dhaka';
    const formatDate = (iso) => iso
        ? formatInTz(iso, userTz, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
        : '–';

    return (
        <ClientLayout title={`${automation.name} · ${t('automation.runs')}`}>
            <Head title={`${t('automation.runs')} · ${automation.name}`} />
            <div className="space-y-5 max-w-5xl">
                <div className="flex items-center gap-3">
                    <Link href={route('client.automations.index')} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{automation.name} — {t('automation.runs')}</h2>
                    {runs.total != null && (
                        <span className="text-sm text-neutral-400">({runs.total})</span>
                    )}
                </div>

                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 overflow-hidden">
                    {runs.data.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700 text-left">
                                <thead className="bg-neutral-50 dark:bg-neutral-800">
                                    <tr>
                                        <th className="px-4 py-3 w-8" />
                                        <th className="px-4 py-3 text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase">{t('automation.col_run')}</th>
                                        <th className="px-4 py-3 text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase">{t('automation.col_status')}</th>
                                        <th className="px-4 py-3 text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase">{t('automation.col_contact')}</th>
                                        <th className="px-4 py-3 text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase">{t('automation.col_started')}</th>
                                        <th className="px-4 py-3 text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase">{t('automation.col_steps')}</th>
                                        <th className="px-4 py-3 text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase">{t('automation.col_error')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-neutral-200 dark:divide-neutral-700">
                                    {runs.data.map(run => (
                                        <RunRow key={run.id} run={run} formatDate={formatDate} t={t} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="py-10 text-center text-neutral-400">
                            {t('automation.no_runs_yet')}
                        </div>
                    )}

                    {runs.data.length > 0 && <Pagination data={runs} className="px-4" />}
                </div>
            </div>
        </ClientLayout>
    );
}
