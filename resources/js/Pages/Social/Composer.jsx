import { Head, usePage, useForm } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Send, Sparkles, Clock, Plus, Trash2, ThumbsUp, MessageCircle, Share2, Heart, Bookmark, Repeat2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SocialBrandIcon } from '@/Components/BrandIcons';
import MediaUpload from '@/Components/MediaUpload';
import TimezonePicker from '@/Components/TimezonePicker';
import { DatePicker } from '@/Components/ui';
import { browserTz, tzLocalToUtcIso, formatInTz } from '@/Utils/datetime';

const CHAR_LIMITS = { twitter: 280, tiktok: 2200, linkedin: 3000, facebook: 63206, instagram: 2200, youtube: 5000 };

const NETWORK_COLORS = {
    twitter:   '#000000',
    facebook:  '#1877F2',
    instagram: '#E1306C',
    linkedin:  '#0A66C2',
    tiktok:    '#000000',
    youtube:   '#FF0000',
};

const NETWORK_LABELS = {
    twitter: 'X (Twitter)', facebook: 'Facebook', instagram: 'Instagram',
    linkedin: 'LinkedIn',   tiktok: 'TikTok',     youtube: 'YouTube',
};

/* ── per-network preview cards ─────────────────────────────── */

function Avatar({ name, pictureUrl, className = 'h-10 w-10', ring = false }) {
    const inner = pictureUrl
        ? <img src={pictureUrl} alt={name} className={`${className} rounded-full object-cover shrink-0`} />
        : <div className={`${className} rounded-full bg-neutral-200 dark:bg-neutral-700 shrink-0 flex items-center justify-center text-xs font-bold text-neutral-500`}>
            {(name?.[0] ?? 'U').toUpperCase()}
          </div>;
    if (!ring) return inner;
    return (
        <div className={`${className} rounded-full bg-gradient-to-tr from-yellow-400 via-pink-500 to-purple-600 p-0.5 shrink-0`}>
            {pictureUrl
                ? <img src={pictureUrl} alt={name} className="h-full w-full rounded-full object-cover" />
                : <div className="h-full w-full rounded-full bg-white dark:bg-neutral-900 flex items-center justify-center text-xs font-bold text-neutral-500">
                    {(name?.[0] ?? 'U').toUpperCase()}
                  </div>
            }
        </div>
    );
}

function TwitterPreview({ body, mediaUrls, accountName, pictureUrl }) {
    const { t } = useTranslation();
    const handle = accountName ? `@${accountName.toLowerCase().replace(/\s+/g, '')}` : '@youraccount';
    return (
        <div className="rounded-2xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 text-sm font-[system-ui]">
            <div className="flex gap-3">
                <Avatar name={accountName} pictureUrl={pictureUrl} className="h-10 w-10" />
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <span className="font-bold text-neutral-900 dark:text-neutral-100">{accountName ?? t('social.preview_your_account')}</span>
                        <span className="text-neutral-500 text-xs">{handle} · now</span>
                    </div>
                    <p className="mt-1 text-neutral-800 dark:text-neutral-200 whitespace-pre-wrap break-words leading-snug">
                        {body || <span className="text-neutral-400 italic">{t('social.preview_tweet_placeholder')}</span>}
                    </p>
                    {mediaUrls?.[0] && <img src={mediaUrls[0]} alt="" className="mt-2 rounded-xl w-full object-cover max-h-48" />}
                    <div className="mt-3 flex items-center gap-5 text-neutral-500 text-xs">
                        <span className="flex items-center gap-1"><MessageCircle className="h-3.5 w-3.5" /> 0</span>
                        <span className="flex items-center gap-1"><Repeat2 className="h-3.5 w-3.5" /> 0</span>
                        <span className="flex items-center gap-1"><Heart className="h-3.5 w-3.5" /> 0</span>
                        <span className="flex items-center gap-1"><Bookmark className="h-3.5 w-3.5" /> 0</span>
                        <span className="flex items-center gap-1"><Share2 className="h-3.5 w-3.5" /> 0</span>
                    </div>
                </div>
            </div>
        </div>
    );
}

