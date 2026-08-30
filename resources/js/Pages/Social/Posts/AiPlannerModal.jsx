import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Sparkles, Loader2, ChevronLeft, Calendar, CheckSquare, Square, AlertCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { SocialBrandIcon } from '@/Components/BrandIcons';
import { browserTz, tzLocalToUtcIso } from '@/Utils/datetime';
import TimezonePicker from '@/Components/TimezonePicker';
import { DatePicker } from '@/Components/ui';

const TONES = [
    { value: 'professional',  labelKey: 'social.tone_professional' },
    { value: 'casual',        labelKey: 'social.tone_casual' },
    { value: 'humorous',      labelKey: 'social.tone_humorous' },
    { value: 'inspirational', labelKey: 'social.tone_inspirational' },
    { value: 'educational',   labelKey: 'social.tone_educational' },
];

const CHAR_LIMITS = { twitter: 280, tiktok: 2200, linkedin: 3000, facebook: 63206, instagram: 2200, youtube: 5000 };

const NETWORK_LABELS = {
    facebook: 'Facebook', instagram: 'Instagram', linkedin: 'LinkedIn',
    twitter: 'X (Twitter)', youtube: 'YouTube', tiktok: 'TikTok',
};

function utcToLocalInput(utcIso) {
    if (!utcIso) return '';
    try {
        return new Date(utcIso).toISOString().slice(0, 16);
    } catch {
        return '';
    }
}

function todayDate() {
    return new Date().toISOString().slice(0, 10);
}

function addDays(dateStr, days) {
    const d = new Date(dateStr);
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
}

// ─── Brief Step ──────────────────────────────────────────────────────────────

