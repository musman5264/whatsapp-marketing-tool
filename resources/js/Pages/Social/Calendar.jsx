import { Head, Link, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ChevronLeft, ChevronRight, Plus, Filter, X, ChevronDown } from 'lucide-react';
import { formatInTz, browserTz } from '@/Utils/datetime';
import { useState, useRef, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { SocialBrandIcon } from '@/Components/BrandIcons';

const STATUS_COLORS = {
    scheduled:  'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    published:  'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    publishing: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    failed:     'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    draft:      'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400',
};

const STATUS_OPTIONS = [
    { value: '',           labelKey: 'social.status_all' },
    { value: 'scheduled',  labelKey: 'social.status_scheduled' },
    { value: 'published',  labelKey: 'social.status_published' },
    { value: 'publishing', labelKey: 'social.status_publishing' },
    { value: 'draft',      labelKey: 'social.status_draft' },
    { value: 'failed',     labelKey: 'social.status_failed' },
];

const NETWORK_ICONS = {
    facebook:  '📘',
    instagram: '📷',
    twitter:   '🐦',
    linkedin:  '💼',
    tiktok:    '🎵',
    youtube:   '▶️',
};

const DAY_KEYS = ['social.day_sun', 'social.day_mon', 'social.day_tue', 'social.day_wed', 'social.day_thu', 'social.day_fri', 'social.day_sat'];

const MONTH_KEYS = ['social.month_january', 'social.month_february', 'social.month_march', 'social.month_april', 'social.month_may', 'social.month_june', 'social.month_july', 'social.month_august', 'social.month_september', 'social.month_october', 'social.month_november', 'social.month_december'];

function ProfilePicker({ accounts, value, onChange }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        const handler = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const selected = accounts.find(a => String(a.id) === value) ?? null;

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen(v => !v)}
                className="flex items-center gap-2 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-700 dark:text-neutral-300 text-xs px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-brand-500 min-w-[130px]"
            >
                {selected ? (
                    <>
                        <div className="relative shrink-0">
                            {selected.picture_url
                                ? <img src={selected.picture_url} alt={selected.name} className="h-4 w-4 rounded-full object-cover" />
                                : <span className="flex h-4 w-4 items-center justify-center rounded-full bg-neutral-200 dark:bg-neutral-700 text-[8px] font-bold text-neutral-500">
                                    {selected.name?.[0]?.toUpperCase() ?? '?'}
                                  </span>
                            }
                            <span className="absolute -bottom-0.5 -right-0.5 flex h-2.5 w-2.5 items-center justify-center rounded-full bg-white dark:bg-neutral-900 ring-1 ring-white dark:ring-neutral-900">
                                <SocialBrandIcon network={selected.network} className="h-2 w-2" />
                            </span>
                        </div>
                        <span className="truncate max-w-[90px]">{selected.name}</span>
                    </>
                ) : (
                    <span>{t('social.all_profiles')}</span>
                )}
                <ChevronDown className={`h-3 w-3 ml-auto shrink-0 text-neutral-400 transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="absolute z-50 mt-1 left-0 min-w-[190px] rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 shadow-xl overflow-hidden">
                    <ul className="max-h-64 overflow-y-auto py-1">
                        <li>
                            <button type="button" onClick={() => { onChange(''); setOpen(false); }}
                                className={`w-full flex items-center gap-2.5 px-3 py-2 text-xs text-left hover:bg-neutral-50 dark:hover:bg-neutral-800 transition ${!value ? 'text-brand-600 font-semibold' : 'text-neutral-700 dark:text-neutral-300'}`}>
                                <span className="flex h-5 w-5 items-center justify-center rounded-full bg-neutral-100 dark:bg-neutral-700 shrink-0">
                                    <Filter className="h-3 w-3 text-neutral-400" />
                                </span>
                                {t('social.all_profiles')}
                            </button>
                        </li>
                        {accounts.map(acc => (
                            <li key={acc.id}>
                                <button type="button" onClick={() => { onChange(String(acc.id)); setOpen(false); }}
                                    className={`w-full flex items-center gap-2.5 px-3 py-2 text-xs text-left hover:bg-neutral-50 dark:hover:bg-neutral-800 transition ${String(acc.id) === value ? 'bg-brand-50 dark:bg-brand-900/20 text-brand-600 dark:text-brand-400 font-medium' : 'text-neutral-700 dark:text-neutral-300'}`}>
                                    <div className="relative shrink-0">
                                        {acc.picture_url
                                            ? <img src={acc.picture_url} alt={acc.name} className="h-5 w-5 rounded-full object-cover" />
                                            : <span className="flex h-5 w-5 items-center justify-center rounded-full bg-neutral-200 dark:bg-neutral-700 text-[9px] font-bold text-neutral-500">
                                                {acc.name?.[0]?.toUpperCase() ?? '?'}
                                              </span>
                                        }
                                        <span className="absolute -bottom-0.5 -right-0.5 flex h-3 w-3 items-center justify-center rounded-full bg-white dark:bg-neutral-900 ring-1 ring-white dark:ring-neutral-900">
                                            <SocialBrandIcon network={acc.network} className="h-2.5 w-2.5" />
                                        </span>
                                    </div>
                                    <span className="truncate">{acc.name}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

function buildCalendarGrid(year, month) {
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const grid = [];
    let dayCount = 1;

    for (let week = 0; week < 6; week++) {
        const row = [];
        for (let day = 0; day < 7; day++) {
            if (week === 0 && day < firstDay) {
                row.push(null);
            } else if (dayCount > daysInMonth) {
                row.push(null);
            } else {
                row.push(dayCount++);
            }
        }
        grid.push(row);
        if (dayCount > daysInMonth) break;
    }
    return grid;
}

function buildUrl(month, filters) {
    const params = new URLSearchParams({ month });
    if (filters.status)     params.set('status',     filters.status);
    if (filters.account_id) params.set('account_id', filters.account_id);
    if (filters.network)    params.set('network',    filters.network);
    return `?${params.toString()}`;
}

export default function SocialCalendar({ posts, month, accounts = [], filters = {} }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const userTz = props.timezone || browserTz() || 'Asia/Dhaka';
    const [year, monthIdx] = month.split('-').map(Number);
    const grid = buildCalendarGrid(year, monthIdx - 1);

    const [localFilters, setLocalFilters] = useState({
        status:     filters.status     ?? '',
        account_id: filters.account_id ?? '',
        network:    filters.network    ?? '',
    });

    const postsByDay = posts.reduce((acc, post) => {
        if (post.scheduled_at) {
            const dayStr = new Intl.DateTimeFormat('en-US', { timeZone: userTz, day: 'numeric' }).format(new Date(post.scheduled_at));
            const day = parseInt(dayStr, 10);
            if (!acc[day]) acc[day] = [];
            acc[day].push(post);
        }
        return acc;
    }, {});

    const prevMonth = new Date(year, monthIdx - 2, 1);
    const nextMonth = new Date(year, monthIdx, 1);
    const fmtMonth  = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;

    const applyFilter = (newFilters) => {
        const merged = { ...localFilters, ...newFilters };
        setLocalFilters(merged);
        router.get(route('client.social.calendar'), { month, ...Object.fromEntries(Object.entries(merged).filter(([, v]) => v)) }, { preserveScroll: true, replace: true });
    };

    const clearFilters = () => {
        setLocalFilters({ status: '', account_id: '', network: '' });
        router.get(route('client.social.calendar'), { month }, { preserveScroll: true, replace: true });
    };

    const hasActiveFilters = localFilters.status || localFilters.account_id || localFilters.network;

    // Unique networks from accounts
    const networks = [...new Set(accounts.map(a => a.network))];

    return (
        <ClientLayout title={t('social.calendar_title')}>
            <Head title={t('social.calendar_head')} />
            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('social.calendar_title')}</h2>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">{t('social.calendar_subtitle')}</p>
                    </div>
                    <Link href={route('client.social.composer')} className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 transition">
                        <Plus className="h-4 w-4" /> {t('social.new_post')}
                    </Link>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-2">
                    <span className="flex items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400 font-medium">
                        <Filter className="h-3.5 w-3.5" /> {t('social.filter_label')}
                    </span>

                    {/* Status filter */}
                    <select
                        value={localFilters.status}
                        onChange={e => applyFilter({ status: e.target.value })}
                        className="rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-700 dark:text-neutral-300 text-xs px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                        {STATUS_OPTIONS.map(opt => (
                            <option key={opt.value} value={opt.value}>{t(opt.labelKey)}</option>
                        ))}
                    </select>

                    {/* Network filter */}
                    <select
                        value={localFilters.network}
                        onChange={e => applyFilter({ network: e.target.value, account_id: '' })}
                        className="rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-700 dark:text-neutral-300 text-xs px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                        <option value="">{t('social.all_networks')}</option>
                        {networks.map(n => (
                            <option key={n} value={n}>{NETWORK_ICONS[n] ?? ''} {n.charAt(0).toUpperCase() + n.slice(1)}</option>
                        ))}
                    </select>

                    {/* Profile/Account filter */}
                    <ProfilePicker
                        accounts={accounts}
                        value={localFilters.account_id}
                        onChange={id => applyFilter({ account_id: id, network: '' })}
                    />

                    {/* Clear */}
                    {hasActiveFilters && (
                        <button
                            onClick={clearFilters}
                            className="flex items-center gap-1 rounded-lg border border-neutral-200 dark:border-neutral-700 px-2.5 py-1.5 text-xs text-neutral-500 hover:text-neutral-700 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                        >
                            <X className="h-3 w-3" /> {t('social.clear')}
                        </button>
                    )}
                </div>

                {/* Month navigation */}
                <div className="flex items-center gap-3">
                    <Link href={buildUrl(fmtMonth(prevMonth), localFilters)} className="rounded p-1 text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition">
                        <ChevronLeft className="h-5 w-5" />
                    </Link>
                    <span className="font-semibold text-neutral-900 dark:text-neutral-100">{t(MONTH_KEYS[monthIdx - 1])} {year}</span>
                    <Link href={buildUrl(fmtMonth(nextMonth), localFilters)} className="rounded p-1 text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition">
                        <ChevronRight className="h-5 w-5" />
                    </Link>
                    {hasActiveFilters && (
                        <span className="text-xs text-brand-600 dark:text-brand-400 font-medium bg-brand-50 dark:bg-brand-900/20 px-2 py-0.5 rounded-full">
                            {t('social.filtered')}
                        </span>
                    )}
                </div>

                {/* Calendar grid */}
                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 overflow-hidden">
                    <div className="grid grid-cols-7 border-b border-neutral-200 dark:border-neutral-700">
                        {DAY_KEYS.map(dKey => (
                            <div key={dKey} className="px-2 py-2 text-center text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase">{t(dKey)}</div>
                        ))}
                    </div>
                    {grid.map((week, wi) => (
                        <div key={wi} className="grid grid-cols-7 border-b last:border-0 border-neutral-100 dark:border-neutral-800">
                            {week.map((day, di) => (
                                <div key={di} className={`min-h-[90px] p-1.5 border-r last:border-0 border-neutral-100 dark:border-neutral-800 ${day ? 'hover:bg-neutral-50 dark:hover:bg-neutral-800/30' : 'bg-neutral-50 dark:bg-neutral-800/20'}`}>
                                    {day && (
                                        <>
                                            <p className="text-xs text-neutral-500 dark:text-neutral-400 mb-1">{day}</p>
                                            {(postsByDay[day] ?? []).map(post => (
                                                <Link
                                                    key={post.id}
                                                    href={route('client.social.posts.index')}
                                                    title={post.title ?? post.status}
                                                    className={`block rounded px-1.5 py-0.5 text-xs mb-0.5 truncate hover:opacity-80 transition ${STATUS_COLORS[post.status] ?? ''}`}
                                                >
                                                    {post.title ?? t('social.post_fallback')}
                                                </Link>
                                            ))}
                                        </>
                                    )}
                                </div>
                            ))}
                        </div>
                    ))}
                </div>
            </div>
        </ClientLayout>
    );
}
