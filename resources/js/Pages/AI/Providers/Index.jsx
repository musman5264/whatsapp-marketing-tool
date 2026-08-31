import { Head, useForm, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Eye, EyeOff, CheckCircle, Bot, BookOpen, ChevronDown, ChevronUp } from 'lucide-react';

const SETUP_GUIDES = {
    openai: {
        steps: [
            'ai.guide_openai_step1',
            'ai.guide_openai_step2',
            'ai.guide_openai_step3',
            'ai.guide_openai_step4',
            'ai.guide_openai_step5',
        ],
        link: 'https://platform.openai.com/api-keys',
        linkLabelKey: 'ai.guide_openai_link',
    },
    anthropic: {
        steps: [
            'ai.guide_anthropic_step1',
            'ai.guide_anthropic_step2',
            'ai.guide_anthropic_step3',
            'ai.guide_anthropic_step4',
            'ai.guide_anthropic_step5',
        ],
        link: 'https://console.anthropic.com/keys',
        linkLabelKey: 'ai.guide_anthropic_link',
    },
    gemini: {
        steps: [
            'ai.guide_gemini_step1',
            'ai.guide_gemini_step2',
            'ai.guide_gemini_step3',
            'ai.guide_gemini_step4',
        ],
        link: 'https://aistudio.google.com/app/apikey',
        linkLabelKey: 'ai.guide_gemini_link',
    },
    cloudflare: {
        steps: [
            'ai.guide_cloudflare_step1',
            'ai.guide_cloudflare_step2',
            'ai.guide_cloudflare_step3',
            'ai.guide_cloudflare_step4',
        ],
        link: 'https://dash.cloudflare.com/?to=/:account/ai/workers-ai',
        linkLabelKey: 'ai.guide_cloudflare_link',
    },
    openrouter: {
        steps: [
            'ai.guide_openrouter_step1',
            'ai.guide_openrouter_step2',
            'ai.guide_openrouter_step3',
            'ai.guide_openrouter_step4',
        ],
        link: 'https://openrouter.ai/keys',
        linkLabelKey: 'ai.guide_openrouter_link',
    },
};

