import Modal from '@/Components/ui/Modal';
import Button from '@/Components/ui/Button';
import Input from '@/Components/ui/Input';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { STAGE_COLORS } from './StageColumn';

/**
 * Create or edit one stage. `stage` null means create; the sentinel object
 * {isNew: true} is what the board passes to open this in create mode.
 *
 * The parent keys this on the stage id, so switching stages remounts it with the
 * right values instead of syncing props into state.
 */
export default function StageModal({ stage, colors, canDelete, onClose }) {
    const { t } = useTranslation();
    const isNew = !stage?.id;

    const { data, setData, errors, processing, reset } = useForm({
        name: stage?.name ?? '',
        color: stage?.color ?? 'neutral',
        is_won: !!stage?.is_won,
        is_lost: !!stage?.is_lost,
    });

    const close = () => { reset(); onClose(); };

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: close };

        if (isNew) {
            router.post(route('client.leads.pipeline.stages.store'), data, opts);
        } else {
            router.put(route('client.leads.pipeline.stages.update', stage.id), data, opts);
        }
    };

    const destroy = () => {
        if (!confirm(t('leads.confirm_delete_stage'))) return;
        router.delete(route('client.leads.pipeline.stages.destroy', stage.id), { preserveScroll: true, onSuccess: close });
    };

    // A stage cannot be both the win and the loss condition.
    const setTerminal = (key, value) => {
        setData({ ...data, is_won: key === 'is_won' ? value : false, is_lost: key === 'is_lost' ? value : false });
    };

    return (
        <Modal show={!!stage} onClose={close} maxWidth="md">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                    {isNew ? t('leads.new_stage') : t('leads.edit_stage')}
                </h2>

                <div className="mt-4 space-y-4">
                    <Input
                        label={t('leads.stage_name')}
                        name="name"
                        value={data.name}
                        error={errors.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        maxLength={128}
                    />

                    <div>
                        <span className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('leads.stage_color')}
                        </span>
                        <div className="flex flex-wrap gap-2">
                            {colors.map((color) => (
                                <button
                                    key={color}
                                    type="button"
                                    onClick={() => setData('color', color)}
                                    aria-label={color}
                                    aria-pressed={data.color === color}
                                    className={[
                                        'h-7 w-7 rounded-full transition-transform',
                                        STAGE_COLORS[color] ?? STAGE_COLORS.neutral,
                                        data.color === color
                                            ? 'scale-110 ring-2 ring-brand-500 ring-offset-2 dark:ring-offset-neutral-900'
                                            : 'hover:scale-105',
                                    ].join(' ')}
                                />
                            ))}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <label className="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                            <input
                                type="checkbox"
                                checked={data.is_won}
                                onChange={(e) => setTerminal('is_won', e.target.checked)}
                                className="rounded border-soft text-brand-600 focus:ring-brand-500"
                            />
                            {t('leads.mark_as_won')}
                        </label>
                        <label className="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                            <input
                                type="checkbox"
                                checked={data.is_lost}
                                onChange={(e) => setTerminal('is_lost', e.target.checked)}
                                className="rounded border-soft text-brand-600 focus:ring-brand-500"
                            />
                            {t('leads.mark_as_lost')}
                        </label>
                        <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('leads.terminal_stage_hint')}</p>
                    </div>

                    {errors.stage && <p className="text-sm text-coral-600 dark:text-coral-400">{errors.stage}</p>}
                </div>

                <div className="mt-6 flex items-center justify-between gap-2">
                    {!isNew && canDelete ? (
                        <Button type="button" variant="danger" onClick={destroy}>{t('common.delete')}</Button>
                    ) : <span />}

                    <div className="flex gap-2">
                        <Button type="button" variant="secondary" onClick={close}>{t('common.cancel')}</Button>
                        <Button type="submit" disabled={processing}>{t('common.save')}</Button>
                    </div>
                </div>
            </form>
        </Modal>
    );
}