function FacebookPreview({ body, mediaUrls, accountName, pictureUrl }) {
    const { t } = useTranslation();
    return (
        <div className="rounded-2xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 text-sm font-[system-ui]">
            <div className="flex items-center gap-2 mb-3">
                <Avatar name={accountName} pictureUrl={pictureUrl} className="h-9 w-9" />
                <div>
                    <p className="font-semibold text-neutral-900 dark:text-neutral-100 text-sm">{accountName ?? t('social.preview_your_page')}</p>
                    <p className="text-xs text-neutral-500">{t('social.preview_just_now')} · <span className="text-blue-500">{t('social.preview_public')}</span></p>
                </div>
            </div>
            <p className="text-neutral-800 dark:text-neutral-200 whitespace-pre-wrap break-words leading-snug">
                {body || <span className="text-neutral-400 italic">{t('social.preview_post_placeholder')}</span>}
            </p>
            {mediaUrls?.[0] && <img src={mediaUrls[0]} alt="" className="mt-3 -mx-4 w-[calc(100%+2rem)] object-cover max-h-52" />}
            <div className="mt-3 flex items-center justify-between text-neutral-500 text-xs border-t border-neutral-100 dark:border-neutral-700 pt-2">
                <span className="flex items-center gap-1"><ThumbsUp className="h-3.5 w-3.5" /> {t('social.preview_like')}</span>
                <span className="flex items-center gap-1"><MessageCircle className="h-3.5 w-3.5" /> {t('social.preview_comment')}</span>
                <span className="flex items-center gap-1"><Share2 className="h-3.5 w-3.5" /> {t('social.preview_share')}</span>
            </div>
        </div>
    );
}

function InstagramPreview({ body, mediaUrls, accountName, pictureUrl }) {
    const { t } = useTranslation();
    const handle = accountName
        ? (accountName.startsWith('@') ? accountName.slice(1) : accountName.toLowerCase().replace(/\s+/g, '_'))
        : 'youraccount';
    return (
        <div className="rounded-2xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-sm font-[system-ui] overflow-hidden">
            <div className="flex items-center gap-2 px-3 py-2.5 border-b border-neutral-100 dark:border-neutral-800">
                <Avatar name={accountName} pictureUrl={pictureUrl} className="h-8 w-8" ring={true} />
                <span className="font-semibold text-neutral-900 dark:text-neutral-100 text-xs">@{handle}</span>
            </div>
            {mediaUrls?.[0]
                ? <img src={mediaUrls[0]} alt="" className="w-full object-cover max-h-52" />
                : <div className="w-full h-36 bg-gradient-to-br from-neutral-100 to-neutral-200 dark:from-neutral-800 dark:to-neutral-700 flex items-center justify-center text-neutral-400 text-xs">{t('social.preview_photo_video')}</div>
            }
            <div className="px-3 pt-2 pb-3">
                <div className="flex items-center gap-3 mb-2 text-neutral-600 dark:text-neutral-400">
                    <Heart className="h-5 w-5" /><MessageCircle className="h-5 w-5" /><Share2 className="h-5 w-5" />
                    <Bookmark className="h-5 w-5 ml-auto" />
                </div>
                <p className="text-xs text-neutral-800 dark:text-neutral-200 whitespace-pre-wrap break-words leading-snug">
                    <span className="font-semibold mr-1">{handle}</span>
                    {body || <span className="text-neutral-400 italic">{t('social.preview_caption_placeholder')}</span>}
                </p>
            </div>
        </div>
    );
}

function LinkedInPreview({ body, mediaUrls, accountName, pictureUrl }) {
    const { t } = useTranslation();
    return (
        <div className="rounded-2xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 text-sm font-[system-ui]">
            <div className="flex items-center gap-2 mb-3">
                <Avatar name={accountName} pictureUrl={pictureUrl} className="h-10 w-10" />
                <div>
                    <p className="font-semibold text-neutral-900 dark:text-neutral-100 text-sm">{accountName ?? t('social.preview_your_profile')}</p>
                    <p className="text-xs text-neutral-500">{t('social.preview_now')} · <span>🌐</span></p>
                </div>
            </div>
            <p className="text-neutral-800 dark:text-neutral-200 whitespace-pre-wrap break-words leading-snug">
                {body || <span className="text-neutral-400 italic">{t('social.preview_post_placeholder')}</span>}
            </p>
            {mediaUrls?.[0] && <img src={mediaUrls[0]} alt="" className="mt-3 rounded-lg w-full object-cover max-h-48" />}
            <div className="mt-3 flex items-center gap-4 text-neutral-500 text-xs border-t border-neutral-100 dark:border-neutral-700 pt-2">
                <span className="flex items-center gap-1"><ThumbsUp className="h-3.5 w-3.5" /> {t('social.preview_like')}</span>
                <span className="flex items-center gap-1"><MessageCircle className="h-3.5 w-3.5" /> {t('social.preview_comment')}</span>
                <span className="flex items-center gap-1"><Repeat2 className="h-3.5 w-3.5" /> {t('social.preview_repost')}</span>
                <span className="flex items-center gap-1"><Share2 className="h-3.5 w-3.5" /> {t('social.preview_send')}</span>
            </div>
        </div>
    );
}

