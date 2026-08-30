import { Head, useForm, usePage, router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { useState } from 'react';
import { Eye, EyeOff, Mail, Send, Trash2, CheckCircle, AlertCircle, BookOpen, ChevronDown, ChevronUp } from 'lucide-react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

const SMTP_GUIDES = [
    {
        label: 'Gmail / Google Workspace',
        steps: [
            'Enable 2-Step Verification on your Google account.',
            'Go to myaccount.google.com → Security → App passwords.',
            'Generate an app password for "Mail".',
            'Use: host=smtp.gmail.com, port=587, encryption=TLS.',
            'Username = your Gmail address, Password = the app password.',
        ],
        link: 'https://myaccount.google.com/apppasswords',
        linkLabel: 'Generate App Password',
    },
    {
        label: 'SendGrid',
        steps: [
            'Sign up at sendgrid.com.',
            'Go to Settings → API Keys → Create API Key with "Mail Send" permission.',
            'Use: host=smtp.sendgrid.net, port=587, encryption=TLS.',
            'Username = apikey (literally), Password = the API key you just created.',
        ],
        link: 'https://app.sendgrid.com/settings/api_keys',
        linkLabel: 'Open SendGrid API Keys',
    },
    {
        label: 'Mailgun',
        steps: [
            'Sign up at mailgun.com and verify your domain.',
            'Go to Sending → Domains → select your domain → SMTP credentials.',
            'Use: host=smtp.mailgun.org, port=587, encryption=TLS.',
            'Username and password are shown in the SMTP credentials section.',
        ],
        link: 'https://app.mailgun.com/app/sending/domains',
        linkLabel: 'Open Mailgun Sending Domains',
    },
    {
        label: 'Amazon SES',
        steps: [
            'Log in to the AWS Console and open the SES service.',
            'Verify your domain or email address under Verified Identities.',
            'Go to SMTP Settings → Create SMTP Credentials (creates an IAM user).',
            'Use: host=email-smtp.<region>.amazonaws.com, port=587, encryption=TLS.',
            'Username and password are the SMTP credentials generated above.',
        ],
        link: 'https://console.aws.amazon.com/ses/home',
        linkLabel: 'Open Amazon SES Console',
    },
];

function SmtpSetupGuide() {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [activeTab, setActiveTab] = useState(0);
    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-6">
            <button
                type="button"
                onClick={() => setOpen(v => !v)}
                className="flex items-center gap-2 w-full text-left"
            >
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 shrink-0">
                    <BookOpen className="h-4 w-4" />
                </div>
                <div className="flex-1">
                    <span className="font-semibold text-sm text-neutral-900 dark:text-neutral-100">{t('email_server.setup_guide')}</span>
                    <p className="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">{t('email_server.setup_guide_desc')}</p>
                </div>
                {open ? <ChevronUp className="h-4 w-4 text-neutral-400" /> : <ChevronDown className="h-4 w-4 text-neutral-400" />}
            </button>
            {open && (
                <div className="mt-4 space-y-3">
                    <div className="flex flex-wrap gap-2">
                        {SMTP_GUIDES.map((g, i) => (
                            <button
                                key={i}
                                type="button"
                                onClick={() => setActiveTab(i)}
                                className={`px-3 py-1.5 rounded-lg text-xs font-medium transition ${
                                    activeTab === i
                                        ? 'bg-brand-600 text-white'
                                        : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-600 dark:text-neutral-300 hover:bg-neutral-200 dark:hover:bg-neutral-700'
                                }`}
                            >
                                {g.label}
                            </button>
                        ))}
                    </div>
                    <div className="rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 px-4 py-3 space-y-2">
                        <p className="text-xs font-semibold text-blue-800 dark:text-blue-300">{SMTP_GUIDES[activeTab].label}</p>
                        <ol className="space-y-1.5">
                            {SMTP_GUIDES[activeTab].steps.map((step, i) => (
                                <li key={i} className="flex gap-2 text-xs text-blue-700 dark:text-blue-400">
                                    <span className="shrink-0 font-medium text-blue-500">{i + 1}.</span>
                                    <span>{step}</span>
                                </li>
                            ))}
                        </ol>
                        {SMTP_GUIDES[activeTab].link && (
                            <a
                                href={SMTP_GUIDES[activeTab].link}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline pt-1"
                            >
                                {SMTP_GUIDES[activeTab].linkLabel} →
                            </a>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

function FieldRow({ label, error, children }) {
    return (
        <div>
            <label className="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">
                {label}
            </label>
            {children}
            {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
        </div>
    );
}

export default function EmailServerIndex({ config }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const hasConfig = !!config;

    const ENCRYPTION_OPTIONS = [
        { value: 'tls', label: t('email_server.enc_tls') },
        { value: 'ssl', label: t('email_server.enc_ssl') },
        { value: 'none', label: t('email_server.enc_none') },
    ];

    const [showPassword, setShowPassword] = useState(false);
    const [testEmail, setTestEmail] = useState('');
    const [testLoading, setTestLoading] = useState(false);
    const [testResult, setTestResult] = useState(null);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        host: config?.host ?? '',
        port: config?.port ?? 587,
        username: config?.username ?? '',
        password: '',
        encryption: config?.encryption ?? 'tls',
        from_email: config?.from_email ?? '',
        from_name: config?.from_name ?? '',
        is_active: config?.is_active ?? true,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        if (hasConfig) {
            put(route('client.email-server.update'), { preserveScroll: true });
        } else {
            post(route('client.email-server.store'), { preserveScroll: true });
        }
    };

    const handleDelete = () => {
        if (!confirm(t('email_server.remove_confirm'))) return;
        router.delete(route('client.email-server.destroy'), { preserveScroll: true });
    };

    const handleTest = async () => {
        if (!testEmail) return;
        setTestLoading(true);
        setTestResult(null);
        try {
            const res = await axios.post(route('client.email-server.test'), { email: testEmail });
            setTestResult({ ok: true, message: res.data.message });
        } catch (err) {
            setTestResult({ ok: false, message: err.response?.data?.message ?? t('email_server.test_failed') });
        } finally {
            setTestLoading(false);
        }
    };

    return (
        <ClientLayout title={t('email_server.title')}>
            <Head title={t('email_server.title')} />
            <div className="max-w-2xl space-y-6">
                {/* Header */}
                <div>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('email_server.title')}</h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('email_server.subtitle')}
                    </p>
                </div>

                {/* Flash messages */}
                {flash.success && (
                    <div className="flex items-center gap-2 rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2.5 text-sm">
                        <CheckCircle className="h-4 w-4 shrink-0" />
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="flex items-center gap-2 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2.5 text-sm">
                        <AlertCircle className="h-4 w-4 shrink-0" />
                        {flash.error}
                    </div>
                )}

                {/* Status badge */}
                {hasConfig && (
                    <div className="flex items-center gap-2 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle className="h-4 w-4" />
                        <span>{t('email_server.connected_to')} <strong>{config.summary}</strong></span>
                    </div>
                )}

                {/* SMTP Form */}
                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-6">
                    <div className="flex items-center gap-2 mb-5">
                        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 shrink-0">
                            <Mail className="h-4 w-4" />
                        </div>
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">
                            {hasConfig ? t('email_server.update_smtp') : t('email_server.add_smtp')}
                        </h3>
                    </div>

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div className="sm:col-span-2">
                                <FieldRow label={`${t('email_server.smtp_host')} *`} error={errors.host}>
                                    <input
                                        type="text"
                                        value={data.host}
                                        onChange={e => setData('host', e.target.value)}
                                        placeholder="smtp.example.com"
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                                    />
                                </FieldRow>
                            </div>
                            <div>
                                <FieldRow label={`${t('email_server.port')} *`} error={errors.port}>
                                    <input
                                        type="number"
                                        value={data.port}
                                        onChange={e => setData('port', parseInt(e.target.value, 10) || 587)}
                                        placeholder="587"
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                                    />
                                </FieldRow>
                            </div>
                        </div>

                        <FieldRow label={`${t('email_server.encryption')} *`} error={errors.encryption}>
                            <select
                                value={data.encryption}
                                onChange={e => setData('encryption', e.target.value)}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                            >
                                {ENCRYPTION_OPTIONS.map(o => (
                                    <option key={o.value} value={o.value}>{o.label}</option>
                                ))}
                            </select>
                        </FieldRow>

                        <FieldRow label={`${t('email_server.username')} *`} error={errors.username}>
                            <input
                                type="text"
                                value={data.username}
                                onChange={e => setData('username', e.target.value)}
                                placeholder="your@email.com"
                                autoComplete="username"
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                            />
                        </FieldRow>

                        <FieldRow label={hasConfig ? t('email_server.password_keep') : `${t('email_server.password')} *`} error={errors.password}>
                            <div className="relative">
                                <input
                                    type={showPassword ? 'text' : 'password'}
                                    value={data.password}
                                    onChange={e => setData('password', e.target.value)}
                                    placeholder={hasConfig ? t('email_server.password_unchanged') : '••••••••'}
                                    autoComplete="new-password"
                                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(s => !s)}
                                    className="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300"
                                >
                                    {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                </button>
                            </div>
                        </FieldRow>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <FieldRow label={`${t('email_server.from_email')} *`} error={errors.from_email}>
                                <input
                                    type="email"
                                    value={data.from_email}
                                    onChange={e => setData('from_email', e.target.value)}
                                    placeholder="noreply@yourdomain.com"
                                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                                />
                            </FieldRow>
                            <FieldRow label={`${t('email_server.from_name')} *`} error={errors.from_name}>
                                <input
                                    type="text"
                                    value={data.from_name}
                                    onChange={e => setData('from_name', e.target.value)}
                                    placeholder="Your Company"
                                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                                />
                            </FieldRow>
                        </div>

                        {hasConfig && (
                            <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    checked={data.is_active}
                                    onChange={e => setData('is_active', e.target.checked)}
                                    className="rounded"
                                />
                                <span className="text-neutral-700 dark:text-neutral-300">{t('email_server.enable_smtp')}</span>
                            </label>
                        )}

                        <div className="flex items-center gap-3 pt-2">
                            <button
                                type="submit"
                                disabled={processing}
                                className="rounded-lg bg-brand-600 hover:bg-brand-700 disabled:opacity-60 px-5 py-2 text-sm font-medium text-white transition"
                            >
                                {processing ? t('email_server.saving') : (hasConfig ? t('email_server.update_config') : t('email_server.save_config'))}
                            </button>
                            {hasConfig && (
                                <button
                                    type="button"
                                    onClick={handleDelete}
                                    className="flex items-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-2 text-sm text-neutral-500 hover:text-red-500 hover:border-red-300 dark:hover:border-red-700 transition"
                                >
                                    <Trash2 className="h-4 w-4" />
                                    {t('email_server.remove')}
                                </button>
                            )}
                        </div>
                    </form>
                </div>

                {/* Setup Guide */}
                <SmtpSetupGuide />

                {/* Test Email */}
                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-6">
                    <div className="flex items-center gap-2 mb-4">
                        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 shrink-0">
                            <Send className="h-4 w-4" />
                        </div>
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{t('email_server.send_test_title')}</h3>
                    </div>
                    <p className="text-sm text-neutral-500 dark:text-neutral-400 mb-4">
                        {t('email_server.send_test_desc')}
                    </p>

                    {testResult && (
                        <div className={`flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm mb-4 ${
                            testResult.ok
                                ? 'bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200'
                                : 'bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200'
                        }`}>
                            {testResult.ok
                                ? <CheckCircle className="h-4 w-4 shrink-0" />
                                : <AlertCircle className="h-4 w-4 shrink-0" />}
                            {testResult.message}
                        </div>
                    )}

                    <div className="flex gap-3">
                        <input
                            type="email"
                            value={testEmail}
                            onChange={e => setTestEmail(e.target.value)}
                            placeholder="recipient@example.com"
                            className="flex-1 rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                        />
                        <button
                            type="button"
                            onClick={handleTest}
                            disabled={testLoading || !testEmail}
                            className="flex items-center gap-1.5 rounded-lg bg-neutral-900 dark:bg-neutral-700 hover:bg-neutral-700 dark:hover:bg-neutral-600 disabled:opacity-60 px-4 py-2 text-sm font-medium text-white transition"
                        >
                            <Send className="h-4 w-4" />
                            {testLoading ? t('email_server.sending') : t('email_server.send_test')}
                        </button>
                    </div>
                </div>

                {/* Info box */}
                <div className="rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 px-4 py-3 text-sm text-blue-800 dark:text-blue-300 space-y-1">
                    <p className="font-medium">{t('email_server.how_it_works')}</p>
                    <ul className="list-disc list-inside space-y-0.5 text-blue-700 dark:text-blue-400">
                        <li>{t('email_server.how_tip_1')}</li>
                        <li>{t('email_server.how_tip_2')}</li>
                    </ul>
                </div>
            </div>
        </ClientLayout>
    );
}
