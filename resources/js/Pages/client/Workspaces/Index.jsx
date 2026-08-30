import ClientLayout from '@/Layouts/ClientLayout';
import { Button, Card, Input, Badge } from '@/Components/ui';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function WorkspaceAvatar({ name }) {
    const initials = name
        .split(' ')
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');

    return (
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-700 font-semibold text-sm dark:bg-brand-900/40 dark:text-brand-300">
            {initials}
        </div>
    );
}

export default function WorkspacesIndex({ workspaces = [] }) {
    const { t } = useTranslation();
    const [name, setName] = useState('');
    const [creating, setCreating] = useState(false);
    const [switching, setSwitching] = useState(null);
    const currentWorkspace = usePage().props.currentWorkspace;

    const handleSwitch = (workspaceId) => {
        setSwitching(workspaceId);
        router.post(route('client.workspaces.switch'), { workspace_id: workspaceId }, {
            preserveScroll: true,
            onFinish: () => setSwitching(null),
        });
    };

    const handleCreate = (e) => {
        e.preventDefault();
        if (!name.trim()) return;
        setCreating(true);
        router.post(route('client.workspaces.store'), { name: name.trim() }, {
            preserveScroll: true,
            onFinish: () => {
                setCreating(false);
                setName('');
            },
        });
    };

    return (
        <ClientLayout title={t('workspaces.title')}>
            <Head title={t('workspaces.title')} />

            <div className="max-w-2xl space-y-8">
                {/* Header */}
                <div>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('workspaces.title')}</h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('workspaces.subtitle')}
                    </p>
                </div>

                {/* Workspace list */}
                <div className="space-y-3">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">
                        {t('workspaces.your_workspaces', { count: workspaces.length })}
                    </h3>

                    {workspaces.length === 0 ? (
                        <Card>
                            <Card.Body className="py-10 text-center">
                                <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-neutral-100 dark:bg-neutral-800">
                                    <svg className="h-6 w-6 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6a1.125 1.125 0 01-1.125-1.125v-3.75zM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 01-1.125-1.125v-8.25zM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 01-1.125-1.125v-2.25z" />
                                    </svg>
                                </div>
                                <p className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('workspaces.none_yet')}</p>
                                <p className="mt-1 text-sm text-neutral-400">{t('workspaces.create_first')}</p>
                            </Card.Body>
                        </Card>
                    ) : (
                        <ul className="space-y-2">
                            {workspaces.map((w) => {
                                const isCurrent = currentWorkspace?.id === w.id;
                                const isSwitching = switching === w.id;
                                return (
                                    <li key={w.id}>
                                        <div className={[
                                            'flex items-center justify-between rounded-xl border px-4 py-3 transition-colors duration-150',
                                            isCurrent
                                                ? 'border-brand-300 bg-brand-50 dark:border-brand-700 dark:bg-brand-900/20'
                                                : 'border-neutral-200 bg-white hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600 dark:hover:bg-neutral-800',
                                        ].join(' ')}>
                                            <div className="flex items-center gap-3">
                                                <WorkspaceAvatar name={w.name} />
                                                <div>
                                                    <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">{w.name}</p>
                                                    <p className="text-xs text-neutral-400 dark:text-neutral-500">
                                                        {w.is_owner ? t('workspaces.owner') : t('workspaces.member')}
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                {isCurrent && (
                                                    <Badge variant="brand" size="sm">{t('common.active')}</Badge>
                                                )}
                                                <Button
                                                    variant={isCurrent ? 'outline' : 'primary'}
                                                    size="sm"
                                                    onClick={() => !isCurrent && handleSwitch(w.id)}
                                                    disabled={isCurrent || isSwitching}
                                                >
                                                    {isSwitching ? t('workspaces.switching') : isCurrent ? t('workspaces.current') : t('workspaces.switch')}
                                                </Button>
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                {/* Create workspace */}
                {(
                <Card className="border-dashed border-neutral-300 dark:border-neutral-700">
                    <Card.Body className="space-y-4">
                        <div className="flex items-start gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 dark:bg-brand-900/30">
                                <svg className="h-4.5 w-4.5 text-brand-600 dark:text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </div>
                            <div>
                                <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('workspaces.create_new')}</h3>
                                <p className="mt-0.5 text-xs text-neutral-400 dark:text-neutral-500">{t('workspaces.create_new_desc')}</p>
                            </div>
                        </div>

                        <form onSubmit={handleCreate} className="flex items-end gap-3">
                            <Input
                                label={t('workspaces.name_label')}
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder={t('workspaces.name_placeholder')}
                                className="flex-1"
                            />
                            <Button
                                type="submit"
                                variant="primary"
                                disabled={creating || !name.trim()}
                                className="shrink-0"
                            >
                                {creating ? (
                                    <span className="flex items-center gap-1.5">
                                        <svg className="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                                        </svg>
                                        {t('workspaces.creating')}
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-1.5">
                                        <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        {t('workspaces.create_workspace')}
                                    </span>
                                )}
                            </Button>
                        </form>
                    </Card.Body>
                </Card>
                )}
            </div>
        </ClientLayout>
    );
}
