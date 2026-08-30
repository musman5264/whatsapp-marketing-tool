import ClientLayout from '@/Layouts/ClientLayout';
import ProductTour from '@/Components/ProductTour';
import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import {
    Package,
    CreditCard,
    ArrowRightCircle,
    Users,
    Settings,
    User,
    FileText,
    Layers,
    CheckSquare,
    ArrowRight,
    Send,
    MessageSquare,
    Inbox,
    Contact as ContactIcon,
    Megaphone,
    Workflow,
    Sparkles,
    AlertCircle,
} from 'lucide-react';
import { LineChart, BarChart, DonutChart } from '@/Components/Charts';
import { RangeFilter, StatTile, WidgetCard, EmptyState } from '@/Components/Dashboard';

const fromCents = (c) => `$${((c || 0) / 100).toFixed(2)}`;
const spark = (arr, keys) =>
    (arr || []).map((d) => ({ v: keys.reduce((s, k) => s + Number(d[k] || 0), 0) }));
const sumRow = (d) => Object.entries(d).reduce((s, [k, v]) => (k === 'date' ? s : s + Number(v || 0)), 0);

function formatDate(iso) {
    if (!iso) return null;
    try {
        return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch {
        return iso;
    }
}

function relTime(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    } catch {
        return iso;
    }
}

const STEP_ROUTES = {
    verify_email: null,
    choose_plan: 'client.pricing',
    connect_first_channel: 'client.whatsapp.setup',
    import_first_contacts: 'client.contacts.index',
    send_first_message: 'client.campaigns.create',
    train_first_chatbot: 'client.ai.chatbots.index',
    connect_first_social_account: 'client.social.accounts.index',
};

const CONV_TONE = {
    open: 'text-emerald-600 dark:text-emerald-400',
    pending: 'text-amber-600 dark:text-amber-400',
    resolved: 'text-neutral-400',
    snoozed: 'text-blue-600 dark:text-blue-400',
};

const CAMPAIGN_TONE = {
    completed: 'text-emerald-600 dark:text-emerald-400',
    sending: 'text-blue-600 dark:text-blue-400',
    queued: 'text-amber-600 dark:text-amber-400',
    paused: 'text-amber-600 dark:text-amber-400',
    draft: 'text-neutral-400',
    failed: 'text-red-600 dark:text-red-400',
};

