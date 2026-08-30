import { Head, useForm, usePage, router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Eye, EyeOff, CheckCircle, AlertCircle, Clock, Trash2, RefreshCw,
    PlugZap, Copy, Check, ShoppingBag, Store,
} from 'lucide-react';

const PLATFORM_META = {
    shopify: {
        color: '#95BF47',
        Icon: ShoppingBag,
        domainLabel: 'Shop domain',
        domainPlaceholder: 'your-store.myshopify.com',
        guide: [
            'In your Shopify admin, go to Settings → Apps and sales channels → Develop apps.',
            'Create an app, then under "API credentials" install it and reveal the Admin API access token.',
            'Grant read access to Orders, Customers and Checkouts (read_orders, read_customers).',
            'Paste the access token and your *.myshopify.com domain below.',
        ],
    },
    woocommerce: {
        color: '#96588A',
        Icon: Store,
        domainLabel: 'Store URL',
        domainPlaceholder: 'https://yourstore.com',
        guide: [
            'In WordPress admin, go to WooCommerce → Settings → Advanced → REST API.',
            'Add a key with Read/Write permissions.',
            'Copy the Consumer Key and Consumer Secret.',
            'Paste them with your store URL below.',
        ],
    },
    bigcommerce: {
        color: '#34313F',
        Icon: Store,
        domainLabel: 'Store Hash',
        domainPlaceholder: 'abcde1234',
        guide: [
            'In your BigCommerce control panel, go to Settings → API → Store-level API accounts.',
            'Create an API account with read scopes for Orders, Customers, Carts, and Webhooks (modify).',
            'Copy the Access Token, and the Store Hash (the code in the API Path: api.bigcommerce.com/stores/{hash}/).',
            'Paste the Store Hash and Access Token below.',
        ],
    },
};

function StatusBadge({ store }) {
    const { t } = useTranslation();
    if (store.status === 'connected') {
        return (
            <span className="flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                <CheckCircle className="h-3.5 w-3.5" /> {t('ecommerce.connected') || 'Connected'}
            </span>
        );
    }
    if (store.status === 'error') {
        return (
            <span className="flex items-center gap-1 text-xs text-red-600 dark:text-red-400">
                <AlertCircle className="h-3.5 w-3.5" /> {t('ecommerce.error') || 'Error'}
            </span>
        );
    }
    return (
        <span className="flex items-center gap-1 text-xs text-neutral-400">
            <Clock className="h-3.5 w-3.5" /> {t('ecommerce.pending') || 'Pending'}
        </span>
    );
}