function TikTokPreview({ body, mediaUrls, accountName }) {
    const { t } = useTranslation();
    const handle = accountName ? `@${accountName.toLowerCase().replace(/\s+/g, '')}` : '@youraccount';
    return (
        <div className="rounded-2xl border border-neutral-200 dark:border-neutral-700 bg-black text-sm font-[system-ui] overflow-hidden">
            <div className="relative">
                {mediaUrls?.[0]
                    ? <img src={mediaUrls[0]} alt="" className="w-full object-cover max-h-64" />
                    : <div className="w-full h-52 bg-neutral-900 flex items-center justify-center text-neutral-600 text-xs">{t('social.preview_video')}</div>
                }
                <div className="absolute bottom-0 left-0 right-0 p-3 bg-gradient-to-t from-black/80 to-transparent">
                    <p className="text-white font-semibold text-xs mb-1">{handle}</p>
                    <p className="text-white/90 text-xs whitespace-pre-wrap break-words line-clamp-2">
                        {body || <span className="text-white/50 italic">{t('social.preview_caption_short')}</span>}
                    </p>
                </div>
                <div className="absolute right-2 bottom-10 flex flex-col items-center gap-3 text-white text-xs">
                    <div className="flex flex-col items-center"><Heart className="h-5 w-5" /><span>0</span></div>
                    <div className="flex flex-col items-center"><MessageCircle className="h-5 w-5" /><span>0</span></div>
                    <div className="flex flex-col items-center"><Share2 className="h-5 w-5" /><span>0</span></div>
                </div>
            </div>
        </div>
    );
}

function YouTubePreview({ body, mediaUrls, accountName }) {
    const { t } = useTranslation();
    return (
        <div className="rounded-2xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-sm font-[system-ui] overflow-hidden">
            {mediaUrls?.[0]
                ? <img src={mediaUrls[0]} alt="" className="w-full object-cover max-h-44" />
                : <div className="w-full h-36 bg-neutral-200 dark:bg-neutral-800 flex items-center justify-center">
                    <div className="h-12 w-12 rounded-full bg-red-600 flex items-center justify-center">
                        <div className="border-t-[8px] border-b-[8px] border-l-[14px] border-t-transparent border-b-transparent border-l-white ml-1" />
                    </div>
                  </div>
            }
            <div className="p-3">
                <p className="font-semibold text-neutral-900 dark:text-neutral-100 line-clamp-2 leading-snug">
                    {body?.split('\n')[0] || <span className="text-neutral-400 italic">{t('social.preview_video_title_placeholder')}</span>}
                </p>
                <p className="text-xs text-neutral-500 mt-1">{accountName ?? t('social.preview_your_channel')} · {t('social.preview_youtube_meta')}</p>
            </div>
        </div>
    );
}

const PREVIEW_COMPONENTS = {
    twitter:   TwitterPreview,
    facebook:  FacebookPreview,
    instagram: InstagramPreview,
    linkedin:  LinkedInPreview,
    tiktok:    TikTokPreview,
    youtube:   YouTubePreview,
};

/* ── main component ─────────────────────────────────────────── */

