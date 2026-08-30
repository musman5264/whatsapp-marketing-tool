import { useEffect, useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, DatePicker, Modal, Pagination } from '@/Components/ui';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Download, Plus, Search, X } from 'lucide-react';
import { formatInTz, formatDateTz } from '@/Utils/datetime';

function CreateSubscriptionModal({ show, onClose, plans }) {
    const { t } = useTranslation();
    const [selectedUser, setSelectedUser] = useState(null);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [searching, setSearching] = useState(false);
    const abortRef = useRef(null);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        user_id: '',
        plan_id: plans[0]?.id ?? '',
        billing_cycle: 'month',
        status: 'active',
        starts_at: '',
        ends_at: '',
        trial_ends_at: '',
    });

    // Debounced user search
    useEffect(() => {
        if (selectedUser) return;
        const q = query.trim();

        const timer = setTimeout(() => {
            if (q.length < 2) { setResults([]); return; }
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;
            setSearching(true);
            fetch(`${route('admin.subscriptions.user-search')}?q=${encodeURIComponent(q)}`, { signal: controller.signal })
                .then(r => r.json())
                .then(d => setResults(d.users ?? []))
                .catch(() => {})
                .finally(() => setSearching(false));
        }, 200);

        return () => clearTimeout(timer);
    }, [query, selectedUser]);

    const pickUser = (user) => {
        setSelectedUser(user);
        setData('user_id', user.id);
        setResults([]);
        setQuery('');
    };

    const clearUser = () => {
        setSelectedUser(null);
        setData('user_id', '');
    };

    const close = () => {
        reset();
        clearErrors();
        setSelectedUser(null);
        setQuery('');
        setResults([]);
        onClose();
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('admin.subscriptions.store'), {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    return (
        <Modal show={show} onClose={close} maxWidth="xl">
            <Modal.Header title={t('admin.subscriptions_create')} onClose={close} />
            <form onSubmit={submit}>
                <Modal.Body className="space-y-4">
                    {/* User picker */}
                    <div>
                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('client.user')}</label>
                        {selectedUser ? (
                            <div className="flex items-center justify-between rounded-lg border border-neutral-300 dark:border-neutral-600 bg-neutral-50 dark:bg-neutral-800 px-3 py-2">
                                <span className="text-sm text-neutral-900 dark:text-neutral-100">{selectedUser.name} ({selectedUser.email})</span>
                                <button type="button" onClick={clearUser} className="p-0.5 text-neutral-400 hover:text-coral-600">
                                    <X className="h-4 w-4" />
                                </button>
                            </div>
                        ) : (
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                                <input
                                    type="text"
                                    value={query}
                                    onChange={e => setQuery(e.target.value)}
                                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-700 dark:text-white pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                    placeholder={t('admin.subscriptions_user_search_placeholder')}
                                />
                                {(results.length > 0 || searching) && (
                                    <div className="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 shadow-soft-lg">
                                        {searching && <div className="px-3 py-2 text-sm text-neutral-400">{t('common.loading')}</div>}
                                        {results.map(u => (
                                            <button
                                                key={u.id}
                                                type="button"
                                                onClick={() => pickUser(u)}
                                                className="flex w-full flex-col items-start px-3 py-2 text-left hover:bg-neutral-50 dark:hover:bg-neutral-700"
                                            >
                                                <span className="text-sm text-neutral-900 dark:text-neutral-100">{u.name}</span>
                                                <span className="text-xs text-neutral-500 dark:text-neutral-400">{u.email}</span>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                        {errors.user_id && <p className="text-coral-600 text-xs mt-1">{errors.user_id}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('client.plan')}</label>
                            <select
                                value={data.plan_id}
                                onChange={e => setData('plan_id', e.target.value)}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-700 dark:text-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                            >
                                {plans.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                            {errors.plan_id && <p className="text-coral-600 text-xs mt-1">{errors.plan_id}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('admin.subscriptions_billing_cycle')}</label>
                            <select
                                value={data.billing_cycle}
                                onChange={e => setData('billing_cycle', e.target.value)}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-700 dark:text-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                            >
                                <option value="month">{t('admin.subscriptions_cycle_monthly')}</option>
                                <option value="year">{t('admin.subscriptions_cycle_yearly')}</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('client.status')}</label>
                            <select
                                value={data.status}
                                onChange={e => setData('status', e.target.value)}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-700 dark:text-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                            >
                                <option value="active">{t('admin.status_success')}</option>
                                <option value="trialing">{t('admin.status_trialing')}</option>
                            </select>
                        </div>
                        {data.status === 'trialing' && (
                            <div>
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('admin.subscriptions_trial_ends_at')}</label>
                                <DatePicker
                                    value={data.trial_ends_at}
                                    onChange={v => setData('trial_ends_at', v)}
                                    error={!!errors.trial_ends_at}
                                />
                                {errors.trial_ends_at && <p className="text-coral-600 text-xs mt-1">{errors.trial_ends_at}</p>}
                            </div>
                        )}
                        <div>
                            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('admin.subscriptions_starts_at')}</label>
                            <DatePicker
                                value={data.starts_at}
                                onChange={v => setData('starts_at', v)}
                            />
                            <p className="text-xs text-neutral-400 mt-1">{t('admin.subscriptions_starts_at_hint')}</p>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('admin.subscriptions_ends_at')}</label>
                            <DatePicker
                                value={data.ends_at}
                                onChange={v => setData('ends_at', v)}
                            />
                            <p className="text-xs text-neutral-400 mt-1">{t('admin.subscriptions_ends_at_hint')}</p>
                        </div>
                    </div>
                </Modal.Body>
                <Modal.Footer>
                    <Button type="button" variant="outline" size="sm" onClick={close}>{t('common.cancel')}</Button>
                    <Button type="submit" size="sm" disabled={processing || !data.user_id}>{t('admin.subscriptions_create')}</Button>
                </Modal.Footer>
            </form>
        </Modal>
    );
}

export default function AdminSubscriptionsIndex({ subscriptions, filters = {}, plans = [] }) {
    const { t } = useTranslation();
    const { auth, timezone } = usePage().props;
    const adminTz = timezone || 'Asia/Dhaka';
    const canManage = (auth?.permissions ?? []).includes('manage_subscriptions');
    const [showCreate, setShowCreate] = useState(false);

    return (
        <AdminLayout title={t('admin.nav.subscriptions')}>
            <Head title={`${t('admin.nav.subscriptions')} · ${t('head.admin')}`} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('admin.nav.subscriptions')}</h2>
                    <div className="flex items-center gap-3">
                        {canManage && plans.length > 0 && (
                            <Button size="sm" onClick={() => setShowCreate(true)} className="inline-flex items-center gap-2">
                                <Plus className="h-4 w-4" /> {t('admin.subscriptions_create')}
                            </Button>
                        )}
                        <a
                            href={`${route('admin.subscriptions.export')}${filters.status ? '?status=' + filters.status : ''}${filters.gateway ? (filters.status ? '&' : '?') + 'gateway=' + filters.gateway : ''}`}
                            className="inline-flex items-center gap-2 rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-700 transition"
                        >
                            <Download className="h-4 w-4" /> {t('admin.subscriptions_export')}
                        </a>
                        <Link
                            href={route('admin.payments.index')}
                            className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300"
                        >
                            {t('admin.view_payments')}
                        </Link>
                    </div>
                </div>
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const f = e.target;
                        router.get(route('admin.subscriptions.index'), { status: f.status?.value, gateway: f.gateway?.value }, { preserveState: true });
                    }}
                >
                    <select
                        name="status"
                        className="rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                        defaultValue={filters.status}
                    >
                        <option value="">{t('admin.all_statuses')}</option>
                        <option value="active">{t('admin.status_success')}</option>
                        <option value="trialing">{t('admin.status_trialing')}</option>
                        <option value="canceled">{t('admin.status_canceled')}</option>
                        <option value="past_due">{t('admin.status_past_due')}</option>
                        <option value="ended">{t('admin.status_ended')}</option>
                    </select>
                    <select
                        name="gateway"
                        className="rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                        defaultValue={filters.gateway}
                    >
                        <option value="">{t('admin.all_gateways')}</option>
                        <option value="stripe">Stripe</option>
                        <option value="paypal">PayPal</option>
                        <option value="paddle">Paddle</option>
                        <option value="manual">{t('admin.subscriptions_gateway_manual')}</option>
                    </select>
                    <Button type="submit" variant="outline" size="sm">{t('common.filter')}</Button>
                </form>
                <Card>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-neutral-200 dark:border-neutral-700 text-left text-neutral-500 dark:text-neutral-400">
                                    <th className="pb-2 pr-4 font-medium">{t('client.user')}</th>
                                    <th className="pb-2 pr-4 font-medium">{t('client.plan')}</th>
                                    <th className="pb-2 pr-4 font-medium">{t('client.status')}</th>
                                    <th className="pb-2 pr-4 font-medium">{t('admin.col_gateway')}</th>
                                    <th className="pb-2 pr-4 font-medium">{t('admin.col_starts')}</th>
                                    <th className="pb-2 pr-4 font-medium">{t('admin.col_renews')}</th>
                                    <th className="pb-2 pr-4 font-medium">{t('admin.col_created')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {subscriptions.data?.map((s) => (
                                    <tr key={s.id} className="border-b border-neutral-100 dark:border-neutral-800">
                                        <td className="py-3 pr-4 text-neutral-900 dark:text-neutral-100">{s.user ? `${s.user.name} (${s.user.email})` : '—'}</td>
                                        <td className="py-3 pr-4 text-neutral-900 dark:text-neutral-100">{s.plan?.name ?? '—'}</td>
                                        <td className="py-3 pr-4 text-neutral-900 dark:text-neutral-100">{s.status}</td>
                                        <td className="py-3 pr-4 text-neutral-900 dark:text-neutral-100">{s.gateway}</td>
                                        <td className="py-3 pr-4 text-neutral-500 dark:text-neutral-400">{s.starts_at ? formatDateTz(s.starts_at, adminTz) : '—'}</td>
                                        <td className="py-3 pr-4 text-neutral-500 dark:text-neutral-400">{s.renews_at ? formatDateTz(s.renews_at, adminTz) : '—'}</td>
                                        <td className="py-3 pr-4 text-neutral-500 dark:text-neutral-400">{s.created_at ? formatInTz(s.created_at, adminTz) : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {subscriptions.data?.length === 0 && (
                        <div className="py-8 text-center text-neutral-500 dark:text-neutral-400">{t('admin.no_subscriptions')}</div>
                    )}
                    <Pagination data={subscriptions} />
                </Card>
            </div>

            {canManage && (
                <CreateSubscriptionModal show={showCreate} onClose={() => setShowCreate(false)} plans={plans} />
            )}
        </AdminLayout>
    );
}