function CopyField({ value }) {
    const [copied, setCopied] = useState(false);
    const copy = () => {
        navigator.clipboard?.writeText(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };
    return (
        <div className="flex items-center gap-2 rounded-lg bg-neutral-50 dark:bg-neutral-800 px-3 py-2">
            <code className="flex-1 truncate text-[11px] text-neutral-500 dark:text-neutral-400">{value}</code>
            <button type="button" onClick={copy} className="text-neutral-400 hover:text-neutral-600 shrink-0">
                {copied ? <Check className="h-3.5 w-3.5 text-green-500" /> : <Copy className="h-3.5 w-3.5" />}
            </button>
        </div>
    );
}

function ConnectedStoreCard({ store }) {
    const { t } = useTranslation();
    const meta = PLATFORM_META[store.platform] ?? {};
    const Icon = meta.Icon ?? Store;
    const [busy, setBusy] = useState(null);

    const act = (name, verb) => {
        setBusy(verb);
        router.post(route(name, store.id), {}, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    const disconnect = () => {
        if (!confirm(t('ecommerce.confirm_disconnect') || 'Disconnect this store?')) return;
        router.delete(route('client.ecommerce.stores.destroy', store.id), { preserveScroll: true });
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-3">
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2.5 min-w-0">
                    <div className="flex items-center justify-center w-9 h-9 rounded-xl shrink-0" style={{ backgroundColor: meta.color ?? '#6b7280' }}>
                        <Icon className="h-5 w-5 text-white" />
                    </div>
                    <div className="min-w-0">
                        <h3 className="font-semibold text-sm text-neutral-900 dark:text-neutral-100 truncate">{store.name}</h3>
                        <p className="text-xs text-neutral-400 truncate">{store.domain}</p>
                    </div>
                </div>
                <StatusBadge store={store} />
            </div>

            {store.last_test_message && (
                <p className={`text-xs ${store.last_test_status === 'ok' ? 'text-neutral-400' : 'text-red-500'}`}>
                    {store.last_test_message}
                </p>
            )}

            <div className="text-xs text-neutral-400 space-y-1">
                <div>{t('ecommerce.customers_synced') || 'Customers synced'}: {store.customers_synced_at ? new Date(store.customers_synced_at).toLocaleString() : '—'}</div>
                <div>{t('ecommerce.orders_synced') || 'Orders synced'}: {store.orders_synced_at ? new Date(store.orders_synced_at).toLocaleString() : '—'}</div>
                <div>{t('ecommerce.products_synced') || 'Products synced'}: {store.products_synced_at ? new Date(store.products_synced_at).toLocaleString() : '—'}</div>
            </div>

            <div>
                <p className="text-[11px] font-medium text-neutral-500 dark:text-neutral-400 mb-1">{t('ecommerce.webhook_url') || 'Webhook URL'}</p>
                <CopyField value={store.webhook_url} />
            </div>

            <div className="flex gap-2 pt-1">
                <button
                    type="button"
                    onClick={() => act('client.ecommerce.stores.test', 'test')}
                    disabled={busy !== null}
                    className="flex-1 rounded-lg border border-neutral-200 dark:border-neutral-700 py-2 text-sm font-medium text-neutral-600 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 disabled:opacity-60 transition"
                >
                    {busy === 'test' ? '…' : (t('ecommerce.test') || 'Test')}
                </button>
                <button
                    type="button"
                    onClick={() => act('client.ecommerce.stores.sync', 'sync')}
                    disabled={busy !== null}
                    className="flex items-center justify-center gap-1.5 flex-1 rounded-lg border border-neutral-200 dark:border-neutral-700 py-2 text-sm font-medium text-neutral-600 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 disabled:opacity-60 transition"
                >
                    <RefreshCw className={`h-3.5 w-3.5 ${busy === 'sync' ? 'animate-spin' : ''}`} />
                    {t('ecommerce.sync') || 'Sync'}
                </button>
                <button
                    type="button"
                    onClick={disconnect}
                    className="rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-2 text-neutral-500 hover:text-red-500 hover:border-red-300 transition"
                >
                    <Trash2 className="h-4 w-4" />
                </button>
            </div>
        </div>
    );
}

function OAuthConnect({ platform, meta, oauthAvailable }) {
    const { t } = useTranslation();
    const [domain, setDomain] = useState('');

    if (platform === 'bigcommerce') {
        if (!oauthAvailable) return null;
        return (
            <div className="rounded-lg border border-brand-200 dark:border-brand-900/40 bg-brand-50/50 dark:bg-brand-900/10 p-3 text-xs text-neutral-600 dark:text-neutral-300 space-y-1">
                <p className="font-medium text-neutral-800 dark:text-neutral-200">{t('ecommerce.oauth_one_click') || 'One-click install'}</p>
                <p>{t('ecommerce.bc_install_hint') || 'Install the app from your BigCommerce control panel (Apps → My Apps). You’ll be redirected back here automatically once it’s authorized.'}</p>
            </div>
        );
    }

    if (!oauthAvailable) return null;

    const start = () => {
        const param = platform === 'shopify' ? 'shop' : 'store_url';
        if (!domain.trim()) return;
        window.location.href = route('client.ecommerce.oauth.connect', { platform }) + `?${param}=${encodeURIComponent(domain.trim())}`;
    };

    return (
        <div className="space-y-2">
            <input
                value={domain}
                onChange={e => setDomain(e.target.value)}
                placeholder={meta.domainPlaceholder}
                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
            />
            <button
                type="button"
                onClick={start}
                className="w-full rounded-lg py-2 text-sm font-medium text-white transition"
                style={{ backgroundColor: meta.color ?? '#4f46e5' }}
            >
                {(t('ecommerce.connect_with') || 'Connect with')} {platform === 'shopify' ? 'Shopify' : 'WooCommerce'}
            </button>
        </div>
    );
}

function ConnectForm({ platforms, oauth = {} }) {
    const { t } = useTranslation();
    const [showSecrets, setShowSecrets] = useState({});
    const [platform, setPlatform] = useState(platforms[0]?.platform ?? 'shopify');

    const current = platforms.find(p => p.platform === platform) ?? platforms[0];
    const meta = PLATFORM_META[platform] ?? {};
    const oauthAvailable = !!oauth[platform];

    const { data, setData, post, processing, errors, reset } = useForm({
        platform,
        name: '',
        domain: '',
        credentials: {},
    });

    const changePlatform = (p) => {
        setPlatform(p);
        setData(d => ({ ...d, platform: p, credentials: {} }));
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('client.ecommerce.stores.store'), {
            preserveScroll: true,
            onSuccess: () => reset('name', 'domain', 'credentials'),
        });
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-4">
            <div className="flex items-center gap-2">
                <PlugZap className="h-5 w-5 text-brand-600" />
                <h3 className="font-semibold text-sm text-neutral-900 dark:text-neutral-100">{t('ecommerce.connect_store') || 'Connect a store'}</h3>
            </div>

            <div className="flex gap-2">
                {platforms.map(p => {
                    const PIcon = (PLATFORM_META[p.platform] ?? {}).Icon ?? Store;
                    const active = p.platform === platform;
                    return (
                        <button
                            key={p.platform}
                            type="button"
                            onClick={() => changePlatform(p.platform)}
                            className={`flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition ${active ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-900/20' : 'border-neutral-200 dark:border-neutral-700 text-neutral-500'}`}
                        >
                            <PIcon className="h-4 w-4" /> {p.label}
                        </button>
                    );
                })}
            </div>

            <OAuthConnect platform={platform} meta={meta} oauthAvailable={oauthAvailable} />

            {oauthAvailable && (
                <div className="flex items-center gap-2 text-xs text-neutral-400">
                    <span className="flex-1 border-t border-neutral-200 dark:border-neutral-700" />
                    {t('ecommerce.or_manual') || 'or enter API credentials'}
                    <span className="flex-1 border-t border-neutral-200 dark:border-neutral-700" />
                </div>
            )}

            <form onSubmit={submit} className="space-y-3">
                <div>
                    <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('ecommerce.store_name') || 'Store name'}</label>
                    <input
                        type="text"
                        value={data.name}
                        onChange={e => setData('name', e.target.value)}
                        placeholder={current?.label}
                        className="mt-1 w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                    />
                </div>

                <div>
                    <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{meta.domainLabel || 'Domain'} *</label>
                    <input
                        type="text"
                        value={data.domain}
                        onChange={e => setData('domain', e.target.value)}
                        placeholder={meta.domainPlaceholder}
                        className="mt-1 w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                    />
                    {errors.domain && <p className="mt-1 text-xs text-red-500">{errors.domain}</p>}
                </div>

                {(current?.fields ?? []).map(field => (
                    <div key={field.key}>
                        <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">
                            {field.label}{field.required && ' *'}
                        </label>
                        <div className="relative mt-1">
                            <input
                                type={field.type === 'password' && !showSecrets[field.key] ? 'password' : 'text'}
                                value={data.credentials[field.key] ?? ''}
                                onChange={e => setData('credentials', { ...data.credentials, [field.key]: e.target.value })}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 pr-10 text-sm"
                            />
                            {field.type === 'password' && (
                                <button
                                    type="button"
                                    onClick={() => setShowSecrets(s => ({ ...s, [field.key]: !s[field.key] }))}
                                    className="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-600"
                                >
                                    {showSecrets[field.key] ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                </button>
                            )}
                        </div>
                        {errors[`credentials.${field.key}`] && (
                            <p className="mt-1 text-xs text-red-500">{errors[`credentials.${field.key}`]}</p>
                        )}
                    </div>
                ))}

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition"
                >
                    {processing ? (t('ecommerce.connecting') || 'Connecting…') : (t('ecommerce.connect') || 'Connect & test')}
                </button>
            </form>

            {meta.guide && (
                <div className="rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 px-3 py-2.5">
                    <ol className="space-y-1">
                        {meta.guide.map((step, i) => (
                            <li key={i} className="flex gap-2 text-xs text-blue-700 dark:text-blue-400">
                                <span className="shrink-0 font-medium text-blue-500">{i + 1}.</span>
                                <span>{step}</span>
                            </li>
                        ))}
                    </ol>
                </div>
            )}
        </div>
    );
}

export default function EcommerceStoresIndex({ stores = [], platforms = [], oauth = {} }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    return (
        <ClientLayout title={t('ecommerce.title') || 'E-Commerce'}>
            <Head title={t('ecommerce.title') || 'E-Commerce'} />
            <div className="space-y-5">
                <div>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('ecommerce.title') || 'E-Commerce Stores'}</h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('ecommerce.subtitle') || 'Connect Shopify or WooCommerce to sync customers, track orders, and trigger automations on commerce events.'}
                    </p>
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>
                )}
                {flash.error && (
                    <div className="rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2 text-sm">{flash.error}</div>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="lg:col-span-2 grid gap-4 sm:grid-cols-2 content-start">
                        {stores.length === 0 && (
                            <p className="text-sm text-neutral-400 sm:col-span-2">{t('ecommerce.no_stores') || 'No stores connected yet.'}</p>
                        )}
                        {stores.map(s => <ConnectedStoreCard key={s.id} store={s} />)}
                    </div>
                    {(
                        <div>
                            <ConnectForm platforms={platforms} oauth={oauth} />
                        </div>
                    )}
                </div>
            </div>
        </ClientLayout>
    );
}
