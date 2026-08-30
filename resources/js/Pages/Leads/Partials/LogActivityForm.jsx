import Button from '@/Components/ui/Button';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Phone, StickyNote, Mail, MessageCircle, Users } from 'lucide-react';

const TYPE_ICONS = { call: Phone, note: StickyNote, whatsapp: MessageCircle, email: Mail, meeting: Users };

/** Shortcuts instead of a date picker — a rep chasing leads wants "in 3 days", not a calendar. */
const PRESETS = [
    { key: 'tomorrow', days: 1 },
    { key: 'in_3_days', days: 3 },
    { key: 'next_week', days: 7 },
];

function atNineAmIn(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);
    d.setHours(9, 0, 0, 0);

    // The <input type="datetime-local"> value must be local time, not the UTC
    // that toISOString() would give.
    const pad = (n) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export default function LogActivityForm({ lead, stages, types, outcomes, onLogged }) {
    const { t } = useTranslation();

    const { data, setData, post, processing, errors, reset } = useForm({
        type: 'call',
        body: '',
        outcome: '',
        next_follow_up_at: '',
        clear_follow_up: false,
        stage_id: lead.stage_id ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('client.leads.pipeline.activities.store', lead.id), {
            preserveScroll: true,
            onSuccess: () => { reset(); onLogged?.(); },
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-soft border border-soft bg-neutral-50 p-3 dark:bg-neutral-900/60">
            <div className="flex flex-wrap gap-1.5">
                {types.map((type) => {
                    const Icon = TYPE_ICONS[type] ?? StickyNote;
                    const active = data.type === type;

                    return (
                        <button
                            key={type}
                            type="button"
                            onClick={() => setData('type', type)}
                            aria-pressed={active}
                            className={`inline-flex items-center gap-1 rounded-soft px-2 py-1 text-xs font-medium transition-colors ${
                                active
                                    ? 'bg-brand-500 text-white'
                                    : 'bg-white text-neutral-600 hover:bg-neutral-100 dark:bg-neutral-800 dark:text-neutral-300'
                            }`}
                        >
                            <Icon className="h-3 w-3" />
                            {t(`leads.activity_${type}`)}
                        </button>
                    );
                })}
            </div>

            <div>
                <textarea
                    value={data.body}
                    onChange={(e) => setData('body', e.target.value)}
                    rows={2}
                    maxLength={2000}
                    placeholder={t('leads.activity_body_placeholder')}
                    aria-label={t('leads.activity_body_placeholder')}
                    className="w-full rounded-soft border-soft bg-white text-sm dark:bg-neutral-900 dark:text-neutral-100"
                />
                {errors.body && <p className="mt-1 text-xs text-coral-600 dark:text-coral-400">{errors.body}</p>}
            </div>

            {data.type === 'call' && (
                <div className="flex flex-wrap gap-1.5">
                    {outcomes.map((outcome) => (
                        <button
                            key={outcome}
                            type="button"
                            onClick={() => setData('outcome', data.outcome === outcome ? '' : outcome)}
                            aria-pressed={data.outcome === outcome}
                            className={`rounded-soft border px-2 py-0.5 text-xs transition-colors ${
                                data.outcome === outcome
                                    ? 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300'
                                    : 'border-soft text-neutral-500 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                            }`}
                        >
                            {t(`leads.outcome_${outcome}`)}
                        </button>
                    ))}
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label htmlFor="next-follow-up" className="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-400">
                        {t('leads.next_follow_up')}
                    </label>
                    <input
                        id="next-follow-up"
                        type="datetime-local"
                        value={data.next_follow_up_at}
                        onChange={(e) => setData({ ...data, next_follow_up_at: e.target.value, clear_follow_up: false })}
                        className="w-full rounded-soft border-soft bg-white text-xs dark:bg-neutral-900 dark:text-neutral-100"
                    />
                    <div className="mt-1 flex flex-wrap gap-1">
                        {PRESETS.map((p) => (
                            <button
                                key={p.key}
                                type="button"
                                onClick={() => setData({ ...data, next_follow_up_at: atNineAmIn(p.days), clear_follow_up: false })}
                                className="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300"
                            >
                                {t(`leads.preset_${p.key}`)}
                            </button>
                        ))}
                        {lead.next_follow_up_at && (
                            <button
                                type="button"
                                onClick={() => setData({ ...data, next_follow_up_at: '', clear_follow_up: true })}
                                className={`rounded px-1.5 py-0.5 text-xs ${
                                    data.clear_follow_up
                                        ? 'bg-coral-500 text-white'
                                        : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300'
                                }`}
                            >
                                {t('leads.clear_follow_up')}
                            </button>
                        )}
                    </div>
                </div>

                <div>
                    <label htmlFor="log-stage" className="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-400">
                        {t('leads.move_to_stage')}
                    </label>
                    <select
                        id="log-stage"
                        value={data.stage_id ?? ''}
                        onChange={(e) => setData('stage_id', e.target.value)}
                        className="w-full rounded-soft border-soft bg-white text-xs dark:bg-neutral-900 dark:text-neutral-100"
                    >
                        {stages.map((s) => (
                            <option key={s.id} value={s.id}>{s.name}</option>
                        ))}
                    </select>
                    <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">{t('leads.move_with_log_hint')}</p>
                </div>
            </div>

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing}>{t('leads.log_activity')}</Button>
            </div>
        </form>
    );
}
