import {
    Phone, StickyNote, Mail, MessageCircle, Users,
    ArrowRight, Flame, UserCheck, Sparkles, Clock,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';

const ICONS = {
    call: Phone,
    note: StickyNote,
    email: Mail,
    whatsapp: MessageCircle,
    meeting: Users,
    stage_changed: ArrowRight,
    qualified: Flame,
    pushed_to_contacts: UserCheck,
    created: Sparkles,
    rescored: Sparkles,
};

const TONES = {
    qualified: 'bg-coral-100 text-coral-700 dark:bg-coral-950/50 dark:text-coral-300',
    stage_changed: 'bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300',
    pushed_to_contacts: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
};

/** "3 minutes ago" without pulling in a date library. */
function useRelativeTime() {
    const { i18n, t } = useTranslation();

    return (iso) => {
        if (!iso) return '';
        const then = new Date(iso);
        const seconds = Math.round((then - new Date()) / 1000);
        const units = [
            ['year', 31536000], ['month', 2592000], ['week', 604800],
            ['day', 86400], ['hour', 3600], ['minute', 60],
        ];

        for (const [unit, secs] of units) {
            if (Math.abs(seconds) >= secs) {
                return new Intl.RelativeTimeFormat(i18n.language, { numeric: 'auto' })
                    .format(Math.round(seconds / secs), unit);
            }
        }

        return t('leads.just_now');
    };
}

export default function ActivityTimeline({ activities }) {
    const { t } = useTranslation();
    const relative = useRelativeTime();

    if (!activities?.length) {
        return (
            <p className="py-6 text-center text-sm text-neutral-400 dark:text-neutral-600">
                {t('leads.no_activity_yet')}
            </p>
        );
    }

    // System rows describe themselves from meta; a person's row shows what they wrote.
    const headline = (a) => {
        switch (a.type) {
            case 'stage_changed':
                return t('leads.activity_stage_changed', {
                    from: a.meta?.from ?? t('leads.stage_none'),
                    to: a.meta?.to ?? '',
                });
            case 'qualified':
                return t('leads.activity_qualified', { score: a.meta?.score ?? '' });
            case 'pushed_to_contacts':
                return t('leads.activity_pushed_to_contacts');
            case 'created':
                return t('leads.activity_created');
            default:
                return t(`leads.activity_${a.type}`);
        }
    };

    return (
        <ol className="relative space-y-4 pl-6">
            {/* The rail the dots sit on. */}
            <span className="absolute bottom-2 left-[11px] top-2 w-px bg-neutral-200 dark:bg-neutral-800" aria-hidden="true" />

            {activities.map((a) => {
                const Icon = ICONS[a.type] ?? StickyNote;
                const tone = TONES[a.type] ?? 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400';

                return (
                    <li key={a.id} className="relative">
                        <span className={`absolute -left-6 flex h-[22px] w-[22px] items-center justify-center rounded-full ring-4 ring-white dark:ring-neutral-900 ${tone}`}>
                            <Icon className="h-3 w-3" aria-hidden="true" />
                        </span>

                        <div className="flex flex-wrap items-baseline gap-x-2">
                            <p className="text-sm font-medium text-neutral-800 dark:text-neutral-200">{headline(a)}</p>
                            {a.meta?.outcome && (
                                <span className="rounded-soft bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                    {t(`leads.outcome_${a.meta.outcome}`)}
                                </span>
                            )}
                        </div>

                        {a.body && (
                            <p className="mt-0.5 whitespace-pre-wrap text-sm text-neutral-600 dark:text-neutral-400">{a.body}</p>
                        )}

                        <p className="mt-0.5 flex items-center gap-1 text-xs text-neutral-400 dark:text-neutral-500">
                            <Clock className="h-3 w-3" aria-hidden="true" />
                            <time dateTime={a.occurred_at} title={new Date(a.occurred_at).toLocaleString()}>
                                {relative(a.occurred_at)}
                            </time>
                            {a.user?.name ? <>· {a.user.name}</> : <>· {t('leads.by_system')}</>}
                        </p>
                    </li>
                );
            })}
        </ol>
    );
}
