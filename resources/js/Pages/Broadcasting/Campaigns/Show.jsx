import { Head, Link, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import {
    ArrowLeft,
    Play,
    Pause,
    BarChart2,
    Users,
    Pencil,
    Clock,
    ExternalLink,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { browserTz, formatInTz } from '@/Utils/datetime';

const STATUS_COLORS = {
    draft: 'bg-neutral-100 text-neutral-600',
    queued: 'bg-blue-100 text-blue-700',
    sending: 'bg-yellow-100 text-yellow-700',
    paused: 'bg-orange-100 text-orange-700',
    completed: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
};

const STATUS_LABEL_KEYS = {
    draft: 'campaign.status_draft',
    queued: 'campaign.status_queued',
    sending: 'campaign.status_sending',
    paused: 'campaign.status_paused',
    completed: 'campaign.status_completed',
    failed: 'campaign.status_failed',
};

function useCountdown(target) {
    const [remaining, setRemaining] = useState(() => calc(target));
    useEffect(() => {
        if (!target) return;
        const id = setInterval(() => setRemaining(calc(target)), 1000);
        return () => clearInterval(id);
    }, [target]);
    return remaining;
}

function calc(target) {
    if (!target) return null;
    const ms = new Date(target).getTime() - Date.now();
    if (ms <= 0) return null;
    const total = Math.floor(ms / 1000);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

export default function CampaignShow({ campaign, sample = [], reportUrl }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const userTz = props.timezone || browserTz() || 'Asia/Dhaka';
    const totals = campaign.totals_json ?? {};
    const total = totals.total || 1;

    // Auto-refresh while a campaign is actively sending so the user sees live progress.
    useEffect(() => {
        if (!['queued', 'sending'].includes(campaign.status)) return;
        const id = setInterval(() => {
            router.reload({ only: ['campaign', 'sample'] });
        }, 8000);
        return () => clearInterval(id);
    }, [campaign.status]);

    const countdown = useCountdown(
        campaign.status === 'queued' && campaign.schedule_at ? campaign.schedule_at : null,
    );

    const metrics = [
        { key: 'total', label: t('campaign.metric_total'), value: totals.total ?? 0, color: 'bg-neutral-500' },
        { key: 'sent', label: t('campaign.metric_sent'), value: totals.sent ?? 0, color: 'bg-blue-500' },
        { key: 'delivered', label: t('campaign.metric_delivered'), value: totals.delivered ?? 0, color: 'bg-green-500' },
        { key: 'read', label: t('campaign.metric_read'), value: totals.read ?? 0, color: 'bg-purple-500' },
        { key: 'failed', label: t('campaign.metric_failed'), value: totals.failed ?? 0, color: 'bg-red-500' },
    ];

    const handleLaunch = () =>
        router.post(route('client.campaigns.launch', campaign.uuid), {}, { preserveScroll: true });
    const handlePause = () =>
        router.post(route('client.campaigns.pause', campaign.uuid), {}, { preserveScroll: true });

    const canEdit = ['draft', 'paused'].includes(campaign.status);

    return (
        <ClientLayout title={campaign.name}>
            <Head title={t('campaign.show_head_title', { name: campaign.name })} />
            <div className="max-w-4xl space-y-6">
                <div className="flex flex-wrap items-center gap-3">
                    <Link
                        href={route('client.campaigns.index')}
                        className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition"
                    >
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                        {campaign.name}
                        <span
                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                                STATUS_COLORS[campaign.status] ?? ''
                            }`}
                        >
                            {STATUS_LABEL_KEYS[campaign.status] ? t(STATUS_LABEL_KEYS[campaign.status]) : campaign.status}
                        </span>
                    </h2>
                    <div className="ml-auto flex flex-wrap gap-2">
                        {canEdit && (
                            <Link
                                href={route('client.campaigns.edit', campaign.uuid)}
                                className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                            >
                                <Pencil className="h-4 w-4" /> {t('common.edit')}
                            </Link>
                        )}
                        {reportUrl && (
                            <a
                                href={reportUrl}
                                className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                            >
                                <BarChart2 className="h-4 w-4" /> {t('campaign.full_report')} <ExternalLink className="h-3 w-3" />
                            </a>
                        )}
                        {campaign.status === 'draft' && (
                            <button
                                onClick={handleLaunch}
                                className="flex items-center gap-1.5 rounded-lg bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 transition"
                            >
                                <Play className="h-4 w-4" /> {t('campaign.launch')}
                            </button>
                        )}
                        {campaign.status === 'sending' && (
                            <button
                                onClick={handlePause}
                                className="flex items-center gap-1.5 rounded-lg bg-orange-500 px-3 py-2 text-sm font-medium text-white hover:bg-orange-600 transition"
                            >
                                <Pause className="h-4 w-4" /> {t('campaign.pause')}
                            </button>
                        )}
                        {campaign.status === 'paused' && (
                            <button
                                onClick={handleLaunch}
                                className="flex items-center gap-1.5 rounded-lg bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 transition"
                            >
                                <Play className="h-4 w-4" /> {t('campaign.resume')}
                            </button>
                        )}
                    </div>
                </div>

                {countdown && (
                    <div className="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-900/20 px-4 py-3 text-sm text-blue-800 dark:text-blue-200">
                        <Clock className="h-4 w-4" />
                        <span>
                            {t('campaign.sending_in')} <span className="font-mono font-semibold">{countdown}</span>
                            {` (${campaign.timezone || userTz})`}
                        </span>
                    </div>
                )}

                {/* Delivery funnel */}
                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-6">
                    <h3 className="font-medium text-neutral-800 dark:text-neutral-200 mb-4 flex items-center gap-2">
                        <BarChart2 className="h-4 w-4" /> {t('campaign.delivery_report')}
                    </h3>
                    {(totals.total ?? 0) === 0 ? (
                        <EmptyState
                            icon={<Users className="h-8 w-8" />}
                            title={t('campaign.no_recipients_title')}
                            description={t('campaign.no_recipients_desc')}
                        />
                    ) : (
                        <div className="space-y-3">
                            {metrics.map((m) => {
                                const pct =
                                    m.key === 'total' ? 100 : Math.round((m.value / total) * 100);
                                return (
                                    <div key={m.key}>
                                        <div className="flex justify-between text-sm mb-1">
                                            <span className="text-neutral-700 dark:text-neutral-300">
                                                {m.label}
                                            </span>
                                            <span className="font-medium text-neutral-900 dark:text-neutral-100">
                                                {m.value}{' '}
                                                <span className="text-neutral-400 font-normal">
                                                    ({pct}%)
                                                </span>
                                            </span>
                                        </div>
                                        <div className="h-2 rounded-full bg-neutral-100 dark:bg-neutral-800">
                                            <div
                                                className={`h-2 rounded-full ${m.color} transition-all`}
                                                style={{ width: `${pct}%` }}
                                            />
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* Recent recipients */}
                {sample.length > 0 && (
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5">
                        <h3 className="font-medium text-neutral-800 dark:text-neutral-200 mb-3">
                            {t('campaign.recent_activity')}
                        </h3>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-neutral-200 dark:border-neutral-700 text-left text-xs text-neutral-500">
                                        <th className="pb-2">{t('campaign.col_contact')}</th>
                                        <th className="pb-2">{t('campaign.col_status')}</th>
                                        <th className="pb-2">{t('campaign.col_last_update')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700/60">
                                    {sample.map((r) => {
                                        const c = r.contact ?? {};
                                        const name =
                                            `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim() ||
                                            c.phone_e164 ||
                                            c.email ||
                                            `#${r.contact_id}`;
                                        const last =
                                            r.read_at || r.delivered_at || r.sent_at || r.updated_at;
                                        return (
                                            <tr key={r.id}>
                                                <td className="py-2 text-neutral-800 dark:text-neutral-200">
                                                    <div>{name}</div>
                                                    <div className="text-xs text-neutral-400">
                                                        {c.phone_e164 || c.email}
                                                    </div>
                                                </td>
                                                <td className="py-2">
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-xs ${
                                                            STATUS_COLORS[r.status] ?? ''
                                                        }`}
                                                    >
                                                        {r.status}
                                                    </span>
                                                    {r.status === 'failed' && r.failed_reason && (
                                                        <div className="mt-1 text-xs text-red-500 max-w-[200px]" title={r.failed_reason}>
                                                            {r.failed_reason}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="py-2 text-neutral-500 text-xs">
                                                    {last ? formatInTz(last, userTz) : '—'}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Campaign details */}
                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5">
                    <h3 className="font-medium text-neutral-800 dark:text-neutral-200 mb-3">{t('campaign.details')}</h3>
                    <dl className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                        {[
                            [t('campaign.col_channel'), campaign.channel],
                            [
                                t('campaign.audience'),
                                `${campaign.audience_type}${
                                    campaign.audience_ref ? ` · ${campaign.audience_ref}` : ''
                                }`,
                            ],
                            [
                                t('campaign.col_scheduled'),
                                campaign.schedule_at
                                    ? (() => {
                                          const tz = campaign.timezone || userTz;
                                          const inTz = formatInTz(campaign.schedule_at, tz);
                                          const inUser =
                                              tz !== userTz
                                                  ? ` · ${formatInTz(campaign.schedule_at, userTz)} (${userTz})`
                                                  : '';
                                          return `${inTz} (${tz})${inUser}`;
                                      })()
                                    : t('campaign.on_demand'),
                            ],
                            [t('campaign.created'), formatInTz(campaign.created_at, userTz)],
                        ].map(([k, v]) => (
                            <div key={k} className="flex gap-3">
                                <dt className="w-28 shrink-0 font-medium text-neutral-500 dark:text-neutral-400">
                                    {k}
                                </dt>
                                <dd className="text-neutral-900 dark:text-neutral-100">{v}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            </div>
        </ClientLayout>
    );
}
