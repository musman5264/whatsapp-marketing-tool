import { useEffect, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Button, Card } from '@/Components/ui';
import axios from 'axios';
import { Wifi, WifiOff, CheckCircle, XCircle, Eye, EyeOff, RefreshCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatInTz } from '@/Utils/datetime';

function formatUptime(seconds) {
    if (seconds === null || seconds === undefined) return '—';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

function Field({ label, name, value, onChange, type = 'text', hint, ...rest }) {
    const [show, setShow] = useState(false);
    const isPassword = type === 'password';
    const inputType = isPassword && show ? 'text' : type;

    return (
        <div>
            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                {label}
            </label>
            <div className="relative">
                <input
                    type={inputType}
                    name={name}
                    value={value}
                    onChange={onChange}
                    autoComplete="off"
                    className="block w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 pr-10"
                    {...rest}
                />
                {isPassword && (
                    <button
                        type="button"
                        onClick={() => setShow((v) => !v)}
                        className="absolute inset-y-0 right-2 flex items-center text-neutral-400 hover:text-neutral-600"
                        tabIndex={-1}
                    >
                        {show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                    </button>
                )}
            </div>
            {hint && <p className="mt-1 text-xs text-neutral-400">{hint}</p>}
        </div>
    );
}

export default function ReverbSettingsIndex({ settings = {}, configured = false, active = false, flash = {}, runtime = null }) {
    const { t } = useTranslation();
    const adminTz = usePage().props.timezone || 'Asia/Dhaka';
    const { data, setData, put, processing, errors } = useForm({
        reverb_app_id: settings.reverb_app_id ?? '',
        reverb_app_key: settings.reverb_app_key ?? '',
        reverb_app_secret: settings.reverb_app_secret ?? '',
        reverb_host: settings.reverb_host ?? '',
        reverb_port: settings.reverb_port ?? '443',
        reverb_scheme: settings.reverb_scheme ?? 'https',
        reverb_listen_host: settings.reverb_listen_host ?? '0.0.0.0',
        reverb_listen_port: settings.reverb_listen_port ?? '8080',
    });

    const [testState, setTestState] = useState(null); // null | 'loading' | 'ok' | 'fail'
    const [testMsg, setTestMsg] = useState('');
    const [generating, setGenerating] = useState(false);
    const [toggling, setToggling] = useState(false);
    const [runtimeState, setRuntimeState] = useState(runtime);
    const [runtimeBusy, setRuntimeBusy] = useState(false);
    const [autostartBusy, setAutostartBusy] = useState(false);
    const autostartOn = settings.reverb_autostart !== 'false';

    // Live status: refreshed every 10 seconds while the page is open.
    useEffect(() => {
        let cancelled = false;
        const load = () => axios.get(route('admin.reverb-settings.status'))
            .then(({ data }) => { if (!cancelled) setRuntimeState(data); })
            .catch(() => {});
        const id = window.setInterval(load, 10000);
        return () => {
            cancelled = true;
            window.clearInterval(id);
        };
    }, []);

    const healthy = Boolean(runtimeState?.healthy);
    const statusLabel = !runtimeState
        ? '…'
        : healthy
            ? t('reverb.status_running')
            : !runtimeState.exec_available
                ? t('reverb.status_no_exec')
                : runtimeState.active
                    ? (runtimeState.autostart === false ? t('reverb.status_manual') : t('reverb.status_waiting'))
                    : t('reverb.status_off');

    // Saves the automatic start switch straight away (the other fields are left alone).
    const toggleAutostart = () => {
        setAutostartBusy(true);
        router.put(route('admin.reverb-settings.update'), { reverb_autostart: autostartOn ? 'false' : 'true' }, {
            preserveScroll: true,
            onFinish: () => setAutostartBusy(false),
        });
    };

    const runtimeAction = (routeName) => {
        setRuntimeBusy(true);
        router.post(route(routeName), {}, {
            preserveScroll: true,
            onFinish: () => setRuntimeBusy(false),
        });
    };

    const handleChange = (e) => setData(e.target.name, e.target.value);

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.reverb-settings.update'));
    };

    // Fills the form with new random credentials. Nothing is saved until the admin presses Save.
    const handleGenerate = async () => {
        setGenerating(true);
        try {
            const { data: res } = await axios.post(route('admin.reverb-settings.generate'));
            setData('reverb_app_id', res.reverb_app_id);
            setData('reverb_app_key', res.reverb_app_key);
            setData('reverb_app_secret', res.reverb_app_secret);
        } finally {
            setGenerating(false);
        }
    };

    const handleToggle = () => {
        setToggling(true);
        router.post(route('admin.reverb-settings.toggle'), { enabled: !active }, {
            preserveScroll: true,
            onFinish: () => setToggling(false),
        });
    };

    const handleTest = async () => {
        setTestState('loading');
        setTestMsg('');
        try {
            const { data: res } = await axios.post(route('admin.reverb-settings.test'));
            setTestState('ok');
            setTestMsg(res.message);
        } catch (e) {
            setTestState('fail');
            setTestMsg(e?.response?.data?.message || t('reverb.connection_failed'));
        }
    };

    const scheme = data.reverb_scheme === 'http' ? 'ws' : 'wss';
    const endpoint = data.reverb_host && data.reverb_app_key
        ? `${scheme}://${data.reverb_host}:${data.reverb_port || 443}/app/${data.reverb_app_key}`
        : '';

    return (
        <AdminLayout title={t('reverb.title')}>
            <Head title={t('reverb.title')} />

            <div className="max-w-2xl space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('reverb.heading')}</h2>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{t('reverb.description')}</p>
                    </div>
                    <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium whitespace-nowrap ${
                        active && healthy === false
                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                            : active
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                            : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300'
                    }`}>
                        {active && healthy === false
                            ? <><WifiOff className="h-3.5 w-3.5" /> {t('reverb.status_not_running')}</>
                            : active
                                ? <><Wifi className="h-3.5 w-3.5" /> {t('reverb.status_active')}</>
                                : <><WifiOff className="h-3.5 w-3.5" /> {configured ? t('reverb.status_off') : t('reverb.status_not_configured')}</>
                        }
                    </span>
                </div>

                <Card>
                    <Card.Body className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">{t('reverb.runtime_title')}</p>
                                <p className="text-xs text-neutral-400">{t('reverb.runtime_desc')}</p>
                            </div>
                            <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-medium whitespace-nowrap ${
                                healthy
                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                                    : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300'
                            }`}>
                                {statusLabel}
                            </span>
                        </div>

                        {runtimeState && (
                            <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
                                <div>
                                    <dt className="text-xs text-neutral-400">{t('reverb.pid')}</dt>
                                    <dd className="font-mono text-neutral-900 dark:text-neutral-100">{runtimeState.pid ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-neutral-400">{t('reverb.started')}</dt>
                                    <dd className="text-neutral-900 dark:text-neutral-100">
                                        {runtimeState.started_at
                                            ? formatInTz(runtimeState.started_at, adminTz)
                                            : (healthy ? t('reverb.started_outside') : '—')}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-neutral-400">{t('reverb.uptime')}</dt>
                                    <dd className="text-neutral-900 dark:text-neutral-100">{formatUptime(runtimeState.uptime_seconds)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-neutral-400">{t('reverb.listen')}</dt>
                                    <dd className="font-mono text-neutral-900 dark:text-neutral-100">{runtimeState.listen}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-neutral-400">{t('reverb.reachable')}</dt>
                                    <dd className={`text-sm ${runtimeState.public_reachable === false ? 'text-red-600 dark:text-red-400' : 'text-neutral-900 dark:text-neutral-100'}`}>
                                        {runtimeState.public_reachable === true
                                            ? t('reverb.reachable_yes')
                                            : runtimeState.public_reachable === false
                                                ? t('reverb.reachable_no')
                                                : '—'}
                                    </dd>
                                </div>
                                <div className="col-span-2 sm:col-span-4">
                                    <dt className="text-xs text-neutral-400">{t('reverb.last_check')}</dt>
                                    <dd className="text-neutral-900 dark:text-neutral-100">
                                        {runtimeState.last_watchdog
                                            ? `${formatInTz(runtimeState.last_watchdog.at, adminTz)} — ${runtimeState.last_watchdog.message}`
                                            : t('reverb.no_check_yet')}
                                    </dd>
                                </div>
                            </dl>
                        )}

                        {runtimeState && runtimeState.public_reachable === false && (
                            <p className="text-xs text-red-600 dark:text-red-400">{t('reverb.reachable_no_hint')}</p>
                        )}

                        <div className="flex flex-wrap items-center gap-3">
                            {runtimeState && !healthy && runtimeState.active && runtimeState.exec_available && (
                                <Button type="button" onClick={() => runtimeAction('admin.reverb-settings.start')} disabled={runtimeBusy}>
                                    {t('reverb.start_now')}
                                </Button>
                            )}
                            {healthy && (
                                <Button type="button" variant="outline" onClick={() => runtimeAction('admin.reverb-settings.restart')} disabled={runtimeBusy}>
                                    <RefreshCw className={`h-4 w-4 ${runtimeBusy ? 'animate-spin' : ''}`} />
                                    {t('reverb.restart')}
                                </Button>
                            )}
                        </div>

                        <div>
                            <p className="text-xs font-medium text-neutral-700 dark:text-neutral-300">{t('reverb.log_title')}</p>
                            <pre className="mt-1 max-h-48 overflow-auto whitespace-pre-wrap break-all rounded-soft bg-neutral-50 dark:bg-neutral-900 p-3 text-xs text-neutral-700 dark:text-neutral-300">
                                {runtimeState && runtimeState.log_tail && runtimeState.log_tail.length > 0
                                    ? runtimeState.log_tail.join('\n')
                                    : t('reverb.log_empty')}
                            </pre>
                        </div>
                    </Card.Body>
                </Card>

                {flash?.success && (
                    <div className="flex items-center gap-2 rounded-soft-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">
                        <CheckCircle className="h-4 w-4 flex-shrink-0" />
                        {flash.success}
                    </div>
                )}

                {flash?.error && (
                    <div className="flex items-center gap-2 rounded-soft-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2 text-sm">
                        <XCircle className="h-4 w-4 flex-shrink-0" />
                        {flash.error}
                    </div>
                )}

                <Card>
                    <Card.Body className="space-y-4">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">{t('reverb.toggle_label')}</p>
                                <p className="text-xs text-neutral-400">{t('reverb.toggle_desc')}</p>
                            </div>
                            <button
                                type="button"
                                onClick={handleToggle}
                                disabled={toggling || (!configured && !active)}
                                aria-pressed={active}
                                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 ${
                                    active ? 'bg-green-500' : 'bg-neutral-300 dark:bg-neutral-600'
                                }`}
                            >
                                <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ${active ? 'translate-x-5' : 'translate-x-0'}`} />
                            </button>
                        </div>
                        <div className="flex items-center justify-between gap-4 border-t border-neutral-200 dark:border-neutral-700 pt-4">
                            <div>
                                <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">{t('reverb.autostart_label')}</p>
                                <p className="text-xs text-neutral-400">{t('reverb.autostart_desc')}</p>
                            </div>
                            <button
                                type="button"
                                onClick={toggleAutostart}
                                disabled={autostartBusy}
                                aria-pressed={autostartOn}
                                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 ${
                                    autostartOn ? 'bg-green-500' : 'bg-neutral-300 dark:bg-neutral-600'
                                }`}
                            >
                                <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ${autostartOn ? 'translate-x-5' : 'translate-x-0'}`} />
                            </button>
                        </div>

                        {!configured && !active && (
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('reverb.toggle_needs_settings')}</p>
                        )}
                    </Card.Body>
                </Card>

                <Card>
                    <Card.Body>
                        <form onSubmit={handleSubmit} className="space-y-5">
                            <div className="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-neutral-200 dark:border-neutral-700">
                                <div>
                                    <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">{t('reverb.credentials_title')}</p>
                                    <p className="text-xs text-neutral-400">{t('reverb.credentials_desc')}</p>
                                </div>
                                <Button type="button" variant="outline" onClick={handleGenerate} disabled={generating}>
                                    <RefreshCw className={`h-4 w-4 ${generating ? 'animate-spin' : ''}`} />
                                    {generating ? t('reverb.generating') : t('reverb.generate')}
                                </Button>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={t('reverb.app_id')} name="reverb_app_id" value={data.reverb_app_id} onChange={handleChange} hint={t('reverb.app_id_hint')} />
                                <Field label={t('reverb.app_key')} name="reverb_app_key" value={data.reverb_app_key} onChange={handleChange} hint={t('reverb.app_key_hint')} />
                                <Field label={t('reverb.app_secret')} name="reverb_app_secret" value={data.reverb_app_secret} onChange={handleChange} type="password" hint={t('reverb.app_secret_hint')} />
                                <Field label={t('reverb.host')} name="reverb_host" value={data.reverb_host} onChange={handleChange} hint={t('reverb.host_hint')} />
                                <Field label={t('reverb.port')} name="reverb_port" type="number" value={data.reverb_port} onChange={handleChange} hint={t('reverb.port_hint')} />
                                <div>
                                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('reverb.scheme')}</label>
                                    <select
                                        name="reverb_scheme"
                                        value={data.reverb_scheme}
                                        onChange={handleChange}
                                        className="block w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
                                    >
                                        <option value="https">https (wss)</option>
                                        <option value="http">http (ws)</option>
                                    </select>
                                    <p className="mt-1 text-xs text-neutral-400">{t('reverb.scheme_hint')}</p>
                                </div>
                                <Field label={t('reverb.listen_host')} name="reverb_listen_host" value={data.reverb_listen_host} onChange={handleChange} hint={t('reverb.listen_host_hint')} />
                                <Field label={t('reverb.listen_port')} name="reverb_listen_port" type="number" value={data.reverb_listen_port} onChange={handleChange} hint={t('reverb.listen_port_hint')} />
                            </div>

                            {endpoint && (
                                <p className="text-xs text-neutral-500 dark:text-neutral-400 break-all">
                                    {t('reverb.browser_endpoint')}: <span className="font-mono">{endpoint}</span>
                                </p>
                            )}

                            {Object.keys(errors).length > 0 && (
                                <ul className="text-sm text-red-600 dark:text-red-400 space-y-0.5">
                                    {Object.values(errors).map((e, i) => <li key={i}>{e}</li>)}
                                </ul>
                            )}

                            <div className="flex flex-wrap items-center gap-3 pt-2">
                                <Button type="submit" disabled={processing}>
                                    {processing ? t('reverb.saving') : t('reverb.save_settings')}
                                </Button>

                                {configured && (
                                    <Button type="button" variant="outline" onClick={handleTest} disabled={testState === 'loading'}>
                                        {testState === 'loading' ? t('reverb.testing') : t('reverb.test_connection')}
                                    </Button>
                                )}
                            </div>

                            {testState && testState !== 'loading' && (
                                <div className={`flex items-center gap-2 text-sm mt-2 ${testState === 'ok' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                    {testState === 'ok'
                                        ? <CheckCircle className="h-4 w-4 flex-shrink-0" />
                                        : <XCircle className="h-4 w-4 flex-shrink-0" />
                                    }
                                    {testMsg}
                                </div>
                            )}
                        </form>
                    </Card.Body>
                </Card>

                <Card>
                    <Card.Body className="space-y-3">
                        <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('reverb.server_title')}</h3>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('reverb.server_intro')}</p>
                        <ol className="text-sm text-neutral-500 dark:text-neutral-400 space-y-1 list-decimal list-inside">
                            <li>{t('reverb.server_step_1')}</li>
                            <li>{t('reverb.server_step_2')}</li>
                            <li>{t('reverb.server_step_3')}</li>
                        </ol>
                    </Card.Body>
                </Card>
            </div>
        </AdminLayout>
    );
}
