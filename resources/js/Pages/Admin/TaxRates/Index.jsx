import { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import AdminLayout from '@/Layouts/AdminLayout';
import { Percent, Plus, Pencil, Trash2 } from 'lucide-react';

function TaxRateForm({ taxRate = null, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, put, processing, errors } = useForm({
        name: taxRate?.name ?? '',
        country: taxRate?.country ?? '',
        region: taxRate?.region ?? '',
        percentage: taxRate?.percentage ?? '',
        inclusive: taxRate?.inclusive ?? false,
        enabled: taxRate?.enabled ?? true,
    });

    const submit = (e) => {
        e.preventDefault();
        if (taxRate) {
            put(route('admin.tax-rates.update', taxRate.id), { onSuccess: onClose });
        } else {
            post(route('admin.tax-rates.store'), { onSuccess: onClose });
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('common.name')}</label>
                    <input
                        type="text"
                        value={data.name}
                        onChange={e => setData('name', e.target.value)}
                        className="w-full border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white"
                        placeholder="VAT 20%"
                        required
                    />
                    {errors.name && <p className="text-coral-600 text-xs mt-1">{errors.name}</p>}
                </div>
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('admin.tax_country_code')}</label>
                    <input
                        type="text"
                        value={data.country}
                        onChange={e => setData('country', e.target.value.toUpperCase())}
                        className="w-full border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white font-mono"
                        placeholder="US"
                        maxLength={2}
                        required
                    />
                    {errors.country && <p className="text-coral-600 text-xs mt-1">{errors.country}</p>}
                </div>
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('admin.tax_region')}</label>
                    <input
                        type="text"
                        value={data.region}
                        onChange={e => setData('region', e.target.value)}
                        className="w-full border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white"
                        placeholder="CA"
                    />
                </div>
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('admin.tax_percentage')}</label>
                    <input
                        type="number"
                        value={data.percentage}
                        onChange={e => setData('percentage', e.target.value)}
                        className="w-full border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white"
                        min="0"
                        max="100"
                        step="0.01"
                        required
                    />
                    {errors.percentage && <p className="text-coral-600 text-xs mt-1">{errors.percentage}</p>}
                </div>
                <div className="flex items-center gap-4 pt-2">
                    <label className="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                        <input type="checkbox" checked={data.inclusive} onChange={e => setData('inclusive', e.target.checked)} className="rounded" />
                        {t('admin.tax_inclusive')}
                    </label>
                    <label className="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                        <input type="checkbox" checked={data.enabled} onChange={e => setData('enabled', e.target.checked)} className="rounded" />
                        {t('common.enabled')}
                    </label>
                </div>
            </div>
            <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={onClose} className="px-4 py-2 text-sm border border-neutral-300 dark:border-neutral-600 rounded-lg hover:bg-neutral-50 dark:hover:bg-neutral-700">
                    {t('common.cancel')}
                </button>
                <button type="submit" disabled={processing} className="px-4 py-2 text-sm bg-brand-500 text-white rounded-soft hover:bg-brand-600 shadow-soft disabled:opacity-50 transition-all duration-150">
                    {taxRate ? t('admin.tax_update') : t('admin.tax_create')}
                </button>
            </div>
        </form>
    );
}

export default function TaxRatesIndex({ taxRates }) {
    const { t } = useTranslation();
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);

    const handleDelete = (taxRate) => {
        if (! confirm(t('admin.tax_delete_confirm', { name: taxRate.name }))) return;
        router.delete(route('admin.tax-rates.destroy', taxRate.id));
    };

    return (
        <AdminLayout title={t('admin.tax_rates_title')}>
            <div className="max-w-5xl mx-auto space-y-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Percent className="h-6 w-6 text-brand-600 dark:text-brand-400" />
                        <div>
                            <h1 className="text-xl font-bold text-neutral-900 dark:text-white">{t('admin.tax_rates_title')}</h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('admin.tax_rates_subtitle')}</p>
                        </div>
                    </div>
                    <button onClick={() => setShowCreate(true)} className="flex items-center gap-2 px-4 py-2 bg-brand-500 text-white text-sm rounded-soft hover:bg-brand-600 shadow-soft transition-all duration-150">
                        <Plus className="h-4 w-4" /> {t('admin.tax_add')}
                    </button>
                </div>

                {showCreate && (
                    <div className="bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 p-6">
                        <h2 className="text-base font-semibold text-neutral-900 dark:text-white mb-4">{t('admin.tax_new')}</h2>
                        <TaxRateForm onClose={() => setShowCreate(false)} />
                    </div>
                )}

                <div className="bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 overflow-hidden">
                    <table className="w-full text-sm">
                        <thead className="bg-neutral-50 dark:bg-neutral-700">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('common.name')}</th>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('admin.tax_country')}</th>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('admin.tax_region_col')}</th>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('admin.tax_rate')}</th>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('admin.tax_type')}</th>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('admin.status')}</th>
                                <th className="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700">
                            {taxRates?.map(rate => (
                                <tr key={rate.id}>
                                    {editing?.id === rate.id ? (
                                        <td colSpan={7} className="px-4 py-4">
                                            <TaxRateForm taxRate={rate} onClose={() => setEditing(null)} />
                                        </td>
                                    ) : (
                                        <>
                                            <td className="px-4 py-3 font-medium text-neutral-900 dark:text-white">{rate.name}</td>
                                            <td className="px-4 py-3 font-mono text-neutral-700 dark:text-neutral-300">{rate.country}</td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{rate.region ?? '—'}</td>
                                            <td className="px-4 py-3 text-neutral-700 dark:text-neutral-300">{rate.percentage}%</td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{rate.inclusive ? t('admin.tax_type_inclusive') : t('admin.tax_type_exclusive')}</td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium ${rate.enabled ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-700 dark:text-neutral-400'}`}>
                                                    {rate.enabled ? t('common.active') : t('admin.disabled')}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2 justify-end">
                                                    <button onClick={() => setEditing(rate)} className="p-1 text-neutral-400 hover:text-brand-600 dark:hover:text-brand-400 transition">
                                                        <Pencil className="h-4 w-4" />
                                                    </button>
                                                    <button onClick={() => handleDelete(rate)} className="p-1 text-neutral-400 hover:text-coral-600">
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        </>
                                    )}
                                </tr>
                            ))}
                            {!taxRates?.length && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-8 text-center text-neutral-400 dark:text-neutral-500">
                                        {t('admin.tax_empty')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AdminLayout>
    );
}
