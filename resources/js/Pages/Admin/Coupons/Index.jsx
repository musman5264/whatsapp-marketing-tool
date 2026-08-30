import { useState } from 'react';
import { useForm, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DatePicker } from '@/Components/ui';
import { Tag, Plus, Pencil, Trash2, Check, X } from 'lucide-react';
import { formatDateTz } from '@/Utils/datetime';
import { useTranslation } from 'react-i18next';

function CouponForm({ coupon = null, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, put, processing, errors, reset } = useForm({
        code: coupon?.code ?? '',
        kind: coupon?.kind ?? 'percent',
        amount: coupon?.amount ?? '',
        duration: coupon?.duration ?? 'once',
        duration_in_months: coupon?.duration_in_months ?? '',
        max_redemptions: coupon?.max_redemptions ?? '',
        enabled: coupon?.enabled ?? true,
        expires_at: coupon?.expires_at ? coupon.expires_at.slice(0, 10) : '',
    });

    const submit = (e) => {
        e.preventDefault();
        if (coupon) {
            put(route('admin.coupons.update', coupon.id), { onSuccess: onClose });
        } else {
            post(route('admin.coupons.store'), { onSuccess: () => { reset(); onClose(); } });
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('coupons.code_label')}</label>
                    <input
                        type="text"
                        value={data.code}
                        onChange={e => setData('code', e.target.value.toUpperCase())}
                        className="w-full border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white font-mono"
                        placeholder={t('coupons.code_placeholder')}
                        required
                    />
                    {errors.code && <p className="text-coral-600 text-xs mt-1">{errors.code}</p>}
                </div>
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('coupons.type_label')}</label>
                    <select value={data.kind} onChange={e => setData('kind', e.target.value)} className="w-full border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white">
                        <option value="percent">{t('coupons.type_percent')}</option>
                        <option value="fixed">{t('coupons.type_fixed')}</option>
                    </select>
                </div>
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                        {t('coupons.amount_label')} {data.kind === 'percent' ? t('coupons.amount_percent') : t('coupons.amount_cents')}
                    </label>
                    <input
                        type="number"
                        value={data.amount}
                        onChange={e => setData('amount', e.target.value)}
                        className="w-full border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white"
                        min="0"
                        step={data.kind === 'percent' ? '0.01' : '1'}
                        required
                    />
                    {errors.amount && <p className="text-coral-600 text-xs mt-1">{errors.amount}</p>}
                </div>
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('coupons.duration_label')}</label>
                    <select value={data.duration} onChange={e => setData('duration', e.target.value)} className="w-full border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white">
                        <option value="once">{t('coupons.duration_once')}</option>
                        <option value="repeating">{t('coupons.duration_repeating')}</option>
                        <option value="forever">{t('coupons.duration_forever')}</option>
                    </select>
                </div>
                {data.duration === 'repeating' && (
                    <div>
                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('coupons.months_label')}</label>
                        <input
                            type="number"
                            value={data.duration_in_months}
                            onChange={e => setData('duration_in_months', e.target.value)}
                            className="w-full border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white"
                            min="1"
                        />
                    </div>
                )}
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('coupons.max_redemptions')}</label>
                    <input
                        type="number"
                        value={data.max_redemptions}
                        onChange={e => setData('max_redemptions', e.target.value)}
                        className="w-full border border-neutral-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm dark:bg-neutral-700 dark:text-white"
                        placeholder={t('coupons.unlimited_placeholder')}
                        min="1"
                    />
                </div>
                <div>
                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('coupons.expires_at')}</label>
                    <DatePicker
                        value={data.expires_at}
                        onChange={v => setData('expires_at', v)}
                    />
                </div>
                <div className="flex items-center gap-2 pt-6">
                    <input
                        type="checkbox"
                        id="enabled"
                        checked={data.enabled}
                        onChange={e => setData('enabled', e.target.checked)}
                        className="rounded"
                    />
                    <label htmlFor="enabled" className="text-sm text-neutral-700 dark:text-neutral-300">{t('coupons.enabled_label')}</label>
                </div>
            </div>
            <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={onClose} className="px-4 py-2 text-sm border border-neutral-300 dark:border-neutral-600 rounded-lg hover:bg-neutral-50 dark:hover:bg-neutral-700">
                    {t('common.cancel')}
                </button>
                <button type="submit" disabled={processing} className="px-4 py-2 text-sm bg-brand-500 text-white rounded-soft hover:bg-brand-600 shadow-soft disabled:opacity-50 transition-all duration-150">
                    {coupon ? t('coupons.update_coupon') : t('coupons.create_coupon')}
                </button>
            </div>
        </form>
    );
}

