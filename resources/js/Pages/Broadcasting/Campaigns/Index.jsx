import { Head, Link, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import {
    Plus,
    Play,
    Pause,
    Trash2,
    BarChart2,
    Pencil,
    Radio,
} from 'lucide-react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { browserTz, formatInTz } from '@/Utils/datetime';
import { ChannelBrandIcon, CHANNEL_LABELS } from '@/Components/BrandIcons';

const STATUS_COLORS = {
    draft:     'bg-neutral-100 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300',
    queued:    'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    sending:   'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    paused:    'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    completed: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    failed:    'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

const STATUS_LABEL_KEYS = {
    draft: 'campaign.status_draft',
    queued: 'campaign.status_queued',
    sending: 'campaign.status_sending',
    paused: 'campaign.status_paused',
    completed: 'campaign.status_completed',
    failed: 'campaign.status_failed',
};

export default function CampaignsIndex({ campaigns, filters }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const userTz = props.timezone || browserTz() || 'Asia/Dhaka';

    const handleFilter = (key, val) =>
        router.get(
            route('client.campaigns.index'),
            { ...filters, [key]: val },
            { preserveState: true, replace: true },
        );
    const handleLaunch = (id) =>
        router.post(route('client.campaigns.launch', id), {}, { preserveScroll: true });
    const handlePause = (id) =>
        router.post(route('client.campaigns.pause', id), {}, { preserveScroll: true });
    const handleDelete = (id) => {
        if (confirm(t('campaign.delete_confirm'))) {
            router.delete(route('client.campaigns.destroy', id), { preserveScroll: true });
        }
    };

    // Auto-refresh while any campaign is actively sending so totals tick up.
    const liveCount = campaigns.data.filter((c) => ['queued', 'sending'].includes(c.status)).length;
    useEffect(() => {
        if (liveCount === 0) return;
        const id = setInterval(() => {
            router.reload({ only: ['campaigns'], preserveScroll: true });
        }, 10000);
        return () => clearInterval(id);
    }, [liveCount]);

    return (
        <ClientLayout title={t('campaign.title')}>
            <Head title={t('campaign.head_title')} />
            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        {t('campaign.title')}
                    </h2>
                    {(
                        <Link
                            href={route('client.campaigns.create')}
                            className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 transition"
                        >
                            <Plus className="h-4 w-4" /> {t('campaign.new_campaign')}
                        </Link>
                    )}
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">
                        {flash.success}
                    </div>
                )}

                {/* Filters */}
                <div className="flex flex-wrap gap-2">
                    <select
                        value={filters.channel ?? ''}
                        onChange={(e) => handleFilter('channel', e.target.value || null)}
                        className="rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm"
                    >
                        <option value="">{t('campaign.all_channels')}</option>
                        {['whatsapp', 'sms', 'email'].map((c) => (
                            <option key={c} value={c}>
                                {CHANNEL_LABELS[c] ?? c}
                            </option>
                        ))}
                    </select>
                    <select
                        value={filters.status ?? ''}
                        onChange={(e) => handleFilter('status', e.target.value || null)}
                        className="rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm"
                    >
                        <option value="">{t('campaign.all_statuses')}</option>
                        {['draft', 'queued', 'sending', 'paused', 'completed', 'failed'].map((s) => (
                            <option key={s} value={s}>
                                {t(STATUS_LABEL_KEYS[s])}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900">
                    <table className="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700 text-sm">
                        <thead className="bg-neutral-50 dark:bg-neutral-800">
                            <tr>
                                {[
                                    ['campaign', t('campaign.col_campaign')],
                                    ['channel', t('campaign.col_channel')],
                                    ['status', t('campaign.col_status')],
                                    ['recipients', t('campaign.col_recipients')],
                                    ['delivered', t('campaign.col_delivered')],
                                    ['scheduled', t('campaign.col_scheduled')],
                                    ['actions', ''],
                                ].map(([key, h]) => (
                                    <th
                                        key={key}
                                        className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500"
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                            {campaigns.data.map((c) => {
                                const totals = c.totals_json ?? {};
                                const deliveredPct =
                                    totals.total > 0
                                        ? Math.round((totals.delivered / totals.total) * 100)
                                        : 0;
                                const live = ['queued', 'sending'].includes(c.status);
                                const canEdit = ['draft', 'paused'].includes(c.status);
                                return (
                                    <tr key={c.id} className="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                        <td className="px-4 py-3 font-medium text-neutral-900 dark:text-neutral-100">
                                            <Link
                                                href={route('client.campaigns.show', c.uuid)}
                                                className="hover:text-brand-600"
                                            >
                                                {c.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="inline-flex items-center gap-1.5">
                                                <ChannelBrandIcon channel={c.channel} className="h-4 w-4 shrink-0" />
                                                {CHANNEL_LABELS[c.channel] ?? c.channel}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                                                    STATUS_COLORS[c.status] ?? ''
                                                }`}
                                            >
                                                {STATUS_LABEL_KEYS[c.status] ? t(STATUS_LABEL_KEYS[c.status]) : c.status}
                                            </span>
                                            {live && (
                                                <span className="ml-2 inline-flex items-center gap-1 text-xs text-yellow-700 dark:text-yellow-300">
                                                    <span className="relative flex h-2 w-2">
                                                        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75" />
                                                        <span className="relative inline-flex h-2 w-2 rounded-full bg-yellow-500" />
                                                    </span>
                                                    {t('campaign.live')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">
                                            {totals.total ?? 0}
                                        </td>
                                        <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">
                                            {totals.delivered ?? 0} ({deliveredPct}%)
                                        </td>
                                        <td className="px-4 py-3 text-neutral-400 text-xs">
                                            {c.schedule_at ? (
                                                <span title={c.timezone || userTz}>
                                                    {formatInTz(c.schedule_at, c.timezone || userTz)}
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-1">
                                                <Link
                                                    href={route('client.reports.campaigns.show', c.uuid)}
                                                    title={t('campaign.view_full_report')}
                                                    className="text-neutral-400 hover:text-brand-600 transition"
                                                >
                                                    <BarChart2 className="h-4 w-4" />
                                                </Link>
                                                {canEdit && (
                                                    <Link
                                                        href={route('client.campaigns.edit', c.uuid)}
                                                        title={t('common.edit')}
                                                        className="text-neutral-400 hover:text-brand-600 transition"
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </Link>
                                                )}
                                                {c.status === 'draft' && (
                                                    <button
                                                        onClick={() => handleLaunch(c.uuid)}
                                                        title={t('campaign.launch')}
                                                        className="text-neutral-400 hover:text-green-500 transition"
                                                    >
                                                        <Play className="h-4 w-4" />
                                                    </button>
                                                )}
                                                {c.status === 'sending' && (
                                                    <button
                                                        onClick={() => handlePause(c.uuid)}
                                                        title={t('campaign.pause')}
                                                        className="text-neutral-400 hover:text-orange-500 transition"
                                                    >
                                                        <Pause className="h-4 w-4" />
                                                    </button>
                                                )}
                                                {c.status === 'paused' && (
                                                    <button
                                                        onClick={() => handleLaunch(c.uuid)}
                                                        title={t('campaign.resume')}
                                                        className="text-neutral-400 hover:text-green-500 transition"
                                                    >
                                                        <Play className="h-4 w-4" />
                                                    </button>
                                                )}
                                                {c.status === 'draft' && (
                                                    <button
                                                        onClick={() => handleDelete(c.uuid)}
                                                        title={t('common.delete')}
                                                        className="text-neutral-400 hover:text-red-500 transition"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                            {campaigns.data.length === 0 && (
                                <tr>
                                    <td colSpan={7}>
                                        <EmptyState
                                            icon={<Radio className="h-8 w-8" />}
                                            title={t('campaign.empty_title')}
                                            description={t('campaign.empty_desc')}
                                            action={{
                                                label: t('campaign.new_campaign'),
                                                href: route('client.campaigns.create'),
                                            }}
                                        />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {campaigns.last_page > 1 && (
                    <div className="flex gap-1">
                        {campaigns.links.map((link, i) => (
                            <a
                                key={i}
                                href={link.url ?? '#'}
                                className={`px-3 py-1.5 rounded text-sm border ${
                                    link.active
                                        ? 'bg-brand-600 text-white border-brand-600'
                                        : 'border-neutral-300 dark:border-neutral-600 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800'
                                } ${!link.url ? 'opacity-40 pointer-events-none' : ''}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </ClientLayout>
    );
}
