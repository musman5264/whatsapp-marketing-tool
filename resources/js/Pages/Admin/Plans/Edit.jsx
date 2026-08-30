import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, Input } from '@/Components/ui';
import { Head, Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function AdminPlansEdit({ plan, currencies = [] }) {
    const { t } = useTranslation();
    const { data, setData, put, processing } = useForm(plan ? { ...plan, enabled: plan.enabled ?? true, export_enabled: plan.export_enabled ?? false, custom_domain_enabled: plan.custom_domain_enabled ?? false, white_label_enabled: plan.white_label_enabled ?? false } : {});

    return (
        <AdminLayout title={plan ? t('admin.edit_plan_with_name', { name: plan.name }) : t('admin.new_plan')}>
            <Head title={t('admin.edit_plan_head')} />
            <div className="space-y-6">
                <Link href={route('admin.plans.index')} className="text-sm text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">{t('admin.back_to_plans')}</Link>
                <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('admin.edit_plan')}</h2>
                <form onSubmit={(e) => { e.preventDefault(); put(route('admin.plans.update', plan.id)); }}>
                    <Card>
                        <Card.Body className="space-y-4">
                            <Input label={t('common.name')} value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            <Input label={t('admin.slug')} value={data.slug} onChange={(e) => setData('slug', e.target.value)} />
                            <Input type="number" label={t('admin.monthly_price_cents')} value={data.monthly_price_cents ?? ''} onChange={(e) => setData('monthly_price_cents', e.target.value ? parseInt(e.target.value, 10) : null)} />
                            <Input type="number" label={t('admin.yearly_price_cents')} value={data.yearly_price_cents ?? ''} onChange={(e) => setData('yearly_price_cents', e.target.value ? parseInt(e.target.value, 10) : null)} />
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('admin.currency_code_label')}</label>
                                <select value={data.currency_code ?? ''} onChange={(e) => setData('currency_code', e.target.value)} className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-neutral-900 dark:text-neutral-100 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                                    {data.currency_code && !currencies.some((c) => c.code === data.currency_code) && (
                                        <option value={data.currency_code}>{data.currency_code}</option>
                                    )}
                                    {currencies.map((c) => (
                                        <option key={c.code} value={c.code}>{c.symbol ? `${c.code} — ${c.symbol}` : c.code}</option>
                                    ))}
                                </select>
                            </div>
                            <label className="flex items-center gap-2 text-neutral-900 dark:text-neutral-100">
                                <input type="checkbox" checked={data.enabled ?? false} onChange={(e) => setData('enabled', e.target.checked)} className="rounded border-neutral-300 dark:border-neutral-600 text-brand-500" />
                                <span className="text-sm">{t('common.enabled')}</span>
                            </label>
                            <Input type="number" label={t('admin.sort_order')} value={data.sort_order ?? 0} onChange={(e) => setData('sort_order', parseInt(e.target.value, 10) || 0)} />
                            <Input type="number" label={t('admin.ai_credits')} value={data.ai_credits ?? ''} onChange={(e) => setData('ai_credits', e.target.value ? parseInt(e.target.value, 10) : null)} />
                            <Input type="number" label={t('admin.websites_limit')} value={data.websites_limit ?? ''} onChange={(e) => setData('websites_limit', e.target.value ? parseInt(e.target.value, 10) : null)} />
                            <label className="flex items-center gap-2 text-neutral-900 dark:text-neutral-100"><input type="checkbox" checked={data.export_enabled ?? false} onChange={(e) => setData('export_enabled', e.target.checked)} className="rounded border-neutral-300 dark:border-neutral-600 text-brand-500" /><span className="text-sm">{t('admin.export_enabled')}</span></label>
                            <label className="flex items-center gap-2 text-neutral-900 dark:text-neutral-100"><input type="checkbox" checked={data.custom_domain_enabled ?? false} onChange={(e) => setData('custom_domain_enabled', e.target.checked)} className="rounded border-neutral-300 dark:border-neutral-600 text-brand-500" /><span className="text-sm">{t('admin.custom_domain_enabled')}</span></label>
                            <label className="flex items-center gap-2 text-neutral-900 dark:text-neutral-100"><input type="checkbox" checked={data.white_label_enabled ?? false} onChange={(e) => setData('white_label_enabled', e.target.checked)} className="rounded border-neutral-300 dark:border-neutral-600 text-brand-500" /><span className="text-sm">{t('admin.white_label_enabled')}</span></label>
                            <Button type="submit" variant="primary" disabled={processing}>{t('common.save')}</Button>
                        </Card.Body>
                    </Card>
                </form>
            </div>
        </AdminLayout>
    );
}