function SetupGuide({ providerKey }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const guide = SETUP_GUIDES[providerKey];
    if (!guide) return null;
    return (
        <div>
            <button
                type="button"
                onClick={() => setOpen(v => !v)}
                className="flex items-center gap-1.5 text-xs text-brand-600 dark:text-brand-400 hover:text-brand-700 font-medium transition w-full text-left"
            >
                <BookOpen className="h-3.5 w-3.5 shrink-0" />
                {t('ai.setup_guide')}
                {open ? <ChevronUp className="h-3 w-3 ml-auto" /> : <ChevronDown className="h-3 w-3 ml-auto" />}
            </button>
            {open && (
                <div className="mt-2 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 px-3 py-2.5 space-y-2">
                    <ol className="space-y-1">
                        {guide.steps.map((stepKey, i) => (
                            <li key={i} className="flex gap-2 text-xs text-blue-700 dark:text-blue-400">
                                <span className="shrink-0 font-medium text-blue-500">{i + 1}.</span>
                                <span>{t(stepKey)}</span>
                            </li>
                        ))}
                    </ol>
                    <a href={guide.link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">
                        {t(guide.linkLabelKey)} →
                    </a>
                </div>
            )}
        </div>
    );
}

const OpenAILogo = () => (
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073zM13.26 22.43a4.476 4.476 0 0 1-2.876-1.04l.141-.081 4.779-2.758a.795.795 0 0 0 .392-.681v-6.737l2.02 1.168a.071.071 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494zM3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085 4.783 2.759a.771.771 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646zM2.34 7.896a4.485 4.485 0 0 1 2.366-1.973V11.6a.766.766 0 0 0 .388.676l5.815 3.355-2.02 1.168a.076.076 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.896zm16.597 3.855l-5.843-3.372L15.115 7.2a.076.076 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.403-.667zm2.01-3.023l-.141-.085-4.774-2.782a.776.776 0 0 0-.785 0L9.409 9.23V6.897a.066.066 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08-4.778 2.758a.795.795 0 0 0-.393.681zm1.097-2.365l2.602-1.5 2.607 1.5v2.999l-2.597 1.5-2.607-1.5z" fill="currentColor"/>
    </svg>
);

const AnthropicLogo = () => (
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M13.827 3.52h3.603L24 20h-3.603l-6.57-16.48zm-3.654 0H6.57L0 20h3.603l1.357-3.415h6.85l1.356 3.415h3.604L10.173 3.52zm-4.17 10.222 2.444-6.317 2.444 6.317H5.997z" fill="currentColor"/>
    </svg>
);

const GeminiLogo = () => (
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M12 24A14.304 14.304 0 0 0 0 12 14.304 14.304 0 0 0 12 0a14.304 14.304 0 0 0 12 12 14.304 14.304 0 0 0-12 12z" fill="url(#gemini-gradient)"/>
        <defs>
            <linearGradient id="gemini-gradient" x1="0" y1="0" x2="24" y2="24" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stopColor="#4285F4"/>
                <stop offset="50%" stopColor="#9B72CB"/>
                <stop offset="100%" stopColor="#D96570"/>
            </linearGradient>
        </defs>
    </svg>
);

const CloudflareLogo = () => (
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M16.5 16.5c.2-.66.12-1.28-.2-1.73-.3-.42-.8-.66-1.4-.69l-8.4-.11a.22.22 0 0 1-.17-.09.22.22 0 0 1-.03-.2.3.3 0 0 1 .26-.2l8.48-.11c1-.05 2.1-.86 2.48-1.86l.48-1.27a.3.3 0 0 0 .01-.17 5.5 5.5 0 0 0-10.56-.57A2.48 2.48 0 0 0 3.3 12a3.5 3.5 0 0 0-.05.66c0 .1.01.22.02.33a.15.15 0 0 0 .15.13h15.4c.07 0 .14-.05.16-.13l.03-.1c.32-1.13.2-2.17-.35-2.9" fill="currentColor"/>
    </svg>
);

const OpenRouterLogo = () => (
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M3 12h4l3-3 4 6 3-3h4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
        <circle cx="19" cy="12" r="2" fill="currentColor"/>
        <circle cx="5" cy="12" r="2" fill="currentColor"/>
    </svg>
);

const PROVIDER_INFO = {
    openai:     { label: 'OpenAI',                Icon: OpenAILogo },
    anthropic:  { label: 'Anthropic',             Icon: AnthropicLogo },
    gemini:     { label: 'Gemini',                Icon: GeminiLogo },
    cloudflare: { label: 'Cloudflare Workers AI', Icon: CloudflareLogo },
    openrouter: { label: 'OpenRouter',            Icon: OpenRouterLogo },
};

function ProviderCard({ provider }) {
    const { t } = useTranslation();
    const [showKey, setShowKey] = useState(false);
    const info = PROVIDER_INFO[provider.provider] ?? {};
    const isCloudflare = provider.provider === 'cloudflare';
    const isOpenRouter = provider.provider === 'openrouter';
    const multiKey = provider.multi_key;
    const [chatModels, setChatModels] = useState(provider.chat_models ?? []);
    const [refreshing, setRefreshing] = useState(false);

    const { data, setData, put, processing } = useForm({
        api_key:             '',
        api_keys:            '',
        account_id:          provider.account_id ?? '',
        gateway_slug:        provider.gateway_slug ?? '',
        default_model_chat:  provider.default_model_chat || (provider.chat_models ?? [])[0] || '',
        default_model_embed: provider.default_model_embed || '',
        enabled:             provider.enabled,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('client.ai.providers.update', provider.provider), { preserveScroll: true });
    };

    const refreshModels = async () => {
        setRefreshing(true);
        try {
            const res = await fetch(route('client.ai.providers.openrouter.models'), {
                headers: { Accept: 'application/json' },
            });
            const json = await res.json();
            if (Array.isArray(json.models) && json.models.length) setChatModels(json.models);
        } catch {
            // keep the current list
        } finally {
            setRefreshing(false);
        }
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-4">
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    {info.Icon && <span className="text-neutral-800 dark:text-neutral-200"><info.Icon /></span>}
                    <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{info.label}</h3>
                </div>
                {provider.configured && <span className="flex items-center gap-1 text-xs text-green-600 dark:text-green-400"><CheckCircle className="h-3.5 w-3.5" /> {t('ai.configured')}</span>}
            </div>

            <form onSubmit={handleSubmit} className="space-y-3">
                {isCloudflare && (
                    <div>
                        <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('ai.cf_account_id')}</label>
                        <input
                            type="text"
                            value={data.account_id}
                            onChange={e => setData('account_id', e.target.value)}
                            placeholder="e.g. 0a1b2c3d4e5f…"
                            className="mt-1 w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm font-mono"
                        />
                    </div>
                )}

                {multiKey ? (
                    <div>
                        <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">
                            {isCloudflare ? t('ai.cf_api_tokens') : t('ai.or_api_keys')}
                            {provider.configured && provider.key_count > 0 && (
                                <span className="ml-1 text-neutral-400">({t('ai.cf_tokens_saved', { count: provider.key_count })})</span>
                            )}
                        </label>
                        <textarea
                            rows={3}
                            value={data.api_keys}
                            onChange={e => setData('api_keys', e.target.value)}
                            placeholder={provider.configured ? t('ai.api_key_encrypted_placeholder') : (isCloudflare ? 'one token per line' : 'one key per line')}
                            className="mt-1 w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm font-mono"
                        />
                        <p className="mt-1 text-xs text-neutral-400">{isCloudflare ? t('ai.cf_api_tokens_hint') : t('ai.or_api_keys_hint')}</p>
                    </div>
                ) : (
                    <div>
                        <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('ai.api_key')}</label>
                        <div className="relative mt-1">
                            <input
                                type={showKey ? 'text' : 'password'}
                                value={data.api_key}
                                onChange={e => setData('api_key', e.target.value)}
                                placeholder={provider.configured ? t('ai.api_key_encrypted_placeholder') : 'sk-…'}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 pr-10 text-sm"
                            />
                            <button type="button" onClick={() => setShowKey(v => !v)} className="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-600">
                                {showKey ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                            </button>
                        </div>
                    </div>
                )}

                {isCloudflare && (
                    <div>
                        <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('ai.cf_gateway_slug')}</label>
                        <input
                            type="text"
                            value={data.gateway_slug}
                            onChange={e => setData('gateway_slug', e.target.value)}
                            placeholder={t('ai.optional')}
                            className="mt-1 w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                        />
                    </div>
                )}

                <div>
                    <div className="flex items-center justify-between">
                        <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">
                            {t('ai.default_chat_model')}
                            {isOpenRouter && <span className="ml-1 text-neutral-400">· {t('ai.or_free_only', { count: chatModels.length })}</span>}
                        </label>
                        {isOpenRouter && (
                            <button type="button" onClick={refreshModels} disabled={refreshing}
                                className="text-xs text-brand-600 dark:text-brand-400 hover:underline disabled:opacity-50">
                                {refreshing ? t('ai.or_refreshing') : t('ai.or_refresh_models')}
                            </button>
                        )}
                    </div>
                    <select value={data.default_model_chat} onChange={e => setData('default_model_chat', e.target.value)} className="mt-1 w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm">
                        {!chatModels.includes(data.default_model_chat) && data.default_model_chat && (
                            <option value={data.default_model_chat}>{data.default_model_chat}</option>
                        )}
                        {chatModels.map(m => <option key={m} value={m}>{m}</option>)}
                    </select>
                </div>
                <label className="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" checked={data.enabled} onChange={e => setData('enabled', e.target.checked)} className="rounded" />
                    {t('common.enabled')}
                </label>
                <button type="submit" disabled={processing} className="w-full rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition">
                    {processing ? t('ai.saving') : t('common.save')}
                </button>
            </form>
            <div className="pt-2 border-t border-neutral-100 dark:border-neutral-800">
                <SetupGuide providerKey={provider.provider} />
            </div>
        </div>
    );
}

export default function AiProvidersIndex({ providers }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    return (
        <ClientLayout title={t('ai.providers_title')}>
            <Head title={t('ai.providers_title')} />
            <div className="space-y-5">
                <div>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('ai.provider_settings')}</h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{t('ai.provider_settings_subtitle')}</p>
                </div>
                {flash.success && <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {providers.length === 0 ? (
                        <div className="col-span-full">
                            <EmptyState
                                icon={<Bot className="h-8 w-8" />}
                                title={t('ai.no_providers_title')}
                                description={t('ai.no_providers_description')}
                            />
                        </div>
                    ) : (
                        providers.map(p => <ProviderCard key={p.provider} provider={p} />)
                    )}
                </div>
            </div>
        </ClientLayout>
    );
}
