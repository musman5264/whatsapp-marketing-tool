import { Head, Link, router, usePage } from '@inertiajs/react';
import InboxLayout from '@/Layouts/InboxLayout';
import EmptyState from '@/Components/EmptyState';
import NewConversationModal from '@/Components/Inbox/NewConversationModal';
import { Skeleton } from '@/Components/ui';
import {
    MessageSquare, Inbox, CheckCircle, Clock, User, RefreshCw,
    Search, Plus,
} from 'lucide-react';
import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { ChannelBrandIcon, CHANNEL_LABELS } from '@/Components/BrandIcons';
import { formatTimeTz } from '@/Utils/datetime';

const FOLDERS = [
    { key: null,         labelKey: 'inbox.folder_all',        icon: Inbox },
    { key: 'mine',       labelKey: 'inbox.folder_mine',       icon: User },
    { key: 'unassigned', labelKey: 'inbox.folder_unassigned', icon: MessageSquare },
    { key: 'resolved',   labelKey: 'inbox.folder_resolved',   icon: CheckCircle },
    { key: 'snoozed',    labelKey: 'inbox.folder_snoozed',    icon: Clock },
];

const ALL_CHANNELS = ['whatsapp', 'instagram', 'messenger', 'sms', 'email'];

function StatusDot({ status }) {
    const colors = {
        open: 'bg-green-500',
        pending: 'bg-amber-400',
        resolved: 'bg-neutral-400',
        snoozed: 'bg-purple-400',
    };
    return <span className={`inline-block h-2 w-2 rounded-full shrink-0 ${colors[status] ?? 'bg-neutral-300'}`} />;
}

