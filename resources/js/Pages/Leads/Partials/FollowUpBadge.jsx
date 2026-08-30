import { Clock, AlarmClock } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/** Whole days between now and `date` — negative once it's in the past. */
export function daysUntil(date) {
    const then = new Date(date);
    const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());

    return Math.round((startOfDay(then) - startOfDay(new Date())) / 86400000);
}

export function followUpState(date) {
    if (!date) return null;
    const days = daysUntil(date);

    if (days < 0) return { tone: 'overdue', days: Math.abs(days) };
    if (days === 0) return { tone: 'today', days: 0 };

    return { tone: 'upcoming', days };
}

const TONES = {
    overdue: 'bg-coral-50 text-coral-800 border-coral-200 dark:bg-coral-950/40 dark:text-coral-300 dark:border-coral-800',
    today: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-700',
    upcoming: 'bg-neutral-50 text-neutral-600 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:border-neutral-700',
};

/** The lead's next follow-up, or nothing at all when none is scheduled. */
export default function FollowUpBadge({ date, className = '' }) {
    const { t } = useTranslation();
    const state = followUpState(date);
    if (!state) return null;

    const label = {
        overdue: t('leads.follow_up_overdue', { count: state.days }),
        today: t('leads.follow_up_today'),
        upcoming: t('leads.follow_up_in', { count: state.days }),
    }[state.tone];

    const Icon = state.tone === 'overdue' ? AlarmClock : Clock;

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-soft border px-1.5 py-0.5 text-xs font-medium ${TONES[state.tone]} ${className}`}
            title={new Date(date).toLocaleString()}
        >
            <Icon className="h-3 w-3" aria-hidden="true" />
            {label}
        </span>
    );
}