export default function CouponsIndex({ coupons }) {
    const { t } = useTranslation();
    const adminTz = usePage().props.timezone || 'Asia/Dhaka';
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);

    const handleDelete = (coupon) => {
        if (! confirm(t('coupons.delete_confirm', { code: coupon.code }))) return;
        router.delete(route('admin.coupons.destroy', coupon.id));
    };

    return (
        <AdminLayout title={t('admin.nav.coupons')}>
            <div className="max-w-6xl mx-auto space-y-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Tag className="h-6 w-6 text-brand-600 dark:text-brand-400" />
                        <div>
                            <h1 className="text-xl font-bold text-neutral-900 dark:text-white">{t('coupons.title')}</h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('coupons.subtitle')}</p>
                        </div>
                    </div>
                    <button onClick={() => setShowCreate(true)} className="flex items-center gap-2 px-4 py-2 bg-brand-500 text-white text-sm rounded-soft hover:bg-brand-600 shadow-soft transition-all duration-150">
                        <Plus className="h-4 w-4" /> {t('coupons.new_coupon')}
                    </button>
                </div>

                {showCreate && (
                    <div className="bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 p-6">
                        <h2 className="text-base font-semibold text-neutral-900 dark:text-white mb-4">{t('coupons.create_coupon')}</h2>
                        <CouponForm onClose={() => setShowCreate(false)} />
                    </div>
                )}

                <div className="bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 overflow-hidden">
                    <table className="w-full text-sm">
                        <thead className="bg-neutral-50 dark:bg-neutral-700">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('coupons.col_code')}</th>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('coupons.col_discount')}</th>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('coupons.col_duration')}</th>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('coupons.col_redemptions')}</th>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('coupons.col_expires')}</th>
                                <th className="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{t('coupons.col_status')}</th>
                                <th className="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700">
                            {coupons.data?.map(coupon => (
                                <tr key={coupon.id}>
                                    {editing?.id === coupon.id ? (
                                        <td colSpan={7} className="px-4 py-4">
                                            <CouponForm coupon={coupon} onClose={() => setEditing(null)} />
                                        </td>
                                    ) : (
                                        <>
                                            <td className="px-4 py-3 font-mono font-semibold text-brand-600 dark:text-brand-400">{coupon.code}</td>
                                            <td className="px-4 py-3 text-neutral-700 dark:text-neutral-300">
                                                {coupon.kind === 'percent' ? `${coupon.amount}%` : `$${(coupon.amount / 100).toFixed(2)}`}
                                            </td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400 capitalize">
                                                {coupon.duration}{coupon.duration_in_months ? ` (${coupon.duration_in_months}mo)` : ''}
                                            </td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">
                                                {coupon.times_redeemed}{coupon.max_redemptions ? ` / ${coupon.max_redemptions}` : ''}
                                            </td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">
                                                {coupon.expires_at ? formatDateTz(coupon.expires_at, adminTz) : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {coupon.enabled
                                                    ? <span className="inline-flex items-center gap-1 text-green-600 dark:text-green-400"><Check className="h-3.5 w-3.5" /> {t('coupons.active')}</span>
                                                    : <span className="inline-flex items-center gap-1 text-neutral-400"><X className="h-3.5 w-3.5" /> {t('coupons.disabled')}</span>
                                                }
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2 justify-end">
                                                    <button onClick={() => setEditing(coupon)} className="p-1 text-neutral-400 hover:text-brand-600 dark:hover:text-brand-400 transition">
                                                        <Pencil className="h-4 w-4" />
                                                    </button>
                                                    <button onClick={() => handleDelete(coupon)} className="p-1 text-neutral-400 hover:text-coral-600">
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        </>
                                    )}
                                </tr>
                            ))}
                            {!coupons.data?.length && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-8 text-center text-neutral-400 dark:text-neutral-500">
                                        {t('coupons.no_coupons')}
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
