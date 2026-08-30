import { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, Button, Badge, Input } from '@/Components/ui';
import { ShieldCheck, ShieldAlert, RefreshCw, Loader2, KeyRound, CheckCircle2, XCircle, Download, AlertTriangle } from 'lucide-react';
import { licenseCopy } from '@/lib/licenseLabels';
import LicenseTypeTabs from '@/Components/LicenseTypeTabs';

function Row({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-4 py-2 text-sm">
            <span className="text-neutral-500 dark:text-neutral-400">{label}</span>
            <span className="font-medium text-neutral-900 dark:text-neutral-100 break-all text-right">{value}</span>
        </div>
    );
}

export default function AdminLicenseIndex({ license = {} }) {
    const { t } = useTranslation();
    const flash = usePage().props.flash ?? {};
    const copy = licenseCopy(license.verify_type);

    const [checking, setChecking] = useState(false);
    const [update, setUpdate] = useState(null);
    const [deactivating, setDeactivating] = useState(false);
    const [applying, setApplying] = useState(false);
    const [applyResult, setApplyResult] = useState(null);

    const [activateForm, setActivateForm] = useState({ license_code: '', client_name: '', verify_type: license.verify_type || 'non_envato' });
    const formCopy = licenseCopy(activateForm.verify_type);
    const [activating, setActivating] = useState(false);

    const checkUpdate = async () => {
        setChecking(true);
        setUpdate(null);
        try {
            const res = await window.axios.post(route('admin.license.check-update'));
            setUpdate(res.data);
        } catch (err) {
            setUpdate({ ok: false, update_available: false, message: err?.response?.data?.message || 'Update check failed.' });
        } finally {
            setChecking(false);
        }
    };

    const applyUpdate = async () => {
        if (!window.confirm('Download and install this update now? The site will go into maintenance mode briefly. Make sure you have a backup.')) return;
        setApplying(true);
        setApplyResult(null);
        try {
            const res = await window.axios.post(route('admin.license.apply-update'), {}, { timeout: 0 });
            setApplyResult(res.data);
        } catch (err) {
            setApplyResult({ ok: false, message: err?.response?.data?.message || 'The update failed. Check the logs and try again.' });
        } finally {
            setApplying(false);
        }
    };

    const deactivate = () => {
        if (!window.confirm('Deactivate this license? The admin panel will be locked until you re-activate.')) return;
        router.post(route('admin.license.deactivate'), {}, {
            onStart: () => setDeactivating(true),
            onFinish: () => setDeactivating(false),
        });
    };

    const activate = (e) => {
        e.preventDefault();
        router.post(route('admin.license.activate'), activateForm, {
            onStart: () => setActivating(true),
            onFinish: () => setActivating(false),
        });
    };

    // Auto-check for updates on page load so an available update is surfaced
    // without the operator having to click "Check for updates".
    useEffect(() => {
        if (license.enabled && license.activated) {
            checkUpdate();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <AdminLayout title={t('admin.license') || 'License & Updates'}>
            <Head title={`${t('admin.license') || 'License & Updates'}`} />

            <div className="mx-auto max-w-3xl space-y-6">
                <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    {t('admin.license') || 'License & Updates'}
                </h2>

                {flash.success && (
                    <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                        {flash.error}
                    </div>
                )}

                {/* License status */}
                <Card>
                    <Card.Header title="License status" />
                    <Card.Body>
                        {!license.enabled ? (
                            <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                                <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>Licensing is not configured for this build.</span>
                            </div>
                        ) : (
                            <div className="mb-2 flex items-center gap-2">
                                {license.activated ? (
                                    <Badge className="bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">
                                        <ShieldCheck className="mr-1 inline h-3.5 w-3.5" /> Active
                                    </Badge>
                                ) : (
                                    <Badge className="bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
                                        <ShieldAlert className="mr-1 inline h-3.5 w-3.5" /> Not activated
                                    </Badge>
                                )}
                            </div>
                        )}

                        <div className="divide-y divide-neutral-100 dark:divide-neutral-800">
                            <Row label="Current version" value={license.current_version || '—'} />
                            <Row label={copy.label} value={license.masked_code || '—'} />
                            <Row label="Product ID" value={license.product_id || '—'} />
                        </div>

                        {license.enabled && license.activated && (
                            <div className="mt-4">
                                <Button variant="danger" size="sm" onClick={deactivate} disabled={deactivating}>
                                    {deactivating ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                                    Deactivate license
                                </Button>
                                <p className="mt-2 text-xs text-neutral-400 dark:text-neutral-500">
                                    Deactivating frees this installation's slot so you can move the license to another server.
                                </p>
                            </div>
                        )}
                    </Card.Body>
                </Card>

                {/* Re-activate (only when configured but not activated) */}
                {license.enabled && !license.activated && (
                    <Card>
                        <Card.Header title="Activate license" />
                        <Card.Body>
                            <form onSubmit={activate} className="space-y-4">
                                <LicenseTypeTabs
                                    types={license.verify_types}
                                    value={activateForm.verify_type}
                                    onChange={(t) => setActivateForm((f) => ({ ...f, verify_type: t }))}
                                />
                                <div>
                                    <Input
                                        label={formCopy.label}
                                        value={activateForm.license_code}
                                        onChange={(e) => setActivateForm((f) => ({ ...f, license_code: e.target.value }))}
                                        placeholder={formCopy.placeholder}
                                    />
                                    {formCopy.helpUrl && (
                                        <p className="mt-1.5 text-xs text-neutral-500 dark:text-neutral-400">
                                            Your purchase code is in your Envato/CodeCanyon account under{' '}
                                            <span className="font-medium">Downloads</span>.{' '}
                                            <a
                                                href={formCopy.helpUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="font-medium text-brand-600 underline hover:text-brand-700 dark:text-brand-400"
                                            >
                                                {formCopy.helpText}
                                            </a>
                                        </p>
                                    )}
                                </div>
                                <Input
                                    label={formCopy.nameLabel}
                                    value={activateForm.client_name}
                                    onChange={(e) => setActivateForm((f) => ({ ...f, client_name: e.target.value }))}
                                    placeholder={formCopy.namePlaceholder}
                                    required={formCopy.nameRequired}
                                />
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={activating || !activateForm.license_code.trim() || (formCopy.nameRequired && !activateForm.client_name.trim())}
                                >
                                    {activating ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <KeyRound className="mr-2 h-4 w-4" />}
                                    {formCopy.activateLabel}
                                </Button>
                            </form>
                        </Card.Body>
                    </Card>
                )}

                {/* Update-available alert (auto-checked on load) */}
                {license.enabled && update?.ok && update.update_available && (
                    <div className="flex flex-col gap-2 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:border-brand-800 dark:bg-brand-900/20 dark:text-brand-300 sm:flex-row sm:items-center sm:justify-between">
                        <span className="flex items-center gap-2">
                            <Download className="h-4 w-4 shrink-0" />
                            <span><span className="font-semibold">Update available:</span> v{update.version} is ready to install.</span>
                        </span>
                        <Button size="sm" variant="primary" onClick={applyUpdate} disabled={applying}>
                            {applying ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Download className="mr-2 h-4 w-4" />}
                            {applying ? 'Installing…' : 'Install now'}
                        </Button>
                    </div>
                )}

                {/* Updates */}
                {license.enabled && (
                    <Card>
                        <Card.Header
                            title="Updates"
                            action={
                                <Button variant="outline" size="sm" onClick={checkUpdate} disabled={checking}>
                                    {checking ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-2 h-4 w-4" />}
                                    {checking ? 'Checking…' : 'Check for updates'}
                                </Button>
                            }
                        />
                        <Card.Body>
                            {!update && (
                                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                    You're on version <span className="font-medium">{license.current_version || '—'}</span>.
                                    Check for updates to see if a newer release is available.
                                </p>
                            )}

                            {update && !update.ok && (
                                <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                                    <XCircle className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>{update.message || 'Update check failed.'}</span>
                                </div>
                            )}

                            {update && update.ok && !update.update_available && (
                                <div className="flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                                    <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>{update.message || "You're using the latest version."}</span>
                                </div>
                            )}

                            {update && update.ok && update.update_available && (
                                <div className="space-y-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge className="bg-brand-100 text-brand-800 dark:bg-brand-900/30 dark:text-brand-300">
                                            New: v{update.version}
                                        </Badge>
                                        {update.released_at && (
                                            <span className="text-xs text-neutral-400">Released {update.released_at}</span>
                                        )}
                                    </div>
                                    {update.summary && (
                                        <p className="text-sm text-neutral-700 dark:text-neutral-300">{update.summary}</p>
                                    )}
                                    {update.changelog && (
                                        <div
                                            className="prose prose-sm max-w-none rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm dark:prose-invert dark:border-neutral-800 dark:bg-neutral-900/40"
                                            dangerouslySetInnerHTML={{ __html: update.changelog }}
                                        />
                                    )}

                                    <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>
                                            Installing downloads the package and overwrites application files (your
                                            <span className="font-medium"> .env</span>, uploads and database are preserved), runs any
                                            migrations, then clears caches. The site goes into maintenance mode for a moment.
                                            <span className="font-medium"> Back up first</span> and ideally test on staging.
                                        </span>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-3">
                                        <Button variant="primary" onClick={applyUpdate} disabled={applying}>
                                            {applying ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Download className="mr-2 h-4 w-4" />}
                                            {applying ? 'Installing…' : `Download & install v${update.version}`}
                                        </Button>
                                        {applying && (
                                            <span className="text-xs text-neutral-500 dark:text-neutral-400">
                                                This can take a few minutes — please don't close this window.
                                            </span>
                                        )}
                                    </div>

                                    {applyResult && (
                                        <div
                                            className={[
                                                'flex items-start gap-2 rounded-lg border px-4 py-3 text-sm',
                                                applyResult.ok
                                                    ? 'border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300'
                                                    : 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300',
                                            ].join(' ')}
                                        >
                                            {applyResult.ok ? (
                                                <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                                            ) : (
                                                <XCircle className="mt-0.5 h-4 w-4 shrink-0" />
                                            )}
                                            <div>
                                                <p>{applyResult.message}</p>
                                                {applyResult.ok && (
                                                    <button
                                                        type="button"
                                                        onClick={() => window.location.reload()}
                                                        className="mt-1 font-medium underline"
                                                    >
                                                        Reload now
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </Card.Body>
                    </Card>
                )}
            </div>
        </AdminLayout>
    );
}