export default function SocialComposer({ accounts }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const userTz = props.timezone || browserTz() || 'Asia/Dhaka';

    const { data, setData, post, processing, reset, errors, transform } = useForm({
        body:            '',
        title:           '',
        media_urls:      [''],
        target_accounts: [],
        scheduled_at:    '',
        timezone:        userTz,
    });

    const [aiLoading, setAiLoading] = useState(false);
    const [aiPrompt, setAiPrompt] = useState('');
    const [aiError, setAiError] = useState('');

    const selectedAccounts = accounts.filter(a => data.target_accounts.includes(a.id.toString()));
    const selectedNetworks  = selectedAccounts.map(a => a.network);
    const minCharLimit = selectedNetworks.length > 0 ? Math.min(...selectedNetworks.map(n => CHAR_LIMITS[n] ?? 5000)) : 5000;

    const toggleAccount = (id) => {
        const sid = id.toString();
        setData('target_accounts', data.target_accounts.includes(sid) ? data.target_accounts.filter(a => a !== sid) : [...data.target_accounts, sid]);
    };

    const generateWithAI = async () => {
        if (!aiPrompt.trim()) return;
        setAiLoading(true);
        setAiError('');
        try {
            const res = await fetch(route('client.social.ai-generate'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content },
                body: JSON.stringify({ prompt: aiPrompt, network: selectedNetworks[0] ?? '' }),
            });
            const json = await res.json();
            if (json.body) {
                setData('body', json.body);
            } else {
                setAiError(json.error ?? t('social.ai_generate_failed'));
            }
        } catch {
            setAiError(t('social.ai_request_failed'));
        } finally {
            setAiLoading(false);
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        // Convert wall-clock datetime-local value to UTC ISO; strip blank media URLs.
        transform(d => ({
            ...d,
            scheduled_at: d.scheduled_at ? tzLocalToUtcIso(d.scheduled_at, d.timezone || 'UTC') : null,
            media_urls: (d.media_urls ?? []).filter(Boolean),
        }));
        post(route('client.social.posts.store'), { preserveScroll: true, onSuccess: () => reset() });
    };

    const mediaUrls = (data.media_urls ?? []).filter(Boolean);

    return (
        <ClientLayout title={t('social.composer_title')}>
            <Head title={t('social.composer_head')} />
            <div className="flex gap-6 items-start">

                {/* ── LEFT: composer ── */}
                <div className="w-full max-w-xl shrink-0 space-y-5">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('social.composer_title')}</h2>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">{t('social.composer_subtitle')}</p>
                    </div>

                    {flash.success && <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>}

                    {/* Account selector */}
                    <div className={`rounded-xl border bg-white dark:bg-neutral-900 p-4 ${errors.target_accounts ? 'border-red-400 ring-2 ring-red-300 dark:ring-red-700' : 'border-neutral-200 dark:border-neutral-700'}`}>
                        <p className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase mb-3">{t('social.post_to')}</p>
                        <div className="flex flex-wrap gap-2">
                            {accounts.map(account => (
                                <button key={account.id} type="button" onClick={() => toggleAccount(account.id)}
                                    className={`flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm border transition ${data.target_accounts.includes(account.id.toString()) ? 'bg-brand-600 border-brand-600 text-white' : 'border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:border-brand-300'}`}>
                                    <div className="relative shrink-0">
                                        {account.picture_url
                                            ? <img src={account.picture_url} alt={account.name} className="h-5 w-5 rounded-full object-cover" />
                                            : <SocialBrandIcon network={account.network} className="h-4 w-4" />
                                        }
                                        {account.picture_url && (
                                            <span className="absolute -bottom-0.5 -right-0.5 flex h-3 w-3 items-center justify-center rounded-full bg-white dark:bg-neutral-900 ring-1 ring-white dark:ring-neutral-900">
                                                <SocialBrandIcon network={account.network} className="h-2.5 w-2.5" />
                                            </span>
                                        )}
                                    </div>
                                    {account.name}
                                </button>
                            ))}
                            {accounts.length === 0 && <p className="text-sm text-neutral-400">{t('social.no_accounts_connected')} <a href={route('client.social.accounts.index')} className="text-brand-600 hover:underline">{t('social.add_one')}</a></p>}
                        </div>
                        {errors.target_accounts && <p className="mt-1 text-xs text-red-500">{errors.target_accounts}</p>}
                    </div>

                    {/* AI Planner */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 space-y-3">
                        <p className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase flex items-center gap-1"><Sparkles className="h-3.5 w-3.5 text-brand-500" /> {t('social.ai_post_planner')}</p>
                        <div className="flex gap-2">
                            <input type="text" value={aiPrompt} onChange={e => setAiPrompt(e.target.value)} placeholder={t('social.ai_prompt_placeholder')} className="flex-1 rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm" />
                            <button type="button" onClick={generateWithAI} disabled={aiLoading || !aiPrompt.trim()} className="ai-glow flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition">
                                <Sparkles className="h-4 w-4" /> {aiLoading ? t('social.generating') : t('social.generate')}
                            </button>
                        </div>
                        {aiError && <p className="text-xs text-red-500 mt-1">{aiError}</p>}
                    </div>

                    {/* Composer */}
                    <form onSubmit={handleSubmit} className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 space-y-4">
                        {/* Title */}
                        <div>
                            <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400 block mb-1">
                                {t('social.title_label')} <span className="text-neutral-400 font-normal">({t('common.optional')})</span>
                            </label>
                            <input
                                type="text"
                                value={data.title}
                                onChange={e => setData('title', e.target.value)}
                                placeholder={t('social.title_placeholder')}
                                maxLength={256}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
                            />
                            {errors.title && <p className="mt-1 text-xs text-red-500">{errors.title}</p>}
                        </div>

                        {/* Body */}
                        <div>
                            <div className="flex items-center justify-between mb-1">
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('social.post_content')}</label>
                                <span className={`text-xs ${data.body.length > minCharLimit ? 'text-red-500' : 'text-neutral-400'}`}>
                                    {data.body.length} / {minCharLimit}
                                </span>
                            </div>
                            <textarea
                                value={data.body}
                                onChange={e => setData('body', e.target.value)}
                                rows={6}
                                placeholder={t('social.body_placeholder')}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500"
                            />
                            {errors.body && <p className="mt-1 text-xs text-red-500">{errors.body}</p>}
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('social.media')}</label>
                                <button
                                    type="button"
                                    onClick={() => setData('media_urls', [...(data.media_urls ?? []), ''])}
                                    className="flex items-center gap-1 text-xs text-brand-600 hover:text-brand-700 font-medium"
                                >
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
                                    <button
                                        type="button"
                                        onClick={() => setData('media_urls', (data.media_urls ?? []).filter((_, j) => j !== i))}
                                        className="mt-7 shrink-0 text-neutral-400 hover:text-red-500"
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </button>
                                </div>
                            ))}
                            {(data.media_urls ?? []).length === 0 && (
                                <p className="text-xs text-neutral-400">{t('social.no_media_hint')}</p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400 flex items-center gap-1"><Clock className="h-3.5 w-3.5" /> {t('social.schedule_optional')}</label>
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <DatePicker
                                    mode="datetime"
                                    value={data.scheduled_at}
                                    onChange={v => setData('scheduled_at', v)}
                                />
                                <TimezonePicker
                                    value={data.timezone}
                                    onChange={tz => setData('timezone', tz)}
                                />
                            </div>
                            {data.scheduled_at && (
                                <p className="text-xs text-neutral-400">
                                    {t('social.publishes_at', { time: formatInTz(tzLocalToUtcIso(data.scheduled_at, data.timezone), data.timezone) })}
                                </p>
                            )}
                            {errors.scheduled_at && <p className="text-xs text-red-500">{errors.scheduled_at}</p>}
                        </div>

                        {(
                            <div className="flex gap-2 pt-1">
                                <button type="submit" disabled={processing || !data.body.trim()} className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition">
                                    <Send className="h-4 w-4" />
                                    {data.scheduled_at
                                        ? (processing ? t('social.scheduling') : t('social.schedule'))
                                        : (processing ? t('social.publishing') : t('social.publish_now'))}
                                </button>
                            </div>
                        )}
                    </form>
                </div>

                {/* ── RIGHT: previews ── */}
                <div className="flex-1 min-w-0">
                    <div className="sticky top-6 space-y-4">
                        <div className="flex items-center gap-2">
                            <h3 className="text-sm font-semibold text-neutral-700 dark:text-neutral-300">{t('social.post_preview')}</h3>
                            {selectedAccounts.length > 0 && (
                                <span className="text-xs text-neutral-400">({t('social.network_count', { count: selectedAccounts.length })})</span>
                            )}
                        </div>

                        {selectedAccounts.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-neutral-300 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-900/50 p-10 text-center text-sm text-neutral-400">
                                {t('social.preview_empty')}
                            </div>
                        ) : (
                            selectedAccounts.map(account => {
                                const Preview = PREVIEW_COMPONENTS[account.network];
                                if (!Preview) return null;
                                return (
                                    <div key={account.id}>
                                        <div className="flex items-center gap-1.5 mb-1.5 px-0.5">
                                            <SocialBrandIcon network={account.network} className="h-3.5 w-3.5 shrink-0" />
                                            <span className="text-xs font-medium text-neutral-500 dark:text-neutral-400">
                                                {NETWORK_LABELS[account.network] ?? account.network} · {account.name}
                                            </span>
                                        </div>
                                        <Preview body={data.body} mediaUrls={mediaUrls} accountName={account.name} pictureUrl={account.picture_url} />
                                    </div>
                                );
                            })
                        )}
                    </div>
                </div>

            </div>
        </ClientLayout>
    );
}