export default function Dashboard({
    range = 30,
    hasWorkspace = false,
    currentPlan = null,
    renewsAt = null,
    managedByAdmin = false,
    usage = {},
    isClientAdministrator = false,
    workspacesCount = 0,
    onboardingNextStep = null,
    onboardingPercent = 0,
    stats = null,
    charts = {},
    tables = {},
}) {
    const { t } = useTranslation();
    const { first_run = false } = usePage().props;
    const { team_members_count = 0, team_members_limit = 0 } = usage;
    const s = stats ?? {};

    const membersLabel = team_members_limit ? `${team_members_count} / ${team_members_limit}` : `${team_members_count}`;
    const membersPct = team_members_limit ? Math.min(100, Math.round((team_members_count / team_members_limit) * 100)) : null;

    const messageChannelKeys = [
        ...new Set((charts.messages ?? []).flatMap((d) => Object.keys(d).filter((k) => k !== 'date'))),
    ];
    const automationKeys = [
        ...new Set((charts.automation_runs ?? []).flatMap((d) => Object.keys(d).filter((k) => k !== 'date'))),
    ];
    const hasAutomationData = (charts.automation_runs ?? []).some((d) => sumRow(d) > 0);
    const hasSocialData = (charts.social_posts ?? []).length > 0;

    const tiles = [
        {
            label: t('client.kpi_messages_sent') || 'Messages sent',
            value: s.messages_out ?? 0,
            icon: Send,
            delta: s.messages_out_delta,
            sparkline: spark(charts.messages, messageChannelKeys),
            href: route('client.inbox.index'),
        },
        {
            label: t('client.kpi_messages_received') || 'Messages received',
            value: s.messages_in ?? 0,
            icon: MessageSquare,
            delta: s.messages_in_delta,
            href: route('client.inbox.index'),
        },
        {
            label: t('client.kpi_open_conversations') || 'Open conversations',
            value: s.conversations_open ?? 0,
            icon: Inbox,
            href: route('client.inbox.index'),
        },
        {
            label: t('client.kpi_new_conversations') || 'New conversations',
            value: s.conversations_new ?? 0,
            icon: MessageSquare,
            delta: s.conversations_new_delta,
            sparkline: (charts.conversations ?? []).map((d) => ({ v: Number(d.opened || 0) })),
        },
        {
            label: t('client.kpi_contacts') || 'Contacts',
            value: s.contacts_total ?? 0,
            icon: ContactIcon,
            delta: s.contacts_new_delta,
            hint: `+${s.contacts_new ?? 0} ${t('admin.this_period') || 'this period'}`,
            sparkline: spark(charts.contacts_growth, ['contacts']),
            href: route('client.contacts.index'),
        },
        {
            label: t('client.kpi_campaigns') || 'Campaigns',
            value: s.campaigns_total ?? 0,
            icon: Megaphone,
            href: route('client.campaigns.index'),
        },
        {
            label: t('client.kpi_automations') || 'Active automations',
            value: s.automations_active ?? 0,
            icon: Workflow,
            href: route('client.automations.index'),
        },
        {
            label: t('client.kpi_ai_runs') || 'AI runs',
            value: s.ai_runs ?? 0,
            icon: Sparkles,
            hint: `${fromCents(s.ai_cost_cents)} ${t('client.kpi_ai_cost') || 'cost'}`,
            sparkline: spark(charts.ai_tokens, ['prompt', 'completion']),
            href: route('client.ai.chatbots.index'),
        },
    ];

    return (
        <ClientLayout title={t('client.dashboard') || 'Dashboard'}>
            <ProductTour show={first_run} />
            <Head title={t('client.dashboard') || 'Dashboard'} />

            <div className="space-y-6">
                {/* Header + range filter */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">{t('client.dashboard') || 'Dashboard'}</h1>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                            {t('client.dashboard_subtitle') || 'Overview of your plan and usage'}
                        </p>
                    </div>
                    {hasWorkspace && <RangeFilter value={range} routeName="client.dashboard" />}
                </div>

                {/* Onboarding next-step nudge */}
                {onboardingNextStep && onboardingPercent < 100 && (
                    <div className="flex items-center gap-4 rounded-xl border border-brand-100 bg-brand-50 p-4 dark:border-brand-800 dark:bg-brand-900/20">
                        <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-brand-100 dark:bg-brand-900/40">
                            <CheckSquare className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-brand-900 dark:text-brand-100">
                                {t('client.next_step')} {onboardingNextStep.label}
                            </p>
                            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-brand-200 dark:bg-brand-800">
                                <div className="h-full rounded-full bg-brand-500" style={{ width: `${onboardingPercent}%` }} />
                            </div>
                        </div>
                        <div className="flex flex-shrink-0 items-center gap-2">
                            {STEP_ROUTES[onboardingNextStep.key] && (
                                <Link
                                    href={route(STEP_ROUTES[onboardingNextStep.key])}
                                    className="inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                                >
                                    {t('client.go')} <ArrowRight className="h-3.5 w-3.5" />
                                </Link>
                            )}
                            <Link href={route('client.onboarding.show')} className="text-xs text-brand-500 hover:underline dark:text-brand-400">
                                {t('client.view_all')}
                            </Link>
                        </div>
                    </div>
                )}

                {/* No-workspace notice */}
                {!hasWorkspace && (
                    <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200">
                        <AlertCircle className="mt-0.5 h-5 w-5 flex-shrink-0" />
                        <div>
                            <p className="font-medium">{t('client.no_workspace_title') || 'No active workspace'}</p>
                            <p className="mt-0.5">
                                {t('client.no_workspace_body') || 'Select or create a workspace to see your messaging analytics.'}{' '}
                                <Link href={route('client.workspaces.index')} className="font-medium underline">
                                    {t('client.manage_workspaces') || 'Manage workspaces'}
                                </Link>
                            </p>
                        </div>
                    </div>
                )}

                {/* KPI tiles */}
                {hasWorkspace && stats && (
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        {tiles.map((tile) => (
                            <StatTile key={tile.label} {...tile} />
                        ))}
                    </div>
                )}

                {/* Current plan */}
                <div className="rounded-xl border border-neutral-200 bg-white p-4 shadow-soft dark:border-neutral-700/50 dark:bg-neutral-800/70 sm:p-5">
                    <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-600 dark:text-neutral-300">
                        {t('client.current_plan') || 'Current plan'}
                    </h2>
                    {currentPlan ? (
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p className="text-xl font-bold text-neutral-900 dark:text-white">{currentPlan.name}</p>
                                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                    {currentPlan.status === 'active'
                                        ? t('client.plan_active') || 'Active'
                                        : t('client.plan_inactive') || currentPlan.status}
                                    {renewsAt && ` · ${t('client.renews') || 'Renews'} ${formatDate(renewsAt)}`}
                                </p>
                                {managedByAdmin && (
                                    <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                        {t('client.managed_by_admin') || 'Managed by your organization admin'}
                                    </p>
                                )}
                            </div>
                            {!managedByAdmin && (
                                <Link
                                    href={route('client.pricing')}
                                    className="inline-flex items-center gap-2 rounded-soft bg-brand-500 px-4 py-2 text-sm font-medium text-white shadow-soft transition-all duration-150 hover:bg-brand-600"
                                >
                                    {t('client.upgrade_plan') || 'Upgrade plan'}
                                    <ArrowRightCircle className="h-4 w-4" />
                                </Link>
                            )}
                        </div>
                    ) : (
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <p className="text-neutral-500 dark:text-neutral-400">{t('client.no_plan') || 'No active plan'}</p>
                            <Link
                                href={route('client.pricing')}
                                className="inline-flex items-center gap-2 rounded-soft bg-brand-500 px-4 py-2 text-sm font-medium text-white shadow-soft transition-all duration-150 hover:bg-brand-600"
                            >
                                {t('client.view_plans') || 'View plans'}
                                <ArrowRightCircle className="h-4 w-4" />
                            </Link>
                        </div>
                    )}
                </div>

                {/* Analytics charts */}
                {hasWorkspace && (
                    <>
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <WidgetCard title={t('client.chart_messages') || 'Messages by channel'}>
                                {messageChannelKeys.length > 0 ? (
                                    <LineChart data={charts.messages ?? []} xKey="date" yKeys={messageChannelKeys} height={200} />
                                ) : (
                                    <EmptyState>{t('client.no_data') || 'No data for this period'}</EmptyState>
                                )}
                            </WidgetCard>
                            <WidgetCard title={t('client.chart_conversations') || 'Conversations'}>
                                <LineChart
                                    data={charts.conversations ?? []}
                                    xKey="date"
                                    yKeys={['opened', 'resolved']}
                                    labels={{ opened: t('client.chart_opened'), resolved: t('client.chart_resolved') }}
                                    height={200}
                                />
                            </WidgetCard>
                            <WidgetCard title={t('client.chart_ai_tokens') || 'AI tokens'}>
                                <BarChart
                                    data={charts.ai_tokens ?? []}
                                    xKey="date"
                                    yKeys={['prompt', 'completion']}
                                    labels={{ prompt: t('client.chart_prompt'), completion: t('client.chart_completion') }}
                                    stacked
                                    height={200}
                                />
                            </WidgetCard>
                        </div>

                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <WidgetCard title={t('client.chart_contacts_growth') || 'New contacts'}>
                                <BarChart data={charts.contacts_growth ?? []} xKey="date" yKeys={['contacts']} labels={{ contacts: t('client.kpi_contacts') }} height={200} />
                            </WidgetCard>
                            <WidgetCard title={t('client.chart_channel_mix') || 'Channel mix'}>
                                {(charts.channel_mix ?? []).length > 0 ? (
                                    <DonutChart data={charts.channel_mix} nameKey="name" valueKey="value" height={200} innerRadius={48} outerRadius={80} />
                                ) : (
                                    <EmptyState>{t('client.no_data') || 'No data for this period'}</EmptyState>
                                )}
                            </WidgetCard>
                            {hasAutomationData ? (
                                <WidgetCard title={t('client.chart_automation_runs') || 'Automation runs'}>
                                    <BarChart data={charts.automation_runs ?? []} xKey="date" yKeys={automationKeys} stacked height={200} />
                                </WidgetCard>
                            ) : hasSocialData ? (
                                <WidgetCard title={t('client.chart_social_posts') || 'Social posts'}>
                                    <DonutChart data={charts.social_posts} nameKey="name" valueKey="value" height={200} innerRadius={48} outerRadius={80} />
                                </WidgetCard>
                            ) : (
                                <WidgetCard title={t('client.chart_automation_runs') || 'Automation runs'}>
                                    <EmptyState>{t('client.no_data') || 'No data for this period'}</EmptyState>
                                </WidgetCard>
                            )}
                        </div>

                        {/* Activity tables */}
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <WidgetCard
                                title={t('client.recent_conversations') || 'Recent conversations'}
                                action={
                                    <Link href={route('client.inbox.index')} className="text-sm text-brand-600 hover:underline dark:text-brand-400">
                                        {t('client.view_all') || 'View all'}
                                    </Link>
                                }
                            >
                                <ul className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                                    {(tables.recent_conversations ?? []).map((c) => (
                                        <li key={c.id} className="flex items-center justify-between gap-2 py-2">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-neutral-800 dark:text-neutral-200">{c.contact}</p>
                                                <p className={`text-xs capitalize ${CONV_TONE[c.status] || 'text-neutral-400'}`}>{c.status}</p>
                                            </div>
                                            <div className="flex flex-shrink-0 items-center gap-2">
                                                {c.unread > 0 && (
                                                    <span className="rounded-full bg-brand-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{c.unread}</span>
                                                )}
                                                <span className="text-xs text-neutral-400">{relTime(c.last_message_at)}</span>
                                            </div>
                                        </li>
                                    ))}
                                    {(tables.recent_conversations ?? []).length === 0 && (
                                        <li className="py-6 text-center text-sm text-neutral-400">{t('client.no_conversations') || 'No conversations yet'}</li>
                                    )}
                                </ul>
                            </WidgetCard>

                            <WidgetCard
                                title={t('client.recent_campaigns') || 'Recent campaigns'}
                                action={
                                    <Link href={route('client.campaigns.index')} className="text-sm text-brand-600 hover:underline dark:text-brand-400">
                                        {t('client.view_all') || 'View all'}
                                    </Link>
                                }
                            >
                                <ul className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                                    {(tables.recent_campaigns ?? []).map((c) => (
                                        <li key={c.id} className="flex items-center justify-between gap-2 py-2">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-neutral-800 dark:text-neutral-200">{c.name}</p>
                                                <p className="text-xs capitalize text-neutral-400">{c.channel} · {c.recipients} {t('client.recipients') || 'recipients'}</p>
                                            </div>
                                            <span className={`flex-shrink-0 text-xs font-medium capitalize ${CAMPAIGN_TONE[c.status] || 'text-neutral-400'}`}>{c.status}</span>
                                        </li>
                                    ))}
                                    {(tables.recent_campaigns ?? []).length === 0 && (
                                        <li className="py-6 text-center text-sm text-neutral-400">{t('client.no_campaigns') || 'No campaigns yet'}</li>
                                    )}
                                </ul>
                            </WidgetCard>

                            <WidgetCard title={t('client.agent_leaderboard') || 'Agent leaderboard'}>
                                <ul className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                                    {(tables.agent_leaderboard ?? []).map((a) => (
                                        <li key={a.user_id} className="flex items-center justify-between gap-2 py-2">
                                            <p className="truncate text-sm font-medium text-neutral-800 dark:text-neutral-200">{a.name}</p>
                                            <div className="flex flex-shrink-0 items-center gap-3 text-xs">
                                                <span className="text-neutral-600 dark:text-neutral-400">
                                                    {a.handled} {t('client.handled') || 'handled'}
                                                </span>
                                                {a.avg_first_response_min != null && (
                                                    <span className="text-neutral-400">~{a.avg_first_response_min}m</span>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                    {(tables.agent_leaderboard ?? []).length === 0 && (
                                        <li className="py-6 text-center text-sm text-neutral-400">{t('client.no_agents') || 'No assigned conversations yet'}</li>
                                    )}
                                </ul>
                            </WidgetCard>
                        </div>
                    </>
                )}

                {/* Usage / account stats */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <WidgetCard title={t('client.team_members') || 'Team members'}>
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-2xl font-bold tabular-nums text-neutral-900 dark:text-white">{membersLabel}</p>
                            <Users className="h-5 w-5 text-brand-500" />
                        </div>
                        {membersPct != null && (
                            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-700">
                                <div className="h-full rounded-full bg-brand-500" style={{ width: `${membersPct}%` }} />
                            </div>
                        )}
                        <Link href={route('client.team.index')} className="mt-3 inline-flex items-center gap-1 text-sm text-brand-600 hover:underline dark:text-brand-400">
                            {t('client.manage_team') || 'Manage team'} <ArrowRightCircle className="h-4 w-4" />
                        </Link>
                    </WidgetCard>

                    <WidgetCard title={t('client.workspaces') || 'Workspaces'}>
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-2xl font-bold tabular-nums text-neutral-900 dark:text-white">{workspacesCount}</p>
                            <Layers className="h-5 w-5 text-brand-500" />
                        </div>
                        <Link href={route('client.workspaces.index')} className="mt-3 inline-flex items-center gap-1 text-sm text-brand-600 hover:underline dark:text-brand-400">
                            {t('client.manage_workspaces') || 'Manage'} <ArrowRightCircle className="h-4 w-4" />
                        </Link>
                    </WidgetCard>

                    <WidgetCard title={t('client.plan') || 'Plan'}>
                        <div className="flex items-center justify-between gap-2">
                            <p className="truncate text-2xl font-bold text-neutral-900 dark:text-white">{currentPlan?.name ?? 'Free'}</p>
                            <Package className="h-5 w-5 text-brand-500" />
                        </div>
                        <Link href={route('client.pricing')} className="mt-3 inline-flex items-center gap-1 text-sm text-brand-600 hover:underline dark:text-brand-400">
                            {t('client.view_plans') || 'View plans'} <ArrowRightCircle className="h-4 w-4" />
                        </Link>
                    </WidgetCard>
                </div>

                {/* Shortcuts */}
                <div className="rounded-xl border border-neutral-200 bg-white p-4 shadow-soft dark:border-neutral-700/50 dark:bg-neutral-800/70 sm:p-5">
                    <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-600 dark:text-neutral-300">
                        {t('client.quick_links') || 'Quick links'}
                    </h2>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <Link href={route('client.pricing')} className="flex items-center gap-3 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-neutral-700 transition hover:bg-neutral-100 hover:text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800/50 dark:text-neutral-200 dark:hover:bg-neutral-700/50 dark:hover:text-white">
                            <CreditCard className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            <span>{t('client.billing_plans') || 'Billing & plans'}</span>
                        </Link>
                        {isClientAdministrator && (
                            <Link href={route('client.team.index')} className="flex items-center gap-3 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-neutral-700 transition hover:bg-neutral-100 hover:text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800/50 dark:text-neutral-200 dark:hover:bg-neutral-700/50 dark:hover:text-white">
                                <Users className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                                <span>{t('nav.team')}</span>
                            </Link>
                        )}
                        {isClientAdministrator && (
                            <Link href={route('client.audit-log.index')} className="flex items-center gap-3 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-neutral-700 transition hover:bg-neutral-100 hover:text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800/50 dark:text-neutral-200 dark:hover:bg-neutral-700/50 dark:hover:text-white">
                                <FileText className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                                <span>{t('client.audit_log') || 'Audit log'}</span>
                            </Link>
                        )}
                        <Link href={route('client.settings.index')} className="flex items-center gap-3 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-neutral-700 transition hover:bg-neutral-100 hover:text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800/50 dark:text-neutral-200 dark:hover:bg-neutral-700/50 dark:hover:text-white">
                            <Settings className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            <span>{t('nav.settings')}</span>
                        </Link>
                        <Link href={route('client.profile.edit')} className="flex items-center gap-3 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-neutral-700 transition hover:bg-neutral-100 hover:text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800/50 dark:text-neutral-200 dark:hover:bg-neutral-700/50 dark:hover:text-white">
                            <User className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            <span>{t('nav.profile') || 'Profile'}</span>
                        </Link>
                    </div>
                </div>
            </div>
        </ClientLayout>
    );
}
