import { Button, Input, Toggle } from '@/Components/ui';
import PlanLimits from './PlanLimits';
import PlanFeatures from './PlanFeatures';
import { useTranslation } from 'react-i18next';

export default function PlanForm({
    data,
    setData,
    errors = {},
    processing,
    onSubmit,
    onCancel,
    isEdit,
    currencies = [],
}) {
    const { t } = useTranslation();
    const yearlyEnabled = data.yearly_price_cents != null && data.yearly_price_cents !== '';

    const toggleYearly = (on) => {
        if (!on) setData('yearly_price_cents', null);
        else setData('yearly_price_cents', data.yearly_price_cents ?? 0);
    };

    return (
        <form onSubmit={onSubmit} className="space-y-6">
            {/* General Information */}
            <section>
                <h4 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 mb-3">
                    {t('admin.general_information')}
                </h4>
                <div className="space-y-4">
                    <Input
                        label={t('admin.plan_name')}
                        value={data.name ?? ''}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        required
                    />
                    <Input
                        label={t('admin.slug')}
                        value={data.slug ?? ''}
                        onChange={(e) => setData('slug', e.target.value)}
                        hint={t('admin.slug_auto_hint')}
                        error={errors.slug}
                    />
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('admin.col_description')}
                        </label>
                        <textarea
                            value={data.description ?? ''}
                            onChange={(e) => setData('description', e.target.value)}
                            rows={2}
                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-neutral-900 dark:text-neutral-100 placeholder:text-neutral-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                            placeholder={t('admin.short_description_placeholder')}
                        />
                        {errors.description && (
                            <p className="mt-1.5 text-sm text-red-500 dark:text-red-400">{errors.description}</p>
                        )}
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('admin.currency_label')}
                        </label>
                        <select
                            value={data.currency_code ?? ''}
                            onChange={(e) => setData('currency_code', e.target.value)}
                            required
                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-neutral-900 dark:text-neutral-100 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        >
                            {currencies.length === 0 && (
                                <option value={data.currency_code ?? ''}>{data.currency_code ?? '—'}</option>
                            )}
                            {/* Keep the plan's saved code selectable even if it is now disabled. */}
                            {data.currency_code && !currencies.some((c) => c.code === data.currency_code) && (
                                <option value={data.currency_code}>{data.currency_code}</option>
                            )}
                            {currencies.map((c) => (
                                <option key={c.code} value={c.code}>
                                    {c.symbol ? `${c.code} — ${c.symbol}` : c.code}
                                </option>
                            ))}
                        </select>
                        {errors.currency_code && (
                            <p className="mt-1.5 text-sm text-red-500 dark:text-red-400">{errors.currency_code}</p>
                        )}
                    </div>
                </div>
            </section>

            {/* Pricing */}
            <section>
                <h4 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 mb-3">{t('admin.pricing')}</h4>
                <div className="space-y-4">
                    <Input
                        type="number"
                        min={0}
                        label={t('admin.monthly_price_cents')}
                        value={data.monthly_price_cents ?? ''}
                        onChange={(e) =>
                            setData('monthly_price_cents', e.target.value ? parseInt(e.target.value, 10) : null)
                        }
                        error={errors.monthly_price_cents}
                        required
                    />
                    <div className="flex items-center justify-between">
                        <span className="text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('admin.enable_yearly_pricing')}
                        </span>
                        <Toggle
                            checked={yearlyEnabled}
                            onChange={toggleYearly}
                        />
                    </div>
                    {yearlyEnabled && (
                        <Input
                            type="number"
                            min={0}
                            label={t('admin.yearly_price_cents')}
                            value={data.yearly_price_cents ?? ''}
                            onChange={(e) =>
                                setData(
                                    'yearly_price_cents',
                                    e.target.value ? parseInt(e.target.value, 10) : null
                                )
                            }
                        />
                    )}
                    <Input
                        type="number"
                        min={0}
                        max={365}
                        label={t('admin.trial_days')}
                        value={data.trial_days ?? 0}
                        onChange={(e) => setData('trial_days', parseInt(e.target.value, 10) || 0)}
                    />
                </div>
            </section>

            {/* Stripe Integration */}
            <section>
                <h4 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 mb-3">
                    {t('admin.stripe_integration')}
                </h4>
                <div className="bg-gray-50 dark:bg-neutral-800/50 border border-neutral-200 dark:border-neutral-700 rounded-lg p-4 space-y-4">
                    <p className="text-xs text-neutral-500 dark:text-neutral-400">
                        {t('admin.stripe_ids_hint')}
                    </p>
                    <Input
                        label={t('admin.price_id_monthly')}
                        value={data.stripe_monthly_id ?? ''}
                        onChange={(e) => setData('stripe_monthly_id', e.target.value)}
                        placeholder={t('admin.price_id_placeholder')}
                    />
                    <Input
                        label={t('admin.price_id_yearly')}
                        value={data.stripe_yearly_id ?? ''}
                        onChange={(e) => setData('stripe_yearly_id', e.target.value)}
                        placeholder={t('admin.price_id_placeholder')}
                    />
                </div>
            </section>

            {/* Plan Limits */}
            <section>
                <h4 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 mb-3">{t('admin.plan_limits')}</h4>
                <PlanLimits limits={data.limits} onChange={(limits) => setData('limits', limits)} />
            </section>

            {/* Feature List */}
            <section>
                <h4 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 mb-3">{t('admin.feature_list')}</h4>
                <PlanFeatures features={data.features} onChange={(features) => setData('features', features)} />
            </section>

            {/* Status toggles */}
            <section>
                <h4 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 mb-3">{t('admin.status')}</h4>
                <div className="flex flex-wrap gap-6">
                    <Toggle
                        label={t('common.enabled')}
                        checked={data.enabled ?? true}
                        onChange={(v) => setData('enabled', v)}
                    />
                    <Toggle
                        label={t('admin.popular')}
                        checked={data.popular ?? false}
                        onChange={(v) => setData('popular', v)}
                    />
                    <Toggle
                        label={t('admin.featured')}
                        checked={data.featured ?? false}
                        onChange={(v) => setData('featured', v)}
                    />
                </div>
            </section>

            <div className="flex justify-end gap-3 pt-2 border-t border-neutral-200 dark:border-neutral-700">
                <Button type="button" variant="outline" onClick={onCancel}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" disabled={processing} className="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg shadow-sm">
                    {isEdit ? t('common.save') : t('common.create')}
                </Button>
            </div>
        </form>
    );
}