function ConversationCard({ conv, isFlashing, isActive, userTz }) {
    const { t } = useTranslation();
    const channel = conv.channel_account?.channel ?? 'whatsapp';
    const lastMsg = conv.last_message ?? {};
    const name = conv.contact?.first_name || conv.contact?.last_name
        ? `${conv.contact.first_name ?? ''} ${conv.contact.last_name ?? ''}`.trim()
        : conv.contact?.phone_e164 ?? 'Unknown';

    const handleContactClick = (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (conv.contact?.id) {
            router.visit(route('client.contacts.show', conv.contact.uuid));
        }
    };

    return (
        <Link
            href={route('client.inbox.show', conv.uuid)}
            className={`block px-3 py-3 border-b border-neutral-100 dark:border-neutral-800 transition-colors ${
                isActive
                    ? 'bg-brand-50 dark:bg-brand-900/20 border-l-2 border-l-brand-600'
                    : isFlashing
                        ? 'bg-brand-50/60 dark:bg-brand-900/10'
                        : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/50'
            }`}
        >
            <div className="flex items-start gap-2.5">
                {/* Avatar — click navigates to contact profile */}
                <button
                    onClick={handleContactClick}
                    title={t('inbox.view_contact')}
                    className="relative shrink-0 group"
                >
                    <div className="h-9 w-9 rounded-full bg-brand-100 dark:bg-brand-900/30 flex items-center justify-center text-sm font-semibold text-brand-700 dark:text-brand-300 group-hover:ring-2 group-hover:ring-brand-400 transition">
                        {name[0]?.toUpperCase() ?? '?'}
                    </div>
                    <span className="absolute -bottom-0.5 -right-0.5 h-4 w-4 rounded-full bg-white dark:bg-neutral-900 flex items-center justify-center">
                        <ChannelBrandIcon channel={channel} className="h-3 w-3" />
                    </span>
                </button>

                {/* Content */}
                <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-1">
                        <button
                            onClick={handleContactClick}
                            className={`text-sm truncate text-left hover:underline ${conv.unread_count > 0 ? 'font-semibold text-neutral-900 dark:text-neutral-100' : 'font-medium text-neutral-700 dark:text-neutral-300'}`}
                            title={t('inbox.view_contact_profile')}
                        >
                            {name}
                        </button>
                        <span className="text-[11px] text-neutral-400 shrink-0">
                            {conv.last_message_at ? formatTimeTz(conv.last_message_at, userTz) : ''}
                        </span>
                    </div>
                    <div className="flex items-center gap-1.5 mt-0.5">
                        <p className={`text-xs truncate flex-1 ${conv.unread_count > 0 ? 'text-neutral-700 dark:text-neutral-300' : 'text-neutral-400 dark:text-neutral-500'}`}>
                            {lastMsg.body || '(media)'}
                        </p>
                        {conv.unread_count > 0 && (
                            <span className="shrink-0 h-5 min-w-5 rounded-full bg-brand-600 text-white text-[10px] font-bold flex items-center justify-center px-1">
                                {conv.unread_count > 99 ? '99+' : conv.unread_count}
                            </span>
                        )}
                    </div>
                    {conv.labels?.length > 0 && (
                        <div className="flex flex-wrap gap-1 mt-1.5">
                            {conv.labels.map(label => (
                                <span key={label.id} className="inline-flex items-center rounded-full px-1.5 py-px text-[10px] font-medium text-white" style={{ backgroundColor: label.color }}>
                                    {label.name}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </Link>
    );
}

function ConversationSkeleton() {
    return (
        <div className="flex items-start gap-2.5 px-3 py-3 border-b border-neutral-100 dark:border-neutral-800">
            <Skeleton variant="circle" className="h-9 w-9 shrink-0" />
            <div className="flex-1 min-w-0 space-y-1.5">
                <Skeleton className="h-3.5 w-28" />
                <Skeleton className="h-3 w-44" />
            </div>
        </div>
    );
}

function FilterSidebar({ filters, labels, channelAccounts = [], onFolder, onChannel, onAccount, onLabel }) {
    const { t } = useTranslation();
    return (
        <div className="flex flex-col h-full overflow-y-auto">
            {/* Folders */}
            <div className="p-2 border-b border-neutral-100 dark:border-neutral-800">
                <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400 dark:text-neutral-500 px-2 py-1.5">{t('inbox.views')}</p>
                {FOLDERS.map(({ key, labelKey, icon: Icon }) => (
                    <button
                        key={key ?? 'all'}
                        onClick={() => onFolder(key)}
                        className={`w-full flex items-center gap-2.5 px-2 py-2 rounded-lg text-sm transition ${
                            (filters.folder ?? null) === key
                                ? 'bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300 font-semibold'
                                : 'text-neutral-600 dark:text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800'
                        }`}
                    >
                        <Icon className="h-4 w-4 shrink-0" />
                        <span>{t(labelKey)}</span>
                    </button>
                ))}
            </div>

            {/* Channels */}
            <div className="p-2 border-b border-neutral-100 dark:border-neutral-800">
                <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400 dark:text-neutral-500 px-2 py-1.5">{t('inbox.channels')}</p>
                {ALL_CHANNELS.map(ch => (
                    <button
                        key={ch}
                        onClick={() => onChannel(ch)}
                        className={`w-full flex items-center gap-2.5 px-2 py-2 rounded-lg text-sm transition ${
                            filters.channel === ch
                                ? 'bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300 font-semibold'
                                : 'text-neutral-600 dark:text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800'
                        }`}
                    >
                        <ChannelBrandIcon channel={ch} className="h-4 w-4 shrink-0" />
                        <span>{CHANNEL_LABELS[ch] ?? ch}</span>
                    </button>
                ))}
            </div>

            {/* Numbers (channel accounts) */}
            {channelAccounts.length > 0 && (
                <div className="p-2 border-b border-neutral-100 dark:border-neutral-800">
                    <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400 dark:text-neutral-500 px-2 py-1.5">{t('inbox.numbers')}</p>
                    {channelAccounts.map(account => (
                        <button
                            key={account.id}
                            onClick={() => onAccount(account.id)}
                            className={`w-full flex items-center gap-2.5 px-2 py-2 rounded-lg text-sm transition ${
                                String(filters.account_id) === String(account.id)
                                    ? 'bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300 font-semibold'
                                    : 'text-neutral-600 dark:text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800'
                            }`}
                        >
                            <ChannelBrandIcon channel={account.channel} className="h-4 w-4 shrink-0" />
                            <span className="truncate">{account.display_name || account.phone_number_id || account.channel}</span>
                        </button>
                    ))}
                </div>
            )}

            {/* Labels / Tags */}
            {labels.length > 0 && (
                <div className="p-2">
                    <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400 dark:text-neutral-500 px-2 py-1.5">{t('inbox.labels')}</p>
                    {labels.map(label => (
                        <button
                            key={label.id}
                            onClick={() => onLabel(label.id)}
                            className={`w-full flex items-center gap-2.5 px-2 py-2 rounded-lg text-sm transition ${
                                String(filters.label) === String(label.id)
                                    ? 'bg-brand-50 dark:bg-brand-900/30 font-semibold'
                                    : 'text-neutral-600 dark:text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800'
                            }`}
                        >
                            <span className="h-3 w-3 rounded-full shrink-0 ring-1 ring-white dark:ring-neutral-900" style={{ backgroundColor: label.color }} />
                            <span className="truncate">{label.name}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function InboxIndex({ conversations: initialConversations, filters, labels = [], channelAccounts = [] }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const authUser = props.auth?.user;
    const workspaceId = props.currentWorkspace?.id ?? authUser?.workspace_id;
    const userTz = props.timezone || 'Asia/Dhaka';

    const [conversations, setConversations] = useState(initialConversations);
    const [flashingIds, setFlashingIds]     = useState(new Set());
    const [loading, setLoading]             = useState(false);
    const [search, setSearch]               = useState('');
    const [showNewModal, setShowNewModal]   = useState(false);

    useEffect(() => {
        setConversations(initialConversations);
        setLoading(false);
    }, [initialConversations]);

    useEffect(() => {
        if (!window.Echo || !workspaceId) return;
        window.Echo.private(`workspace.${workspaceId}`)
            .listen('.MessageReceived', (e) => {
                setConversations(prev => {
                    const convId = e.conversation_id;
                    const exists = prev.data.find(c => c.id === convId);
                    if (!exists) {
                        router.reload({ preserveScroll: true, preserveState: true });
                        return prev;
                    }
                    setFlashingIds(ids => new Set([...ids, convId]));
                    setTimeout(() => setFlashingIds(ids => {
                        const next = new Set(ids);
                        next.delete(convId);
                        return next;
                    }), 2000);
                    return {
                        ...prev,
                        data: [
                            { ...exists, unread_count: (exists.unread_count ?? 0) + 1, last_message_at: e.created_at, last_message: { body: e.body } },
                            ...prev.data.filter(c => c.id !== convId),
                        ],
                    };
                });
            });
        return () => { window.Echo.leave(`workspace.${workspaceId}`); };
    }, [workspaceId]);

    const navigate = (params) => {
        setLoading(true);
        router.get(route('client.inbox.index'), { ...filters, ...params }, { preserveState: true, replace: true });
    };

    const handleFolder  = (key) => navigate({ folder: key, channel: undefined, label: undefined, account_id: undefined });
    const handleChannel = (ch)  => navigate({ channel: filters.channel === ch ? undefined : ch, account_id: undefined });
    const handleAccount = (id)  => navigate({ account_id: String(filters.account_id) === String(id) ? undefined : id, channel: undefined });
    const handleLabel   = (id)  => navigate({ label: String(filters.label) === String(id) ? undefined : id });

    const filtered = search.trim()
        ? conversations.data.filter(c => {
            const name = `${c.contact?.first_name ?? ''} ${c.contact?.last_name ?? ''} ${c.contact?.phone_e164 ?? ''}`.toLowerCase();
            return name.includes(search.toLowerCase());
        })
        : conversations.data;

    const activeFolder = FOLDERS.find(f => (f.key ?? null) === (filters.folder ?? null));

    return (
        <InboxLayout>
            <Head title={t('inbox.title')} />
            {showNewModal && <NewConversationModal onClose={() => setShowNewModal(false)} />}
            <div className="flex flex-1 overflow-hidden">

                {/* Filter sidebar */}
                <aside className="w-48 shrink-0 border-r border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 flex flex-col overflow-hidden">
                    <div className="px-3 py-3 border-b border-neutral-100 dark:border-neutral-800 flex items-center justify-between gap-1">
                        <p className="text-sm font-bold text-neutral-800 dark:text-neutral-200 flex items-center gap-2">
                            <Inbox className="h-4 w-4 text-brand-600" />
                            {t('inbox.title')}
                        </p>
                        <button
                            onClick={() => setShowNewModal(true)}
                            title={t('inbox.new_conversation')}
                            className="h-7 w-7 rounded-lg bg-brand-600 hover:bg-brand-700 text-white flex items-center justify-center transition shrink-0"
                        >
                            <Plus className="h-4 w-4" />
                        </button>
                    </div>
                    <FilterSidebar
                        filters={filters}
                        labels={labels}
                        channelAccounts={channelAccounts}
                        onFolder={handleFolder}
                        onChannel={handleChannel}
                        onAccount={handleAccount}
                        onLabel={handleLabel}
                    />
                </aside>

                {/* Conversation list */}
                <div className="w-72 shrink-0 border-r border-neutral-200 dark:border-neutral-700 flex flex-col bg-white dark:bg-neutral-900">
                    {/* List header */}
                    <div className="px-3 py-2.5 border-b border-neutral-100 dark:border-neutral-800 space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-semibold text-neutral-800 dark:text-neutral-200 flex items-center gap-1 flex-wrap">
                                {activeFolder ? t(activeFolder.labelKey) : t('inbox.folder_all')}
                                {filters.channel && <span className="text-xs font-normal text-neutral-400">· {CHANNEL_LABELS[filters.channel] ?? filters.channel}</span>}
                                {filters.account_id && (() => {
                                    const acct = channelAccounts.find(a => String(a.id) === String(filters.account_id));
                                    return acct ? <span className="text-xs font-normal text-neutral-400">· {acct.display_name || acct.phone_number_id}</span> : null;
                                })()}
                            </span>
                            <div className="flex items-center gap-1">
                                <span className="text-xs text-neutral-400 tabular-nums">{conversations.total}</span>
                                <button
                                    onClick={() => { setLoading(true); router.reload(); }}
                                    className="p-1 rounded hover:bg-neutral-100 dark:hover:bg-neutral-800 text-neutral-400 hover:text-neutral-600 transition"
                                    title={t('inbox.refresh')}
                                >
                                    <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
                                </button>
                            </div>
                        </div>
                        {/* Search */}
                        <div className="relative">
                            <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-neutral-400 pointer-events-none" />
                            <input
                                type="text"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder={t('inbox.search_conversations')}
                                className="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg bg-neutral-100 dark:bg-neutral-800 border-0 focus:outline-none focus:ring-2 focus:ring-brand-500 placeholder-neutral-400"
                            />
                        </div>
                    </div>

                    {/* List body */}
                    <div className="flex-1 overflow-y-auto">
                        {loading ? (
                            Array.from({ length: 6 }).map((_, i) => <ConversationSkeleton key={i} />)
                        ) : filtered.length === 0 ? (
                            <div className="py-10 px-4">
                                <EmptyState
                                    icon={<Inbox className="h-7 w-7" />}
                                    title={t('inbox.no_conversations')}
                                    description={t('inbox.no_conversations_desc')}
                                />
                            </div>
                        ) : (
                            filtered.map(conv => (
                                <ConversationCard
                                    key={conv.id}
                                    conv={conv}
                                    isFlashing={flashingIds.has(conv.id)}
                                    isActive={false}
                                    userTz={userTz}
                                />
                            ))
                        )}
                    </div>
                </div>

                {/* Empty state – main pane */}
                <div className="flex-1 flex items-center justify-center bg-neutral-50 dark:bg-neutral-950">
                    <div className="text-center">
                        <div className="mx-auto mb-4 h-16 w-16 rounded-2xl bg-neutral-100 dark:bg-neutral-800 flex items-center justify-center">
                            <MessageSquare className="h-8 w-8 text-neutral-300 dark:text-neutral-600" />
                        </div>
                        <p className="text-base font-semibold text-neutral-500 dark:text-neutral-400">{t('inbox.select_conversation')}</p>
                        <p className="text-sm text-neutral-400 dark:text-neutral-500 mt-1">{t('inbox.select_conversation_desc')}</p>
                    </div>
                </div>
            </div>
        </InboxLayout>
    );
}
