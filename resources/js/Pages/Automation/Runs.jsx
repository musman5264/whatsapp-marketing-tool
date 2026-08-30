import { Head, Link, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft, CheckCircle, XCircle, Clock, SkipForward } from 'lucide-react';
import { useTranslation } from 'react-i18next';

const STATUS_ICONS = {
    completed: <CheckCircle className="h-4 w-4 text-green-500" />,
    failed:    <XCircle className="h-4 w-4 text-red-500" />,
    running:   <Clock className="h-4 w-4 text-blue-500" />,
    pending:   <Clock className="h-4 w-4 text-yellow-500" />,
    cancelled: <SkipForward className="h-4 w-4 text-neutral-400" />,
};

const LOG_COLORS = {
    ok:      'text-green-700 bg-green-50 dark:bg-green-900/20 dark:text-green-300',
    error:   'text-red-700 bg-red-50 dark:bg-red-900/20 dark:text-red-300',
    skipped: 'text-neutral-500 bg-neutral-50 dark:bg-neutral-800 dark:text-neutral-400',
};

export default function AutomationRuns({ automation, runs }) {
    const { t } = useTranslation();
    return (
        <ClientLayout title={`${automation.name} · ${t('automation.runs')}`}>
            <Head title={`${t('automation.runs')} · ${automation.name}`} />
            <div className="space-y-5 max-w-4xl">
                <div className="flex items-center gap-3">
                    <Link href={route('client.automations.index')} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{automation.name} — {t('automation.runs')}</h2>
                </div>

                <div className="space-y-3">
                    {runs.data.map(run => (
                        <div key={run.id} className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 space-y-3">
                            <div className="flex items-center gap-3">
                                {STATUS_ICONS[run.status]}
                                <span className="font-medium text-neutral-900 dark:text-neutral-100 text-sm">{t('automation.run_number', { id: run.id })}</span>
                                <span className="text-xs text-neutral-400">{run.started_at}</span>
                                {run.error && <span className="ml-auto text-xs text-red-600 dark:text-red-400">{run.error}</span>}
                            </div>
                            {run.logs?.length > 0 && (
                                <div className="space-y-1">
                                    {run.logs.map(log => (
                                        <div key={log.id} className={`rounded px-3 py-1.5 text-xs flex items-start gap-2 ${LOG_COLORS[log.result] ?? ''}`}>
                                            <span className="font-mono font-semibold w-24 shrink-0">{log.node_type}</span>
                                            <span>{log.message}</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                    {runs.data.length === 0 && (
                        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 py-10 text-center text-neutral-400">
                            {t('automation.no_runs_yet')}
                        </div>
                    )}
                </div>
            </div>
        </ClientLayout>
    );
}