function BriefStep({ brief, setBrief, accounts, onGenerate, loading, error }) {
    const { t } = useTranslation();
    const toggleAccount = (id) => {
        setBrief(prev => {
            const set = new Set(prev.target_accounts);
            set.has(id) ? set.delete(id) : set.add(id);
            return { ...prev, target_accounts: [...set] };
        });
    };

    return (
        <div className="space-y-5">
            {error && (
                <div className="flex items-start gap-2 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 px-4 py-3 text-sm">
                    <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
                    <span>{error}</span>
                </div>
            )}

            <div>
                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                    {t('social.campaign_topic')} <span className="text-red-500">*</span>
                </label>
                <textarea
                    value={brief.topic}
                    onChange={e => setBrief(p => ({ ...p, topic: e.target.value }))}
                    rows={3}
                    maxLength={500}
                    placeholder={t('social.campaign_topic_placeholder')}
                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none"
                />
                <p className="text-xs text-neutral-400 mt-0.5 text-right">{brief.topic.length}/500</p>
            </div>

            <div>
                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('social.campaign_goal')} <span className="text-neutral-400 font-normal">({t('common.optional')})</span></label>
                <input
                    type="text"
                    value={brief.campaign_goal}
                    onChange={e => setBrief(p => ({ ...p, campaign_goal: e.target.value }))}
                    maxLength={200}
                    placeholder={t('social.campaign_goal_placeholder')}
                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
                />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('social.tone')}</label>
                    <select
                        value={brief.tone}
                        onChange={e => setBrief(p => ({ ...p, tone: e.target.value }))}
                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                        {TONES.map(tone => <option key={tone.value} value={tone.value}>{t(tone.labelKey)}</option>)}
                    </select>
                </div>
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('social.number_of_posts')}</label>
                    <input
                        type="number"
                        min={3}
                        max={14}
                        value={brief.post_count}
                        onChange={e => setBrief(p => ({ ...p, post_count: Math.max(3, Math.min(14, parseInt(e.target.value) || 7)) }))}
                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                    />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('social.start_date')} <span className="text-red-500">*</span></label>
                    <DatePicker
                        value={brief.start_date}
                        min={todayDate()}
                        onChange={v => setBrief(p => ({ ...p, start_date: v }))}
                    />
                </div>
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('social.end_date')} <span className="text-red-500">*</span></label>
                    <DatePicker
                        value={brief.end_date}
                        min={brief.start_date ? addDays(brief.start_date, 1) : todayDate()}
                        onChange={v => setBrief(p => ({ ...p, end_date: v }))}
                    />
                </div>
            </div>

            <div>
                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('social.timezone')}</label>
                <TimezonePicker
                    value={brief.timezone}
                    onChange={tz => setBrief(p => ({ ...p, timezone: tz }))}
                />
            </div>

            <div>
                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-2">{t('social.target_accounts')} <span className="text-red-500">*</span></label>
                {accounts.length === 0 ? (
                    <p className="text-sm text-neutral-400">{t('social.no_connected_accounts')}</p>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        {accounts.map(a => {
                            const selected = brief.target_accounts.includes(a.id);
                            return (
                                <button
                                    key={a.id}
                                    type="button"
                                    onClick={() => toggleAccount(a.id)}
                                    className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition ${
                                        selected
                                            ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300'
                                            : 'border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-600 dark:text-neutral-400 hover:border-brand-400'
                                    }`}
                                >
                                    <SocialBrandIcon network={a.network} className="h-3.5 w-3.5" />
                                    {a.name}
                                </button>
                            );
                        })}
                    </div>
                )}
            </div>

            <button
                type="button"
                onClick={onGenerate}
                disabled={loading || !brief.topic.trim() || !brief.start_date || !brief.end_date || brief.target_accounts.length === 0}
                className="ai-glow w-full inline-flex items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
            >
                {loading ? <><Loader2 className="h-4 w-4 animate-spin" /> {t('social.generating_plan')}</> : <><Sparkles className="h-4 w-4" /> {t('social.generate_plan')}</>}
            </button>
        </div>
    );
}

// ─── Review Step ─────────────────────────────────────────────────────────────

function ReviewStep({ editedPosts, setEditedPosts, approved, setApproved, selectedAccounts, error }) {
    const { t } = useTranslation();
    const minLimit = selectedAccounts.length > 0
        ? Math.min(...selectedAccounts.map(a => CHAR_LIMITS[a.network] ?? 5000))
        : 5000;

    const toggleAll = () => {
        if (approved.size === editedPosts.length) {
            setApproved(new Set());
        } else {
            setApproved(new Set(editedPosts.map((_, i) => i)));
        }
    };

    const toggleOne = (i) => {
        setApproved(prev => {
            const next = new Set(prev);
            next.has(i) ? next.delete(i) : next.add(i);
            return next;
        });
    };

    const updatePost = (i, field, value) => {
        setEditedPosts(prev => prev.map((p, idx) => idx === i ? { ...p, [field]: value } : p));
    };

    return (
        <div className="space-y-4">
            {error && (
                <div className="flex items-start gap-2 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 px-4 py-3 text-sm">
                    <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
                    <span>{error}</span>
                </div>
            )}

            <div className="flex items-center justify-between">
                <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('social.posts_selected', { count: approved.size, total: editedPosts.length })}</p>
                <button
                    type="button"
                    onClick={toggleAll}
                    className="text-xs text-brand-600 dark:text-brand-400 hover:underline"
                >
                    {approved.size === editedPosts.length ? t('social.deselect_all') : t('social.select_all')}
                </button>
            </div>

            {editedPosts.map((post, i) => {
                const isApproved = approved.has(i);
                const charCount = post.body.length;
                const overLimit = charCount > minLimit;

                return (
                    <div
                        key={i}
                        className={`rounded-xl border p-4 space-y-3 transition ${
                            isApproved
                                ? 'border-brand-400 dark:border-brand-600 bg-brand-50/50 dark:bg-brand-900/10'
                                : 'border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 opacity-60'
                        }`}
                    >
                        <div className="flex items-start gap-3">
                            <button type="button" onClick={() => toggleOne(i)} className="mt-0.5 shrink-0 text-brand-600 dark:text-brand-400">
                                {isApproved ? <CheckSquare className="h-4 w-4" /> : <Square className="h-4 w-4 text-neutral-400" />}
                            </button>
                            <div className="flex-1 space-y-3">
                                <input
                                    type="text"
                                    value={post.title}
                                    onChange={e => updatePost(i, 'title', e.target.value)}
                                    placeholder={t('social.post_title_optional')}
                                    maxLength={100}
                                    className="w-full rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm font-medium text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                                />
                                <div>
                                    <textarea
                                        value={post.body}
                                        onChange={e => updatePost(i, 'body', e.target.value)}
                                        rows={4}
                                        maxLength={5000}
                                        className={`w-full rounded-lg border px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 bg-white dark:bg-neutral-800 focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none ${
                                            overLimit ? 'border-red-400' : 'border-neutral-200 dark:border-neutral-700'
                                        }`}
                                    />
                                    <p className={`text-xs mt-0.5 text-right ${overLimit ? 'text-red-500' : 'text-neutral-400'}`}>
                                        {charCount}/{minLimit}
                                    </p>
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">
                                        <Calendar className="inline h-3 w-3 mr-1" />{t('social.scheduled_time_utc')}
                                    </label>
                                    <DatePicker
                                        mode="datetime"
                                        value={post.scheduled_at_local ?? ''}
                                        onChange={v => updatePost(i, 'scheduled_at_local', v)}
                                    />
                                </div>
                                {post.rationale && (
                                    <p className="text-xs text-neutral-400 italic">{post.rationale}</p>
                                )}
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

// ─── Main Modal ───────────────────────────────────────────────────────────────

export default function AiPlannerModal({ show, onClose, accounts, onSuccess }) {
    const { t } = useTranslation();
    const userTz = browserTz() || 'UTC';
    const today = todayDate();

    const [step, setStep] = useState('brief');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [editedPosts, setEditedPosts] = useState([]);
    const [approved, setApproved] = useState(new Set());
    const [brief, setBrief] = useState({
        topic: '',
        campaign_goal: '',
        tone: 'professional',
        post_count: 7,
        start_date: today,
        end_date: addDays(today, 13),
        target_accounts: accounts.map(a => a.id),
        timezone: userTz,
    });

    if (!show) return null;

    const csrfToken = document.querySelector('meta[name=csrf-token]')?.content;
    const headers = {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken,
    };

    const selectedAccounts = accounts.filter(a => brief.target_accounts.includes(a.id));

    const handleGenerate = async () => {
        setError('');
        setLoading(true);
        try {
            const res = await fetch(route('client.social.ai-plan'), {
                method: 'POST',
                headers,
                body: JSON.stringify(brief),
            });
            const json = await res.json();

            if (!res.ok || json.error) {
                setError(json.error ?? json.errors?.topic?.[0] ?? t('social.generation_failed'));
                return;
            }

            const posts = json.posts.map(p => ({
                ...p,
                scheduled_at_local: utcToLocalInput(p.suggested_time),
            }));

            setEditedPosts(posts);
            setApproved(new Set(posts.map((_, i) => i)));
            setStep('review');
        } catch {
            setError(t('social.network_error'));
        } finally {
            setLoading(false);
        }
    };

    const handleSchedule = async () => {
        setError('');
        const postsToCreate = editedPosts
            .filter((_, i) => approved.has(i))
            .map(p => ({
                title: p.title || null,
                body: p.body,
                scheduled_at: p.scheduled_at_local ? tzLocalToUtcIso(p.scheduled_at_local, brief.timezone) : null,
                timezone: brief.timezone,
                target_accounts: brief.target_accounts,
                ai_prompt: brief.topic,
            }));

        if (postsToCreate.length === 0) {
            setError(t('social.select_one_post'));
            return;
        }

        setLoading(true);
        try {
            const res = await fetch(route('client.social.posts.bulk'), {
                method: 'POST',
                headers,
                body: JSON.stringify({ posts: postsToCreate }),
            });
            const json = await res.json();

            if (res.status === 402) {
                setError(json.message ?? t('social.plan_limit_reached'));
                return;
            }

            if (!res.ok || !json.success) {
                const firstError = json.errors ? Object.values(json.errors)[0]?.[0] : t('social.scheduling_failed');
                setError(firstError);
                return;
            }

            onSuccess();
        } catch {
            setError(t('social.network_error'));
        } finally {
            setLoading(false);
        }
    };

    const approvedCount = approved.size;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" onClick={e => { if (e.target === e.currentTarget && !loading) onClose(); }}>
            <div className="w-full max-w-2xl bg-white dark:bg-neutral-900 rounded-2xl shadow-2xl flex flex-col max-h-[90vh]">
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-neutral-200 dark:border-neutral-700 shrink-0">
                    <div className="flex items-center gap-2">
                        <Sparkles className="h-5 w-5 text-brand-600" />
                        <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">
                            {step === 'brief' ? t('social.ai_post_planner') : step === 'review' ? t('social.review_generated_posts') : t('social.confirm_and_schedule')}
                        </h2>
                    </div>
                    {!loading && (
                        <button onClick={onClose} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition text-xl leading-none">&times;</button>
                    )}
                </div>

                {/* Step indicators */}
                <div className="flex items-center gap-1 px-6 pt-4 shrink-0">
                    {['brief', 'review'].map((s, idx) => (
                        <div key={s} className="flex items-center gap-1">
                            <div className={`h-2 w-2 rounded-full ${step === s || (step === 'review' && idx === 0) ? 'bg-brand-600' : 'bg-neutral-200 dark:bg-neutral-700'}`} />
                            {idx === 0 && <div className={`h-0.5 w-8 ${step === 'review' ? 'bg-brand-600' : 'bg-neutral-200 dark:bg-neutral-700'}`} />}
                        </div>
                    ))}
                    <span className="ml-2 text-xs text-neutral-400">{step === 'brief' ? t('social.step_1_of_2') : t('social.step_2_of_2')}</span>
                </div>

                {/* Body */}
                <div className="flex-1 overflow-y-auto px-6 py-4">
                    {step === 'brief' && (
                        <BriefStep
                            brief={brief}
                            setBrief={setBrief}
                            accounts={accounts}
                            onGenerate={handleGenerate}
                            loading={loading}
                            error={error}
                        />
                    )}
                    {step === 'review' && (
                        <ReviewStep
                            editedPosts={editedPosts}
                            setEditedPosts={setEditedPosts}
                            approved={approved}
                            setApproved={setApproved}
                            selectedAccounts={selectedAccounts}
                            error={error}
                        />
                    )}
                </div>

                {/* Footer */}
                {step === 'review' && (
                    <div className="flex items-center justify-between gap-3 px-6 py-4 border-t border-neutral-200 dark:border-neutral-700 shrink-0">
                        <button
                            type="button"
                            onClick={() => { setStep('brief'); setError(''); }}
                            disabled={loading}
                            className="inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300 disabled:opacity-50 transition"
                        >
                            <ChevronLeft className="h-4 w-4" /> {t('common.back')}
                        </button>
                        <button
                            type="button"
                            onClick={handleSchedule}
                            disabled={loading || approvedCount === 0}
                            className="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
                        >
                            {loading ? <><Loader2 className="h-4 w-4 animate-spin" /> {t('social.scheduling')}</> : <><Calendar className="h-4 w-4" /> {t('social.schedule_n_posts', { count: approvedCount })}</>}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
