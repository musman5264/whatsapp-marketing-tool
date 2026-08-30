import { typeLabel } from '@/lib/licenseLabels';

/**
 * Segmented chooser for the kind of code the buyer has (Envato purchase code vs
 * a plain license code). Renders nothing when only one type is offered.
 */
export default function LicenseTypeTabs({ types = [], value, onChange }) {
    if (!Array.isArray(types) || types.length <= 1) {
        return null;
    }

    return (
        <div className="mb-1">
            <p className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                What kind of code do you have?
            </p>
            <div className="inline-flex rounded-soft border border-neutral-200 bg-neutral-50 p-0.5 dark:border-neutral-700 dark:bg-neutral-800">
                {types.map((t) => (
                    <button
                        key={t}
                        type="button"
                        onClick={() => onChange(t)}
                        className={[
                            'rounded-soft px-3 py-1.5 text-xs font-medium transition',
                            value === t
                                ? 'bg-white text-brand-700 shadow-sm dark:bg-neutral-900 dark:text-brand-300'
                                : 'text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200',
                        ].join(' ')}
                    >
                        {typeLabel(t)}
                    </button>
                ))}
            </div>
        </div>
    );
}
