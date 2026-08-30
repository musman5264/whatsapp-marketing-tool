import { Head, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import Pagination from '@/Components/ui/Pagination';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Search, Package, AlertTriangle, XOctagon } from 'lucide-react';

function StatCard({ label, value, tone = 'neutral', Icon }) {
    const tones = {
        neutral: 'text-neutral-900 dark:text-neutral-100',
        amber: 'text-amber-600 dark:text-amber-400',
        red: 'text-red-600 dark:text-red-400',
    };
    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 flex items-center gap-3">
            {Icon && <Icon className={`h-5 w-5 ${tones[tone]}`} />}
            <div>
                <p className="text-xs text-neutral-500 dark:text-neutral-400">{label}</p>
                <p className={`text-xl font-semibold ${tones[tone]}`}>{value}</p>
            </div>
        </div>
    );
}

function stockBadge(qty, threshold, t) {
    if (qty === null || qty === undefined) return <span className="text-neutral-400 text-xs">—</span>;
    if (qty <= 0) return <span className="px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">{t('ecommerce.out_of_stock')}</span>;
    if (qty <= threshold) return <span className="px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{t('ecommerce.stock_n_low', { count: qty })}</span>;
    return <span className="text-sm text-neutral-700 dark:text-neutral-300">{qty}</span>;
}

export default function ProductsIndex({ products, filters = {}, stores = [], stats = {}, lowStockThreshold = 5 }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const [search, setSearch] = useState(filters.search ?? '');

    const apply = (next) => {
        router.get(route('client.ecommerce.products.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    };

    return (
        <ClientLayout title={t('ecommerce.products') || 'Products'}>
            <Head title={t('ecommerce.products') || 'Products'} />
            <div className="space-y-5">
                <div>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('ecommerce.products') || 'Products & Inventory'}</h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{t('ecommerce.products_sub') || 'Synced products and live stock levels from your connected stores.'}</p>
                </div>

                {flash.success && <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>}

                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard label={t('ecommerce.total_products') || 'Products'} value={stats.total ?? 0} Icon={Package} />
                    <StatCard label={t('ecommerce.low_stock') || 'Low stock'} value={stats.low_stock ?? 0} tone="amber" Icon={AlertTriangle} />
                    <StatCard label={t('ecommerce.out_of_stock') || 'Out of stock'} value={stats.out_of_stock ?? 0} tone="red" Icon={XOctagon} />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <form onSubmit={e => { e.preventDefault(); apply({ search }); }} className="relative flex-1 min-w-[200px]">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-neutral-400" />
                        <input value={search} onChange={e => setSearch(e.target.value)} placeholder={t('ecommerce.search_products') || 'Search name or SKU…'}
                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 pl-9 pr-3 py-2 text-sm" />
                    </form>
                    <select value={filters.store_id ?? ''} onChange={e => apply({ store_id: e.target.value || undefined })}
                        className="rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm">
                        <option value="">{t('ecommerce.all_stores') || 'All stores'}</option>
                        {stores.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                    <label className="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-300 px-2">
                        <input type="checkbox" checked={!!filters.low_stock} onChange={e => apply({ low_stock: e.target.checked ? 1 : undefined })} className="rounded" />
                        {t('ecommerce.low_stock_only') || 'Low stock only'}
                    </label>
                </div>

                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 overflow-hidden">
                    <table className="w-full text-sm">
                        <thead className="bg-neutral-50 dark:bg-neutral-800/50 text-neutral-500 dark:text-neutral-400 text-xs uppercase">
                            <tr>
                                <th className="text-left font-medium px-4 py-2.5">{t('ecommerce.product') || 'Product'}</th>
                                <th className="text-left font-medium px-4 py-2.5">SKU</th>
                                <th className="text-right font-medium px-4 py-2.5">{t('ecommerce.price') || 'Price'}</th>
                                <th className="text-right font-medium px-4 py-2.5">{t('ecommerce.stock') || 'Stock'}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                            {products.data.length === 0 && (
                                <tr><td colSpan={4} className="px-4 py-8 text-center text-neutral-400">{t('ecommerce.no_products') || 'No products synced yet.'}</td></tr>
                            )}
                            {products.data.map(p => (
                                <tr key={p.id} className="hover:bg-neutral-50 dark:hover:bg-neutral-800/40">
                                    <td className="px-4 py-2.5">
                                        <div className="flex items-center gap-3">
                                            {p.image_url
                                                ? <img src={p.image_url} alt="" className="h-9 w-9 rounded-lg object-cover bg-neutral-100" />
                                                : <div className="h-9 w-9 rounded-lg bg-neutral-100 dark:bg-neutral-800 flex items-center justify-center"><Package className="h-4 w-4 text-neutral-400" /></div>}
                                            <span className="font-medium text-neutral-800 dark:text-neutral-200">{p.name}</span>
                                        </div>
                                    </td>
                                    <td className="px-4 py-2.5 text-neutral-500">{p.sku || '—'}</td>
                                    <td className="px-4 py-2.5 text-right text-neutral-700 dark:text-neutral-300">{p.price}</td>
                                    <td className="px-4 py-2.5 text-right">{stockBadge(p.inventory_quantity, lowStockThreshold, t)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <Pagination data={products} />
                </div>
            </div>
        </ClientLayout>
    );
}
