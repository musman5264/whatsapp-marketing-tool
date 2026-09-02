import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ChannelBrandIcon } from '@/Components/BrandIcons';
import {
    LayoutDashboard, CreditCard, Package, FileText, Users, Settings,
    Layers, Webhook, Key, BookOpen, Image, Radio, Inbox, Bot, Database,
    Zap, Share2, MapPin, Tag, LifeBuoy, ExternalLink, Mail, MessageSquare,
    ShoppingBag, KanbanSquare, Activity,
} from 'lucide-react';

const iconClass = 'h-4 w-4';
const whatsappNavIcon = <ChannelBrandIcon channel="whatsapp" className={iconClass} />;

function safeRoute(name, ...args) {
    try { return route(name, ...args); } catch { return '#'; }
}

/**
 * Single source of truth for the client-panel sidebar navigation.
 *
 * Both ClientLayout and InboxLayout consume this so the sidebar is identical on
 * every client page. Previously each layout kept its own copy and they drifted —
 * the inbox sidebar was missing whole groups (Social Media, Automations, Leads)
 * and items. Keep all nav changes here only.
 */
export default function useClientNav() {
    const { auth, branding } = usePage().props;
    const { t } = useTranslation();
    const user = auth?.user;
    const docsUrl = branding?.docs_url;
    const isClientAdmin = user?.client_role === 'administrator';

    const accountItems = [
        { label: t('nav.dashboard'), href: safeRoute('client.dashboard'), icon: <LayoutDashboard className={iconClass} />, activePattern: 'client.dashboard' },
    ];

    const accountSettingsItems = [
        { label: t('nav.workspaces'), href: safeRoute('client.workspaces.index'), icon: <Layers className={iconClass} />,   activePattern: 'client.workspaces.*' },
        { label: t('nav.settings'),   href: safeRoute('client.settings.index'),   icon: <Settings className={iconClass} />, activePattern: 'client.settings.*' },
    ];

    if (isClientAdmin) {
        accountSettingsItems.push(
            { label: t('nav.team'),      href: safeRoute('client.team.index'),      icon: <Users className={iconClass} />,    activePattern: 'client.team.*' },
            { label: t('nav.audit_log'), href: safeRoute('client.audit-log.index'), icon: <FileText className={iconClass} />, activePattern: 'client.audit-log.*' },
        );
    }

    const billingItems = [
        { label: t('nav.subscription'), href: safeRoute('client.subscription.show'), icon: <CreditCard className={iconClass} />, activePattern: 'client.subscription.*' },
        { label: t('nav.billing'),      href: safeRoute('client.billing.index'),     icon: <CreditCard className={iconClass} />, activePattern: 'client.billing.*' },
        { label: t('nav.plans'),        href: safeRoute('client.pricing'),            icon: <Package className={iconClass} />,    activePattern: 'client.pricing' },
    ];

    const developerItems = [
        { label: t('nav.api_tokens'),    href: safeRoute('client.api-tokens.index'), icon: <Key className={iconClass} />,      activePattern: 'client.api-tokens.*' },
        { label: t('nav.api_usage'),     href: safeRoute('client.api-usage.index'),   icon: <Activity className={iconClass} />, activePattern: 'client.api-usage.*' },
        { label: t('nav.webhooks'),      href: safeRoute('client.webhooks.index'),    icon: <Webhook className={iconClass} />,  activePattern: 'client.webhooks.*' },
        { label: t('nav.api_docs'),      href: safeRoute('client.api-docs'),          icon: <BookOpen className={iconClass} />, activePattern: 'client.api-docs' },
        { label: t('nav.media_library'), href: safeRoute('client.media.index'),       icon: <Image className={iconClass} />,   activePattern: 'client.media.*' },
    ];

    const supportItems = [
        { label: t('nav.support_tickets'), href: safeRoute('client.support.index'), icon: <LifeBuoy className={iconClass} />,   activePattern: 'client.support.*' },
    ];

    if (docsUrl) {
        supportItems.push({ label: t('nav.help_docs'), href: docsUrl, icon: <ExternalLink className={iconClass} />, external: true });
    }

    const contactsItems = [
        { label: t('nav.contacts'),  href: safeRoute('client.contacts.index'),  icon: <Users className={iconClass} />,  activePattern: 'client.contacts.*' },
        { label: t('nav.segments'),  href: safeRoute('client.segments.index'),  icon: <Tag className={iconClass} />,    activePattern: 'client.segments.*' },
    ];

    const messagingItems = [
        { label: t('nav.templates'),     href: safeRoute('client.whatsapp.templates.index'),     icon: whatsappNavIcon, activePattern: 'client.whatsapp.templates.*' },
        { label: t('nav.auto_replies'),  href: safeRoute('client.whatsapp.auto-replies.index'),  icon: whatsappNavIcon, activePattern: 'client.whatsapp.auto-replies.*' },
        { label: t('nav.chat_widget'),   href: safeRoute('client.whatsapp.widget.index'),         icon: whatsappNavIcon, activePattern: 'client.whatsapp.widget.*' },
    ];

    const broadcastItems = [
        { label: t('nav.campaigns'),    href: safeRoute('client.campaigns.index'),    icon: <Radio className={iconClass} />,        activePattern: 'client.campaigns.*' },
        { label: t('nav.sms_gateways'), href: safeRoute('client.sms-gateways.index'), icon: <MessageSquare className={iconClass} />, activePattern: 'client.sms-gateways.*' },
        { label: t('nav.email_server'), href: safeRoute('client.email-server.index'), icon: <Mail className={iconClass} />,          activePattern: 'client.email-server.*' },
    ];

    const inboxItems = [
        { label: t('nav.inbox'),         href: safeRoute('client.inbox.index'), icon: <Inbox className={iconClass} />, activePattern: 'client.inbox.index' },
        { label: t('nav.channel_setup'), href: safeRoute('client.inbox.setup'), icon: <Inbox className={iconClass} />, activePattern: 'client.inbox.setup' },
    ];

    const aiItems = [
        { label: t('nav.chatbots'),        href: safeRoute('client.ai.chatbots.index'),        icon: <Bot className={iconClass} />,      activePattern: 'client.ai.chatbots.*' },
        { label: t('nav.knowledge_bases'), href: safeRoute('client.ai.knowledge-bases.index'), icon: <Database className={iconClass} />, activePattern: 'client.ai.knowledge-bases.*' },
        { label: t('nav.ai_providers'),    href: safeRoute('client.ai.providers.index'),        icon: <Bot className={iconClass} />,      activePattern: 'client.ai.providers.*' },
    ];

    const socialItems = [
        { label: t('nav.post_composer'),   href: safeRoute('client.social.composer'),        icon: <FileText className={iconClass} />,       activePattern: 'client.social.composer' },
        { label: t('nav.posts'),           href: safeRoute('client.social.posts.index'),     icon: <Radio className={iconClass} />,           activePattern: 'client.social.posts.*' },
        { label: t('nav.calendar'),        href: safeRoute('client.social.calendar'),         icon: <LayoutDashboard className={iconClass} />, activePattern: 'client.social.calendar' },
        { label: t('nav.social_accounts'), href: safeRoute('client.social.accounts.index'),  icon: <Share2 className={iconClass} />,          activePattern: 'client.social.accounts.*' },
    ];

    const leadsItems = [
        { label: t('nav.lead_scraper'),  href: safeRoute('client.leads.index'),          icon: <MapPin className={iconClass} />,     activePattern: 'client.leads.index' },
        { label: t('nav.lead_pipeline'), href: safeRoute('client.leads.pipeline.index'), icon: <KanbanSquare className={iconClass} />, activePattern: 'client.leads.pipeline.*' },
    ];

    const automationItems = [
        { label: t('nav.automations'), href: safeRoute('client.automations.index'), icon: <Zap className={iconClass} />, activePattern: 'client.automations.*' },
    ];

    const ecommerceItems = [
        { label: t('nav.orders'),   href: safeRoute('client.ecommerce.orders.index'),   icon: <Package className={iconClass} />,     activePattern: 'client.ecommerce.orders.*' },
        { label: t('nav.products'), href: safeRoute('client.ecommerce.products.index'), icon: <Tag className={iconClass} />,         activePattern: 'client.ecommerce.products.*' },
        { label: t('nav.stores'),   href: safeRoute('client.ecommerce.stores.index'),   icon: <ShoppingBag className={iconClass} />, activePattern: 'client.ecommerce.stores.*' },
    ];

    const reportsItems = [
        { label: t('nav.reports_inbox'),       href: safeRoute('client.reports.inbox.index'),       icon: <Inbox className={iconClass} />,  activePattern: 'client.reports.inbox.*' },
        { label: t('nav.campaigns'),           href: safeRoute('client.reports.campaigns.index'),   icon: <Radio className={iconClass} />,  activePattern: 'client.reports.campaigns.*' },
        { label: t('nav.automations'),         href: safeRoute('client.reports.automations.index'), icon: <Zap className={iconClass} />,    activePattern: 'client.reports.automations.*' },
        { label: t('nav.ai_usage'),            href: safeRoute('client.reports.ai.index'),          icon: <Bot className={iconClass} />,    activePattern: 'client.reports.ai.*' },
        { label: t('nav.social'),              href: safeRoute('client.reports.social.index'),      icon: <Share2 className={iconClass} />, activePattern: 'client.reports.social.*' },
    ];

    // Group order: daily operations first, then growth tools, periodic review, then account-adjacent config (usage-frequency–based).
    return [
        { type: 'group', label: t('nav.group_account'),      items: accountItems },
        { type: 'group', label: t('nav.group_inbox'),         items: inboxItems },
        { type: 'group', label: t('nav.group_social_media'),  items: socialItems },
        { type: 'group', label: t('nav.group_messaging'),     items: messagingItems },
        { type: 'group', label: t('nav.group_contacts'),      items: contactsItems },
        { type: 'group', label: t('nav.group_broadcasting'),  items: broadcastItems },
        { type: 'group', label: t('nav.group_automations'),   items: automationItems },
        { type: 'group', label: t('nav.group_ecommerce'),    items: ecommerceItems },
        { type: 'group', label: t('nav.group_ai'),            items: aiItems },
        { type: 'group', label: t('nav.group_leads'),         items: leadsItems },
        { type: 'group', label: t('nav.group_reports'),       items: reportsItems },
        { type: 'group', label: t('nav.group_support'),       items: supportItems },
        { type: 'group', label: t('nav.group_billing'),       items: billingItems },
        { type: 'group', label: t('nav.group_developer'),     items: developerItems },
        { type: 'group', label: t('nav.group_account'),       items: accountSettingsItems },
    ];
}
