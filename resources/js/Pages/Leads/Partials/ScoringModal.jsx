import Modal from '@/Components/ui/Modal';
import Button from '@/Components/ui/Button';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

/**
 * Tunes the qualification formula. Weights are shown as sliders with a live total
 * so a tenant can see the shape of their formula; the backend rescales anyway, so
 * a total other than 100 is a hint, not an error.
 */
export default function ScoringModal({ show, scoring, onClose }) {
    const { t } = useTranslation();

    const { data, setData, put, processing, errors } = useForm({
        weights: { ...scoring.weights },
        thresholds: { ...scoring.thresholds },
    });

    const total = Object.values(data.weights).reduce((sum, n) => sum + Number(n || 0), 0);

    const submit = (e) => {
        e.preventDefault();
        put(route('client.leads.pipeline.scoring.update'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{t('leads.scoring_title')}</h2>
                <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{t('leads.scoring_subtitle')}</p>

                <div className="mt-5 space-y-4">
                    {scoring.rules.map((rule) => (
                        <div key={rule}>
                            <div className="flex items-center justify-between gap-2">
                                <label htmlFor={`weight-${rule}`} className="text-sm text-neutral-700 dark:text-neutral-300">
                                    {t(`leads.rule_${rule}`)}
                                </label>
                                <span className="text-xs font-medium tabular-nums text-neutral-500 dark:text-neutral-400">
                                    {data.weights[rule]}
                                </span>
                            </div>
                            <input
                                id={`weight-${rule}`}
                                type="range"
                                min="0"
                                max="100"
                                value={data.weights[rule]}
                                onChange={(e) => setData('weights', { ...data.weights, [rule]: Number(e.target.value) })}
                                className="mt-1 w-full accent-brand-500"
                            />
                        </div>
                    ))}
                </div>

                <p className="mt-3 text-xs text-neutral-500 dark:text-neutral-400">
                    {total === 100 ? t('leads.weights_total_ok') : t('leads.weights_total_rescaled', { total })}
                </p>
                {errors.weights && <p className="mt-1 text-sm text-coral-600 dark:text-coral-400">{errors.weights}</p>}

                <div className="mt-5 grid grid-cols-2 gap-4 border-t border-soft pt-4">
                    {['hot', 'warm'].map((band) => (
                        <div key={band}>
                            <label htmlFor={`threshold-${band}`} className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                {t(`leads.threshold_${band}`)}
                            </label>
                            <input
                                id={`threshold-${band}`}
                                type="number"
                                min="0"
                                max="100"
                                value={data.thresholds[band]}
                                onChange={(e) => setData('thresholds', { ...data.thresholds, [band]: Number(e.target.value) })}
                                className="w-full rounded-soft border-soft bg-white text-sm dark:bg-neutral-900 dark:text-neutral-100"
                            />
                            {errors[`thresholds.${band}`] && (
                                <p className="mt-1 text-xs text-coral-600 dark:text-coral-400">{errors[`thresholds.${band}`]}</p>
                            )}
                        </div>
                    ))}
                </div>
                <p className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">{t('leads.thresholds_hint')}</p>

                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" disabled={processing}>{t('leads.save_and_rescore')}</Button>
                </div>
            </form>
        </Modal>
    );
}
