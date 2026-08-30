import { Head, Link, useForm, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import MediaUpload from '@/Components/MediaUpload';
import TimezonePicker from '@/Components/TimezonePicker';
import { DatePicker } from '@/Components/ui';
import { SocialBrandIcon } from '@/Components/BrandIcons';
import { ArrowLeft, Clock, Trash2, Plus, Send, Calendar } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { browserTz, tzLocalToUtcIso, formatInTz } from '@/Utils/datetime';

const CHAR_LIMITS = { twitter: 280, tiktok: 2200, linkedin: 3000, facebook: 63206, instagram: 2200, youtube: 5000 };

const NETWORK_LABELS = {
    facebook: 'Facebook', instagram: 'Instagram', linkedin: 'LinkedIn',
    twitter: 'X (Twitter)', youtube: 'YouTube', tiktok: 'TikTok',
};

/** Convert a UTC datetime string to a `datetime-local` value in the given timezone. */
function toLocalDatetime(utcStr, tz) {
    if (!utcStr) return '';
    try {
        const d = new Date(utcStr);
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: tz,
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', hour12: false,
        }).formatToParts(d);
        const get = (type) => parts.find(p => p.type === type)?.value ?? '00';
        return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
    } catch {
        return '';
    }
}

export default function EditPost({ post, accounts }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const userTz = props.timezone || browserTz() || 'Asia/Dhaka';
    const postTz = post.timezone || userTz;

    const { data, setData, put, processing, errors } = useForm({
        title:           post.title ?? '',
        body:            post.body ?? '',
        media_urls:      (post.media_urls ?? []).filter(Boolean).length
                            ? post.media_urls.filter(Boolean)
                            : [''],
        target_accounts: (post.target_accounts ?? []).map(String),
        scheduled_at:    toLocalDatetime(post.scheduled_at, postTz),
        timezone:        postTz,
    });

    const toggleAccount = (id) => {
        const sid = id.toString();
        setData('target_accounts', data.target_accounts.includes(sid)
            ? data.target_accounts.filter(a => a !== sid)
            : [...data.target_accounts, sid]);
    };

    const selectedNetworks = accounts
        .filter(a => data.target_accounts.includes(a.id.toString()))
        .map(a => a.network);
    const minCharLimit = selectedNetworks.length > 0
        ? Math.min(...selectedNetworks.map(n => CHAR_LIMITS[n] ?? 5000))
        : 5000;

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('client.social.posts.update', post.id), {
            data: {
                ...data,
                scheduled_at: data.scheduled_at
                    ? tzLocalToUtcIso(data.scheduled_at, data.timezone || 'UTC')
                    : null,
                media_urls: (data.media_urls ?? []).filter(Boolean),
                target_accounts: data.target_accounts.map(Number),
            },
            preserveScroll: true,
        });
    };

    const statusColor = {
        draft: 'bg-neutral-100 text-neutral-600',
        scheduled: 'bg-blue-100 text-blue-700',
        failed: 'bg-red-100 text-red-700',
    }[post.status] ?? 'bg-neutral-100 text-neutral-500';

    return (
        <ClientLayout title={t('social.edit_post_title')}>
            <Head title={t('social.edit_post_head')} />
            <div className="max-w-2xl space-y-6">

                {/* Header */}
                <div className="flex items-center gap-3">
                    <Link href={route('client.social.posts.index')}
                        className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <div className="flex-1 min-w-0">
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('social.edit_post_title')}</h2>
                        <p className="text-sm text-neutral-400 mt-0.5">
                            {t('social.edit_post_subtitle')}
                        </p>
                    </div>
                    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${statusColor}`}>
                        {t(`social.status_${post.status}`, post.status)}
                    </span>
                </div>

                <form onSubmit={handleSubmit} className="space-y-5">

                    {/* Account selector */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-3">
                        <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('social.post_to')}</h3>
                        <div className="flex flex-wrap gap-2">
                            {accounts.map(acct => {
                                const selected = data.target_accounts.includes(acct.id.toString());
                                return (
                                    <button key={acct.id} type="button" onClick={() => toggleAccount(acct.id)}
                                        className={`flex items-center gap-2 rounded-full px-3 py-1.5 text-sm border transition ${
                                            selected
                                                ? 'bg-brand-600 border-brand-600 text-white'
                                                : 'border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:border-brand-400'
                                        }`}>
                                        <div className="relative shrink-0">
                                            {acct.picture_url
                                                ? <img src={acct.picture_url} alt={acct.name} className="h-5 w-5 rounded-full object-cover" />
                                                : <SocialBrandIcon network={acct.network} className="h-4 w-4" />
                                            }
                                            {acct.picture_url && (
                                                <span className="absolute -bottom-0.5 -right-0.5 flex h-3 w-3 items-center justify-center rounded-full bg-white dark:bg-neutral-900 ring-1 ring-white dark:ring-neutral-900">
                                                    <SocialBrandIcon network={acct.network} className="h-2.5 w-2.5" />
                                                </span>
                                            )}
                                        </div>
                                        <span className="truncate max-w-[120px]">{acct.name}</span>
                                    </button>
                                );
                            })}
                            {accounts.length === 0 && (
                                <p className="text-sm text-neutral-400">
                                    {t('social.no_accounts_connected')}{' '}
                                    <Link href={route('client.social.accounts.index')} className="text-brand-600 hover:underline">{t('social.add_one')}</Link>
                                </p>
                            )}
                        </div>
                        {errors.target_accounts && <p className="text-xs text-red-500">{errors.target_accounts}</p>}
                    </div>

                    {/* Content */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-4">
                        <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('social.content')}</h3>

                        {/* Title */}
                        <div>
                            <label className="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">
                                {t('social.title_label')} <span className="text-neutral-400">({t('common.optional')})</span>
                            </label>
                            <input
                                type="text"
                                value={data.title}
                                onChange={e => setData('title', e.target.value)}
                                placeholder={t('social.edit_title_placeholder')}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                            />
                        </div>

                        {/* Body */}
                        <div>
                            <div className="flex items-center justify-between mb-1">
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('social.post_content')}</label>
                                <span className={`text-xs ${data.body.length > minCharLimit ? 'text-red-500 font-medium' : 'text-neutral-400'}`}>
                                    {data.body.length} / {minCharLimit}
                                </span>
                            </div>
                            <textarea
                                value={data.body}
                                onChange={e => setData('body', e.target.value)}
                                rows={8}
                                placeholder={t('social.body_placeholder')}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 resize-none focus:outline-none focus:ring-2 focus:ring-brand-500"
                            />
                            {errors.body && <p className="mt-1 text-xs text-red-500">{errors.body}</p>}
                        </div>
                    </div>

                    {/* Media */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-3">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('social.media')}</h3>
                            <button type="button"
                                onClick={() => setData('media_urls', [...(data.media_urls ?? []), ''])}
                                className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                                <Plus className="h-3.5 w-3.5" /> {t('social.add_media')}
                            </button>
                        </div>
                        {(data.media_urls ?? []).map((url, i) => (
                            <div key={i} className="flex items-start gap-2">
                                <div className="flex-1 min-w-0">
                                    <MediaUpload
                                        value={url}
                                        onChange={v => {
                                            const next = [...(data.media_urls ?? [])];
                                            next[i] = v;
                                            setData('media_urls', next);
                                        }}
                                        accept="image/*,video/*"
                                        collection="social"
                                        placeholder="https://cdn.example.com/image.jpg"
                                    />
                                </div>
                                <button type="button"
                                    onClick={() => setData('media_urls', (data.media_urls ?? []).filter((_, j) => j !== i))}
                                    className="mt-7 shrink-0 text-neutral-400 hover:text-red-500 transition">
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            </div>
                        ))}
                        {(data.media_urls ?? []).filter(Boolean).length === 0 && (
                            <p className="text-xs text-neutral-400">{t('social.no_media_attached')}</p>
                        )}
                    </div>

                    {/* Schedule */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-3">
                        <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200 flex items-center gap-1.5">
                            <Calendar className="h-4 w-4 text-neutral-400" /> {t('social.schedule')}
                            <span className="text-xs font-normal text-neutral-400">{t('social.schedule_draft_hint')}</span>
                        </h3>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label className="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">{t('social.date_time')}</label>
                                <DatePicker
                                    mode="datetime"
                                    value={data.scheduled_at}
                                    onChange={v => setData('scheduled_at', v)}
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">{t('social.timezone')}</label>
                                <TimezonePicker
                                    value={data.timezone}
                                    onChange={tz => setData('timezone', tz)}
                                />
                            </div>
                        </div>
                        {data.scheduled_at && (
                            <p className="text-xs text-neutral-400 flex items-center gap-1">
                                <Clock className="h-3 w-3" />
                                {t('social.publishes_at', { time: formatInTz(tzLocalToUtcIso(data.scheduled_at, data.timezone), data.timezone) })}
                            </p>
                        )}
                        {errors.scheduled_at && <p className="text-xs text-red-500">{errors.scheduled_at}</p>}
                    </div>

                    {/* Actions */}
                    <div className="flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={processing || !data.body.trim() || data.target_accounts.length === 0}
                            className="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition"
                        >
                            <Send className="h-4 w-4" />
                            {processing ? t('social.saving') : data.scheduled_at ? t('social.save_and_schedule') : t('social.save_as_draft')}
                        </button>
                        <Link
                            href={route('client.social.posts.index')}
                            className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-5 py-2.5 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                        >
                            {t('common.cancel')}
                        </Link>
                    </div>

                </form>
            </div>
        </ClientLayout>
    );
}
