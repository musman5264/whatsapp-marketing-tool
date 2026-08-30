import { useState, useCallback, Fragment } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router } from '@inertiajs/react';
import { Card } from '@/Components/ui';
import {
    Rocket, GitBranch, Server, RefreshCw, RotateCcw, Terminal, AlertTriangle,
    CheckCircle, XCircle, Copy, Check, KeyRound, ShieldAlert, Loader2, ChevronDown,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';

const CSRF = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function api(routeName, { method = 'GET', body, params } = {}) {
    const url = new URL(route(routeName), window.location.origin);
    if (params) Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
    const res = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': CSRF(),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const json = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, json };
}

function StatusBadge({ status }) {
    const map = {
        success: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        reverted: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        failed: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        in_progress: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        pending: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300',
    };
    return <span className={`rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide ${map[status] ?? map.pending}`}>{status}</span>;
}

function ResultBlock({ result }) {
    if (!result) return null;
    const d = result.json?.data ?? result.json ?? {};
    const rows = Array.isArray(d.results) ? d.results : null;
    return (
        <div className="mt-3 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-900 p-3 text-xs">
            <div className="mb-1 flex items-center gap-1.5 font-medium">
                {result.json?.ok ? <CheckCircle className="h-3.5 w-3.5 text-emerald-500" /> : <XCircle className="h-3.5 w-3.5 text-red-500" />}
                {result.json?.ok ? 'Completed' : (result.json?.error ?? d.error ?? 'Reported issues')}
                {d.elapsed && <span className="text-neutral-400">· {d.elapsed}</span>}
            </div>
            {d.git?.commit && <div className="font-mono text-neutral-500">→ {d.git.commit}</div>}
            {rows && (
                <ul className="mt-2 space-y-0.5">
                    {rows.map((r, i) => {
                        const [k, v] = Object.entries(r)[0] ?? [];
                        const bad = String(v).startsWith('ERROR') || String(v).startsWith('FAIL');
                        return (
                            <li key={i} className="flex gap-2">
                                {bad ? <XCircle className="mt-0.5 h-3 w-3 shrink-0 text-red-500" /> : <Check className="mt-0.5 h-3 w-3 shrink-0 text-emerald-500" />}
                                <span className="text-neutral-500"><strong className="text-neutral-700 dark:text-neutral-300">{k}:</strong> {String(v)}</span>
                            </li>
                        );
                    })}
                </ul>
            )}
            {!rows && (
                <pre className="mt-2 max-h-64 overflow-auto whitespace-pre-wrap font-mono text-[11px] text-neutral-500">
                    {JSON.stringify(d, null, 2)}
                </pre>
            )}
        </div>
    );
}

export default function DeploymentIndex({ settings, localGit, lastDeploy, history }) {
    const { t } = useTranslation();

    const [form, setForm] = useState({
        repo_url: settings.repo_url,
        branch: settings.branch,
        deploy_url: settings.deploy_url,
        deploy_key: '',
        github_token: '',
        auto_migrate: settings.auto_migrate,
        auto_composer: settings.auto_composer,
        maintenance_mode: settings.maintenance_mode,
    });
    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    const [busy, setBusy] = useState(null); // action name currently running
    const [result, setResult] = useState(null);
    const [serverStatus, setServerStatus] = useState(null);
    const [gitInfo, setGitInfo] = useState(null);
    const [rollbackList, setRollbackList] = useState(null);
    const [rollbackTarget, setRollbackTarget] = useState('');
    const [pubKey, setPubKey] = useState(null);
    const [cmd, setCmd] = useState('php artisan optimize:clear');
    const [confirmKey, setConfirmKey] = useState('');
    const [showAdvanced, setShowAdvanced] = useState(false);
    const [expandedLog, setExpandedLog] = useState(null);
    const [copied, setCopied] = useState(false);

    const run = useCallback(async (name, fn) => {
        setBusy(name);
        setResult(null);
        try {
            const r = await fn();
            setResult(r);
        } finally {
            setBusy(null);
        }
    }, []);

    const saveSettings = () =>
        run('save', async () => {
            const r = await api('admin.deployment.settings.update', { method: 'POST', body: form });
            if (r.ok) router.reload({ only: ['settings'], preserveScroll: true });
            return r;
        });

    const deploy = () => {
        if (!confirm(t('deployment.confirm_deploy'))) return;
        run('deploy', async () => {
            const r = await api('admin.deployment.deploy', { method: 'POST' });
            router.reload({ only: ['history', 'lastDeploy', 'localGit'], preserveScroll: true });
            return r;
        });
    };

    const doRollback = () => {
        if (!rollbackTarget || !confirm(t('deployment.confirm_rollback'))) return;
        run('rollback', async () => {
            const r = await api('admin.deployment.rollback', { method: 'POST', body: { mode: 'execute', commit: rollbackTarget } });
            router.reload({ only: ['history', 'lastDeploy', 'localGit'], preserveScroll: true });
            return r;
        });
    };

    const runCmd = () =>
        run('cmd', async () =>
            api('admin.deployment.run-command', { method: 'POST', body: { command: cmd, confirm_key: confirmKey || undefined } }),
        );

    const copyKey = async () => {
        try {
            await navigator.clipboard.writeText(pubKey);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch { /* ignore */ }
    };

    const Toggle = ({ k, label }) => (
        <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={form[k]} onChange={(e) => set(k, e.target.checked)}
                className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500" />
            {label}
        </label>
    );

    const Field = ({ k, label, placeholder, type = 'text', hint, isSecret }) => (
        <div>
            <label className="mb-1 block text-xs font-medium text-neutral-700 dark:text-neutral-300">{label}</label>
            <input
                type={type}
                value={form[k]}
                onChange={(e) => set(k, e.target.value)}
                placeholder={isSecret && settings[`${k}_set`] ? '•••••••••••• (unchanged)' : placeholder}
                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-2.5 py-2 text-xs font-mono focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
            />
            {hint && <p className="mt-1 text-[10px] text-neutral-400">{hint}</p>}
        </div>
    );

    const behind = gitInfo?.remote?.data?.commits_behind ?? null;

    return (
        <AdminLayout title={t('deployment.title')}>
            <Head title={t('deployment.title')} />

            <div className="mb-6 flex items-center gap-3">
                <div className="rounded-xl bg-brand-100 dark:bg-brand-900/30 p-2">
                    <Rocket className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                </div>
                <div>
                    <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">{t('deployment.title')}</h1>
                    <p className="text-sm text-neutral-500">{t('deployment.subtitle')}</p>
                </div>
                <span className="ml-auto rounded bg-neutral-100 dark:bg-neutral-800 px-2 py-1 text-xs font-mono text-neutral-500">
                    v{settings.app_version}
                </span>
            </div>

            <div className="grid gap-5 lg:grid-cols-2">
                {/* Connection */}
                <Card className="p-5">
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold"><GitBranch className="h-4 w-4" /> {t('deployment.connection')}</h2>
                    <div className="space-y-3">
                        <Field k="repo_url" label={t('deployment.repo_url')} placeholder="https://github.com/you/repo.git" />
                        <Field k="branch" label={t('deployment.branch')} placeholder="main" />
                        <Field k="deploy_url" label={t('deployment.deploy_url')} placeholder="https://wa.esystematics.com/deploy.php"
                            hint={t('deployment.deploy_url_hint')} />
                        <Field k="deploy_key" label={t('deployment.deploy_key')} placeholder={t('deployment.deploy_key_ph')} isSecret type="password" />
                        <Field k="github_token" label={t('deployment.github_token')} placeholder={t('deployment.github_token_ph')} isSecret type="password"
                            hint={t('deployment.github_token_hint')} />
                        <div className="flex flex-wrap gap-4 pt-1">
                            <Toggle k="auto_migrate" label={t('deployment.auto_migrate')} />
                            <Toggle k="auto_composer" label={t('deployment.auto_composer')} />
                        </div>
                        <button onClick={saveSettings} disabled={busy === 'save'}
                            className="mt-1 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700 disabled:opacity-50">
                            {busy === 'save' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Check className="h-3.5 w-3.5" />}
                            {t('common.save')}
                        </button>
                    </div>

                    <div className="mt-4 border-t border-neutral-100 dark:border-neutral-800 pt-3">
                        <button onClick={() => run('setup-key', async () => {
                            const r = await api('admin.deployment.setup-key', { method: 'POST' });
                            setPubKey(r.json?.data?.public_key ?? null);
                            return r;
                        })} disabled={busy === 'setup-key'}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-1.5 text-xs hover:bg-neutral-50 dark:hover:bg-neutral-800 disabled:opacity-50">
                            {busy === 'setup-key' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <KeyRound className="h-3.5 w-3.5" />}
                            {t('deployment.generate_key')}
                        </button>
                        {pubKey && (
                            <div className="relative mt-2">
                                <pre className="max-h-24 overflow-auto rounded-lg bg-neutral-900 px-3 py-2 pr-10 text-[10px] font-mono text-neutral-100" dir="ltr">{pubKey}</pre>
                                <button onClick={copyKey} className="absolute right-2 top-2 text-neutral-400 hover:text-white">
                                    {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
                                </button>
                                <p className="mt-1 text-[10px] text-neutral-400">{t('deployment.add_key_hint')}</p>
                            </div>
                        )}
                    </div>
                </Card>

                {/* Server status + deploy */}
                <Card className="p-5">
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold"><Server className="h-4 w-4" /> {t('deployment.server')}</h2>
                    <div className="flex flex-wrap gap-2">
                        <button onClick={() => run('probe', async () => {
                            const r = await api('admin.deployment.status');
                            setServerStatus(r.json?.data ?? null);
                            return r;
                        })} disabled={busy === 'probe'}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-1.5 text-xs hover:bg-neutral-50 dark:hover:bg-neutral-800 disabled:opacity-50">
                            {busy === 'probe' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
                            {t('deployment.check_server')}
                        </button>
                        <button onClick={() => run('git-info', async () => {
                            const r = await api('admin.deployment.git-info');
                            setGitInfo(r.json ?? null);
                            return { ok: true, json: r.json };
                        })} disabled={busy === 'git-info'}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-1.5 text-xs hover:bg-neutral-50 dark:hover:bg-neutral-800 disabled:opacity-50">
                            {busy === 'git-info' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <GitBranch className="h-3.5 w-3.5" />}
                            {t('deployment.check_updates')}
                        </button>
                    </div>

                    {serverStatus && (
                        <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            {serverStatus.git_initialized === false && (
                                <div className="col-span-2 rounded bg-amber-50 dark:bg-amber-900/20 px-2 py-1 text-amber-700 dark:text-amber-300">
                                    {t('deployment.no_repo')}
                                    <button onClick={() => run('clone', async () => {
                                        const r = await api('admin.deployment.clone', { method: 'POST' });
                                        router.reload({ preserveScroll: true });
                                        return r;
                                    })} className="ml-2 underline">{t('deployment.clone_now')}</button>
                                </div>
                            )}
                            {serverStatus.git?.commit && <><dt className="text-neutral-400">{t('deployment.prod_commit')}</dt><dd className="font-mono">{serverStatus.git.commit}</dd></>}
                            {serverStatus.php_version && <><dt className="text-neutral-400">PHP</dt><dd>{serverStatus.php_version}</dd></>}
                            {serverStatus.disk_free && <><dt className="text-neutral-400">{t('deployment.disk_free')}</dt><dd>{serverStatus.disk_free}</dd></>}
                        </dl>
                    )}

                    {gitInfo && (
                        <div className="mt-3 rounded-lg bg-neutral-50 dark:bg-neutral-900 p-2.5 text-xs">
                            {behind != null && behind > 0
                                ? <span className="font-medium text-amber-600 dark:text-amber-400">{t('deployment.n_behind', { n: behind })}</span>
                                : <span className="font-medium text-emerald-600 dark:text-emerald-400">{t('deployment.up_to_date')}</span>}
                            {gitInfo.github_head?.short_sha && (
                                <div className="mt-1 text-neutral-500 font-mono">GitHub: {gitInfo.github_head.short_sha} — {gitInfo.github_head.message}</div>
                            )}
                        </div>
                    )}

                    <div className="mt-4 border-t border-neutral-100 dark:border-neutral-800 pt-3">
                        <button onClick={deploy} disabled={busy === 'deploy'}
                            className="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                            {busy === 'deploy' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Rocket className="h-4 w-4" />}
                            {t('deployment.deploy_latest')}
                        </button>
                        {lastDeploy && (
                            <p className="mt-2 text-[11px] text-neutral-400">
                                {t('deployment.last')}: <StatusBadge status={lastDeploy.status} /> {lastDeploy.completed_at ?? lastDeploy.created_at}
                            </p>
                        )}
                    </div>
                </Card>

                {/* Rollback */}
                <Card className="p-5">
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold"><RotateCcw className="h-4 w-4" /> {t('deployment.rollback')}</h2>
                    <button onClick={() => run('rb-list', async () => {
                        const r = await api('admin.deployment.rollback', { method: 'POST', body: { mode: 'list' } });
                        setRollbackList(r.json?.commits ?? r.json?.data?.commits ?? null);
                        return { ok: true, json: r.json };
                    })} disabled={busy === 'rb-list'}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-1.5 text-xs hover:bg-neutral-50 dark:hover:bg-neutral-800 disabled:opacity-50">
                        {busy === 'rb-list' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
                        {t('deployment.load_commits')}
                    </button>
                    {rollbackList && (
                        <div className="mt-3 max-h-56 space-y-1 overflow-auto">
                            {rollbackList.filter(Boolean).map((line) => {
                                const sha = line.split(' ')[0];
                                return (
                                    <label key={sha} className="flex items-start gap-2 rounded px-2 py-1 text-xs hover:bg-neutral-50 dark:hover:bg-neutral-800">
                                        <input type="radio" name="rb" value={sha} checked={rollbackTarget === sha}
                                            onChange={() => setRollbackTarget(sha)} className="mt-0.5" />
                                        <span className="font-mono text-neutral-500">{line}</span>
                                    </label>
                                );
                            })}
                        </div>
                    )}
                    <button onClick={doRollback} disabled={!rollbackTarget || busy === 'rollback'}
                        className="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-amber-300 dark:border-amber-700 px-3 py-1.5 text-xs text-amber-700 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20 disabled:opacity-40">
                        {busy === 'rollback' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RotateCcw className="h-3.5 w-3.5" />}
                        {t('deployment.rollback_to_selected')}
                    </button>
                </Card>

                {/* Advanced / commands */}
                <Card className="p-5">
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold"><Terminal className="h-4 w-4" /> {t('deployment.advanced')}</h2>
                    <label className="mb-1 block text-xs font-medium text-neutral-700 dark:text-neutral-300">{t('deployment.quick_command')}</label>
                    <select value={cmd} onChange={(e) => setCmd(e.target.value)}
                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-2.5 py-2 text-xs">
                        <option>php artisan optimize:clear</option>
                        <option>php artisan migrate --force</option>
                        <option>php artisan migrate:status</option>
                        <option>php artisan queue:restart</option>
                        <option>php artisan storage:link</option>
                        <option>git status</option>
                        <option>git log --oneline -20</option>
                        <option>composer install --no-dev --optimize-autoloader</option>
                    </select>

                    <button type="button" onClick={() => setShowAdvanced((v) => !v)}
                        className="mt-2 flex items-center gap-1 text-[11px] text-neutral-400 hover:text-neutral-600">
                        <ChevronDown className={`h-3 w-3 transition ${showAdvanced ? 'rotate-180' : ''}`} /> {t('deployment.custom_command')}
                    </button>
                    {showAdvanced && (
                        <div className="mt-2 space-y-2 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/10 p-2.5">
                            <p className="flex items-start gap-1.5 text-[10px] text-red-600 dark:text-red-400">
                                <ShieldAlert className="mt-0.5 h-3 w-3 shrink-0" /> {t('deployment.custom_command_warn')}
                            </p>
                            <input value={cmd} onChange={(e) => setCmd(e.target.value)} placeholder="any shell command"
                                className="w-full rounded border border-red-300 dark:border-red-700 bg-white dark:bg-neutral-800 px-2 py-1.5 text-xs font-mono" />
                            <input value={confirmKey} onChange={(e) => setConfirmKey(e.target.value)} type="password" placeholder={t('deployment.reenter_key')}
                                className="w-full rounded border border-red-300 dark:border-red-700 bg-white dark:bg-neutral-800 px-2 py-1.5 text-xs font-mono" />
                        </div>
                    )}

                    <button onClick={runCmd} disabled={busy === 'cmd'}
                        className="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-1.5 text-xs hover:bg-neutral-50 dark:hover:bg-neutral-800 disabled:opacity-50">
                        {busy === 'cmd' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Terminal className="h-3.5 w-3.5" />}
                        {t('deployment.run')}
                    </button>

                    <div className="mt-4 border-t border-neutral-100 dark:border-neutral-800 pt-3">
                        <p className="mb-2 text-[11px] font-medium text-neutral-500">{t('deployment.danger_zone')}</p>
                        <div className="flex flex-wrap gap-2">
                            <button onClick={() => confirm(t('deployment.confirm_cleanup')) && run('cleanup', () => api('admin.deployment.cleanup', { method: 'POST' }))}
                                className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-2.5 py-1 text-[11px] hover:bg-neutral-50 dark:hover:bg-neutral-800">
                                {t('deployment.lock_down')}
                            </button>
                            <button onClick={() => confirm(t('deployment.confirm_selfdelete')) && run('selfdelete', () => api('admin.deployment.self-delete', { method: 'POST' }))}
                                className="rounded-lg border border-red-300 dark:border-red-700 px-2.5 py-1 text-[11px] text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">
                                {t('deployment.remove_script')}
                            </button>
                        </div>
                    </div>
                </Card>
            </div>

            <ResultBlock result={result} />

            {/* History */}
            <Card className="mt-5 p-5">
                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold"><Rocket className="h-4 w-4" /> {t('deployment.history')}</h2>
                <div className="overflow-x-auto">
                    <table className="w-full text-xs">
                        <thead className="text-left text-neutral-400">
                            <tr className="border-b border-neutral-100 dark:border-neutral-800">
                                <th className="py-1.5 pr-3">{t('deployment.when')}</th>
                                <th className="py-1.5 pr-3">{t('deployment.who')}</th>
                                <th className="py-1.5 pr-3">{t('deployment.col_action')}</th>
                                <th className="py-1.5 pr-3">{t('deployment.status')}</th>
                                <th className="py-1.5 pr-3">{t('deployment.commit')}</th>
                                <th className="py-1.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {history.data.map((l) => (
                                <Fragment key={l.id}>
                                    <tr className="border-b border-neutral-50 dark:border-neutral-800/50">
                                        <td className="py-1.5 pr-3 text-neutral-500">{l.completed_at ?? l.created_at}</td>
                                        <td className="py-1.5 pr-3">{l.deployer?.name ?? '—'}</td>
                                        <td className="py-1.5 pr-3">{l.action}</td>
                                        <td className="py-1.5 pr-3"><StatusBadge status={l.status} /></td>
                                        <td className="py-1.5 pr-3 font-mono text-neutral-500">{l.commit_short ?? '—'} {l.commit_message ? `— ${l.commit_message}` : ''}</td>
                                        <td className="py-1.5">
                                            {(l.output || l.error_output) && (
                                                <button onClick={() => setExpandedLog(expandedLog === l.id ? null : l.id)} className="text-brand-500 hover:underline">
                                                    {expandedLog === l.id ? t('deployment.hide') : t('deployment.details')}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                    {expandedLog === l.id && (
                                        <tr>
                                            <td colSpan={6} className="bg-neutral-50 dark:bg-neutral-900 p-3">
                                                {l.error_output && <p className="mb-1 text-red-500">{l.error_output}</p>}
                                                <pre className="max-h-64 overflow-auto whitespace-pre-wrap font-mono text-[10px] text-neutral-500">{l.output}</pre>
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                            {history.data.length === 0 && (
                                <tr><td colSpan={6} className="py-6 text-center text-neutral-400">{t('deployment.no_history')}</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AdminLayout>
    );
}
