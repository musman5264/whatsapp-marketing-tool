import { router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Toaster, toast } from 'sonner';
import Sidebar from '@/Components/Sidebar';
import UpgradeModal from '@/Components/UpgradeModal';
import useClientNav from '@/Layouts/useClientNav';

export default function InboxLayout({ children }) {
    const { t } = useTranslation();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const { auth, impersonation, current_workspace_usage, unreadNotificationsCount, branding, demo_mode } = usePage().props;
    const logoUrl = branding?.logo_url;
    const [unreadCount, setUnreadCount] = useState(unreadNotificationsCount ?? 0);
    const clientNavGroups = useClientNav();

    useEffect(() => {
        setUnreadCount(unreadNotificationsCount ?? 0);
    }, [unreadNotificationsCount]);

    useEffect(() => {
        if (!window.Echo || !auth?.user?.id) return;
        window.Echo.private(`App.Models.User.${auth.user.id}`)
            .notification((notification) => {
                setUnreadCount(prev => prev + 1);
                const msg = notification.snippet ?? notification.name ?? notification.automation ?? notification.error ?? 'New notification';
                const title = {
                    new_message: 'New message',
                    mention: '@ You were mentioned',
                    conversation_assigned: 'Conversation assigned',
                    campaign_completed: 'Campaign completed',
                    automation_failed: 'Automation failed',
                    billing_failed: 'Payment failed',
                }[notification.type] ?? 'Notification';
                toast(title, {
                    description: msg,
                    action: notification.url ? { label: 'View', onClick: () => router.visit(notification.url) } : undefined,
                });
            });
        return () => { window.Echo.leave(`App.Models.User.${auth.user.id}`); };
    }, [auth?.user?.id]);

    const returnToAdmin = () => {
        router.post(impersonation?.returnUrl ?? route('admin.impersonation.stop'));
    };

    return (
        <div className="h-screen overflow-hidden bg-neutral-50 dark:bg-neutral-950 flex flex-col">
            {impersonation?.active && (
                <div className="flex items-center justify-between gap-4 bg-amber-500/90 text-white px-4 py-2 text-sm font-medium shrink-0">
                    <span>{t('impersonation.impersonating', { name: impersonation.clientName })}</span>
                    <button type="button" onClick={returnToAdmin} className="rounded-soft bg-white/20 px-3 py-1.5 font-medium hover:bg-white/30 transition">
                        {t('impersonation.return_to_admin')}
                    </button>
                </div>
            )}

            <div className="flex flex-1 overflow-hidden">
                <Sidebar
                    open={sidebarOpen}
                    onClose={() => setSidebarOpen(false)}
                    title={t('client.panel') || 'Client Panel'}
                    logo={logoUrl ? <img src={logoUrl} alt="Logo" className="h-8 max-w-[160px] object-contain" /> : null}
                    showCreateButton={false}
                    navGroups={clientNavGroups.map(group => ({
                        ...group,
                        items: group.items.map(item => ({
                            ...item,
                            key: item.activePattern || item.label,
                            active: () => item.activePattern ? route().current(item.activePattern) : false,
                        }))
                    }))}
                />

                <div className="lg:pl-64 rtl:lg:pl-0 rtl:lg:pr-64 flex-1 overflow-hidden flex flex-col">
                    {children}
                </div>
            </div>

            {/* Demo notice — pinned to the bottom of the viewport */}
            {demo_mode && (
                <div className="flex items-center justify-center gap-2 bg-amber-500/90 text-amber-950 px-4 py-2 text-sm font-medium shrink-0">
                    <span>{t('demo.banner') || 'Demo mode: changes are disabled.'}</span>
                </div>
            )}

            {/* Mobile menu button */}
            <button
                type="button"
                onClick={() => setSidebarOpen(true)}
                className="fixed bottom-4 right-4 z-30 flex h-12 w-12 items-center justify-center rounded-soft-lg bg-white dark:bg-neutral-900 border border-gray-200 dark:border-neutral-800 shadow-lg text-neutral-600 hover:bg-neutral-50 dark:text-neutral-400 dark:hover:bg-neutral-800 lg:hidden rtl:right-auto rtl:left-4"
                aria-label={t('open_menu')}
            >
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <UpgradeModal />
            <Toaster richColors position="top-right" />
        </div>
    );
}
