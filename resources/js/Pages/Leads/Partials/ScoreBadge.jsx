import { Flame, Thermometer, Snowflake } from 'lucide-react';
import { useTranslation } from 'react-i18next';

const BANDS = {
    hot:  { variant: 'danger',  Icon: Flame,       labelKey: 'leads.band_hot' },
    warm: { variant: 'warning', Icon: Thermometer, labelKey: 'leads.band_warm' },
    cold: { variant: 'default', Icon: Snowflake,   labelKey: 'leads.band_cold' },
};

const BAND_CLASSES = {
    danger:  'bg-coral-50 text-coral-800 border-coral-200 dark:bg-coral-950/40 dark:text-coral-300 dark:border-coral-800',
    warning: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-700',
    default: 'bg-neutral-100 text-neutral-600 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:border-neutral-700',
};

/**
 * A lead's qualification band and score. An unscored lead renders nothing rather
 * than a misleading zero.
 */
export default function ScoreBadge({ band, score, showScore = true }) {
    const { t } = useTranslation();
    if (!band) return null;

    const { variant, Icon, labelKey } = BANDS[band] ?? BANDS.cold;

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-soft border px-1.5 py-0.5 text-xs font-medium ${BAND_CLASSES[variant]}`}
            title={t(labelKey)}
        >
            <Icon className="h-3 w-3" aria-hidden="true" />
            <span className="sr-only">{t(labelKey)}</span>
            {showScore && score !== null && score !== undefined && <span>{score}</span>}
        </span>
    );
}
