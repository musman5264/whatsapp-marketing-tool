import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Head, Link } from '@inertiajs/react';
import { BookOpen, ChevronDown, ChevronRight, Key, ShieldCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';

const BASE = '/api/v1';

const SCOPES = [
    { scope: 'contacts:read',       labelKey: 'api.scope_contacts_read' },
    { scope: 'contacts:write',      labelKey: 'api.scope_contacts_write' },
    { scope: 'campaigns:read',      labelKey: 'api.scope_campaigns_read' },
    { scope: 'campaigns:write',     labelKey: 'api.scope_campaigns_write' },
    { scope: 'messages:write',      labelKey: 'api.scope_messages_write' },
    { scope: 'conversations:read',  labelKey: 'api.scope_conversations_read' },
    { scope: 'webhooks:write',      labelKey: 'api.scope_webhooks_write' },
    { scope: 'ai:read',             labelKey: 'api.scope_ai_read' },
    { scope: 'ai:write',            labelKey: 'api.scope_ai_write' },
    { scope: 'automations:write',   labelKey: 'api.scope_automations_write' },
    { scope: 'social:write',        labelKey: 'api.scope_social_write' },
];

const ENDPOINTS = [
    {
        groupKey: 'api.group_authentication',
        items: [
            { method: 'POST', path: `${BASE}/tokens`, descKey: 'api.ep_tokens_create', scope: null, body: '{ "name": "My Token", "abilities": ["contacts:read"], "expires_at": "2027-01-01" }', response: '{ "id": 1, "token": "1|abc...", "abilities": ["contacts:read"] }' },
            { method: 'GET', path: `${BASE}/tokens`, descKey: 'api.ep_tokens_list', scope: null },
            { method: 'DELETE', path: `${BASE}/tokens/{id}`, descKey: 'api.ep_tokens_revoke', scope: null },
        ],
    },
    {
        groupKey: 'api.group_contacts',
        items: [
            { method: 'GET', path: `${BASE}/contacts`, descKey: 'api.ep_contacts_list', scope: 'contacts:read' },
            { method: 'POST', path: `${BASE}/contacts`, descKey: 'api.ep_contacts_create', scope: 'contacts:write', body: '{ "phone_e164": "+8801700000001", "first_name": "Rahim", "opt_in_whatsapp": true }' },
            { method: 'GET', path: `${BASE}/contacts/{id}`, descKey: 'api.ep_contacts_get', scope: 'contacts:read' },
            { method: 'PATCH', path: `${BASE}/contacts/{id}`, descKey: 'api.ep_contacts_update', scope: 'contacts:write' },
            { method: 'DELETE', path: `${BASE}/contacts/{id}`, descKey: 'api.ep_contacts_delete', scope: 'contacts:write' },
        ],
    },
    {
        groupKey: 'api.group_segments',
        items: [
            { method: 'GET', path: `${BASE}/segments`, descKey: 'api.ep_segments_list', scope: 'contacts:read' },
            { method: 'POST', path: `${BASE}/segments`, descKey: 'api.ep_segments_create', scope: 'contacts:write', body: '{ "name": "VIP", "type": "static" }' },
            { method: 'GET', path: `${BASE}/segments/{id}/contacts`, descKey: 'api.ep_segments_members', scope: 'contacts:read' },
        ],
    },
    {
        groupKey: 'api.group_campaigns',
        items: [
            { method: 'GET', path: `${BASE}/campaigns`, descKey: 'api.ep_campaigns_list', scope: 'campaigns:read' },
            { method: 'POST', path: `${BASE}/campaigns`, descKey: 'api.ep_campaigns_create', scope: 'campaigns:write', body: '{ "name": "Flash Sale", "channel": "whatsapp", "template_ref": {...} }' },
            { method: 'GET', path: `${BASE}/campaigns/{id}`, descKey: 'api.ep_campaigns_get', scope: 'campaigns:read' },
            { method: 'POST', path: `${BASE}/campaigns/{id}/launch`, descKey: 'api.ep_campaigns_launch', scope: 'campaigns:write' },
            { method: 'POST', path: `${BASE}/campaigns/{id}/pause`, descKey: 'api.ep_campaigns_pause', scope: 'campaigns:write' },
            { method: 'GET', path: `${BASE}/campaigns/{id}/recipients`, descKey: 'api.ep_campaigns_recipients', scope: 'campaigns:read' },
        ],
    },
    {
        groupKey: 'api.group_messages',
        items: [
            { method: 'POST', path: `${BASE}/messages/send`, descKey: 'api.ep_messages_send', scope: 'messages:write', body: '{ "contact_id": 42, "channel": "whatsapp", "template_name": "order_confirmation", "template_vars": {"1": "ORD-99"} }', response: '{ "provider_message_id": "wamid.xxx", "status": "sent" }' },
        ],
    },
    {
        groupKey: 'api.group_conversations',
        items: [
            { method: 'GET', path: `${BASE}/conversations`, descKey: 'api.ep_conversations_list', scope: 'conversations:read' },
            { method: 'GET', path: `${BASE}/conversations/{id}/messages`, descKey: 'api.ep_conversations_messages', scope: 'conversations:read' },
        ],
    },
    {
        groupKey: 'api.group_webhooks',
        items: [
            { method: 'GET', path: `${BASE}/webhooks`, descKey: 'api.ep_webhooks_list', scope: 'webhooks:write' },
            { method: 'POST', path: `${BASE}/webhooks`, descKey: 'api.ep_webhooks_create', scope: 'webhooks:write', body: '{ "url": "https://yourapp.com/hook", "events": ["contact.created", "campaign.completed"] }', response: '{ "id": 1, "secret": "whsec_..." }' },
            { method: 'DELETE', path: `${BASE}/webhooks/{id}`, descKey: 'api.ep_webhooks_delete', scope: 'webhooks:write' },
        ],
    },
    {
        groupKey: 'api.group_ai_chatbots',
        items: [
            { method: 'GET', path: `${BASE}/ai/chatbots`, descKey: 'api.ep_ai_chatbots_list', scope: 'ai:read' },
            { method: 'POST', path: `${BASE}/ai/chatbots/{id}/chat`, descKey: 'api.ep_ai_chatbots_chat', scope: 'ai:write', body: '{ "message": "What is your refund policy?", "history": [] }', response: '{ "reply": "Our refund policy is 30 days.", "tokens_used": 312 }' },
        ],
    },
    {
        groupKey: 'api.group_ai_knowledge_bases',
        items: [
            { method: 'GET', path: `${BASE}/ai/knowledge-bases`, descKey: 'api.ep_kb_list', scope: 'ai:read' },
            { method: 'POST', path: `${BASE}/ai/knowledge-bases`, descKey: 'api.ep_kb_create', scope: 'ai:write', body: '{ "name": "Help Center" }' },
            { method: 'GET', path: `${BASE}/ai/knowledge-bases/{id}`, descKey: 'api.ep_kb_get', scope: 'ai:read' },
            { method: 'POST', path: `${BASE}/ai/knowledge-bases/{id}/documents`, descKey: 'api.ep_kb_add_doc', scope: 'ai:write', body: '{ "source_type": "url", "source_ref": "https://example.com/faq" }' },
            { method: 'DELETE', path: `${BASE}/ai/knowledge-bases/{kbId}/documents/{docId}`, descKey: 'api.ep_kb_remove_doc', scope: 'ai:write' },
        ],
    },
    {
        groupKey: 'api.group_automations',
        items: [
            { method: 'GET', path: `${BASE}/automations`, descKey: 'api.ep_automations_list', scope: 'automations:write' },
            { method: 'POST', path: `${BASE}/automations/{id}/trigger`, descKey: 'api.ep_automations_trigger', scope: 'automations:write', body: '{ "contact_id": 42 }', response: '{ "run_id": 1, "status": "pending" }' },
        ],
    },
    {
        groupKey: 'api.group_social',
        items: [
            { method: 'GET', path: `${BASE}/social/accounts`, descKey: 'api.ep_social_accounts', scope: 'social:write' },
            { method: 'POST', path: `${BASE}/social/posts`, descKey: 'api.ep_social_posts', scope: 'social:write', body: '{ "body": "Check out our sale!", "account_ids": [1, 2], "scheduled_at": "2026-05-01T10:00:00Z" }' },
        ],
    },
];

// HTTP method badges use industry-standard semantic colors — intentional
const METHOD_COLORS = {
    GET:    'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    POST:   'bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-400',
    PATCH:  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    PUT:    'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    DELETE: 'bg-coral-100 text-coral-700 dark:bg-coral-900/30 dark:text-coral-400',
};

function EndpointRow({ ep }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const hasDetail = ep.body || ep.response || ep.scope;

    return (
        <div className="bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 overflow-hidden">
            <button
                onClick={() => hasDetail && setOpen(v => !v)}
                className="w-full flex items-center gap-3 p-4 text-left hover:bg-neutral-50 dark:hover:bg-neutral-700/30 transition duration-150"
            >
                <span className={`shrink-0 px-2 py-0.5 rounded text-xs font-bold font-mono ${METHOD_COLORS[ep.method] ?? ''}`}>
                    {ep.method}
                </span>
                <code className="flex-1 text-sm font-mono text-neutral-700 dark:text-neutral-300 break-all">{ep.path}</code>
                {ep.scope && (
                    <span className="shrink-0 hidden sm:inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-brand-50 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 rounded-full font-mono border border-brand-100 dark:border-brand-800">
                        <ShieldCheck className="h-3 w-3" />{ep.scope}
                    </span>
                )}
                {hasDetail && (
                    open
                        ? <ChevronDown className="h-4 w-4 text-neutral-400 shrink-0" />
                        : <ChevronRight className="h-4 w-4 text-neutral-400 shrink-0" />
                )}
            </button>
            {open && (
                <div className="border-t border-neutral-100 dark:border-neutral-700 px-4 pb-4 pt-3 space-y-3">
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">{t(ep.descKey)}</p>
                    {ep.body && (
                        <div>
                            <p className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 mb-1">{t('api.request_body')}</p>
                            <pre className="text-xs bg-neutral-50 dark:bg-neutral-900 p-3 rounded-soft-lg overflow-x-auto text-neutral-700 dark:text-neutral-300 border border-neutral-200 dark:border-neutral-700">{ep.body}</pre>
                        </div>
                    )}
                    {ep.response && (
                        <div>
                            <p className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 mb-1">{t('api.response')}</p>
                            <pre className="text-xs bg-neutral-50 dark:bg-neutral-900 p-3 rounded-soft-lg overflow-x-auto text-neutral-700 dark:text-neutral-300 border border-neutral-200 dark:border-neutral-700">{ep.response}</pre>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

export default function ApiDocs() {
    const { t } = useTranslation();
    const [tab, setTab] = useState('endpoints');

    return (
        <ClientLayout title={t('api.docs_title')}>
            <Head title={t('api.docs_title')} />
            <div className="space-y-6 max-w-4xl">
                {/* Header */}
                <div className="flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <span className="flex h-10 w-10 items-center justify-center rounded-soft-lg bg-brand-50 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400">
                            <BookOpen className="h-5 w-5" />
                        </span>
                        <div>
                            <h1 className="text-xl font-bold text-neutral-900 dark:text-white">{t('api.rest_docs')}</h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                {t('api.base_url')}: <code className="font-mono text-brand-600 dark:text-brand-400">{window.location.origin}/api/v1</code>
                            </p>
                        </div>
                    </div>
                    <Link
                        href={route('client.api-tokens.index')}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm bg-brand-500 text-white rounded-soft hover:bg-brand-600 shadow-soft transition-all duration-150"
                    >
                        <Key className="h-4 w-4" /> {t('api.manage_tokens')}
                    </Link>
                </div>

                {/* Tabs */}
                <div className="border-b border-neutral-200 dark:border-neutral-700">
                    <nav className="-mb-px flex gap-4">
                        {[
                            { key: 'endpoints', labelKey: 'api.tab_endpoints' },
                            { key: 'scopes', labelKey: 'api.tab_scopes' },
                            { key: 'quickstart', labelKey: 'api.tab_quickstart' },
                        ].map(({ key: tabKey, labelKey }) => (
                            <button
                                key={tabKey}
                                onClick={() => setTab(tabKey)}
                                className={`pb-2 text-sm font-medium border-b-2 transition-colors duration-150 ${
                                    tab === tabKey
                                        ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400'
                                        : 'border-transparent text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300'
                                }`}
                            >
                                {t(labelKey)}
                            </button>
                        ))}
                    </nav>
                </div>

                {tab === 'endpoints' && (
                    <div className="space-y-8">
                        {ENDPOINTS.map(section => (
                            <div key={section.groupKey}>
                                <h2 className="text-base font-semibold text-neutral-900 dark:text-white mb-3">{t(section.groupKey)}</h2>
                                <div className="space-y-2">
                                    {section.items.map((ep, i) => <EndpointRow key={i} ep={ep} />)}
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {tab === 'scopes' && (
                    <div className="space-y-4">
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">
                            {t('api.scopes_intro_before')} <code className="font-mono text-xs">*</code> {t('api.scopes_intro_after')}
                        </p>
                        <div className="bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 overflow-hidden">
                            <table className="w-full text-sm">
                                <thead className="bg-neutral-50 dark:bg-neutral-700/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500">{t('api.col_scope')}</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-neutral-500">{t('api.col_grants')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700">
                                    {SCOPES.map(({ scope, labelKey }) => (
                                        <tr key={scope}>
                                            <td className="px-4 py-3 font-mono text-xs text-brand-600 dark:text-brand-400">{scope}</td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400 text-xs">{t(labelKey)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {tab === 'quickstart' && (
                    <div className="space-y-4">
                        {[
                            { stepKey: 'api.quickstart_step_authenticate', code: `curl -H "Authorization: Bearer <your_token>" \\\n     -H "Accept: application/json" \\\n     ${window.location.origin}/api/v1/me` },
                            { stepKey: 'api.quickstart_step_create_contact', code: `curl -X POST \\\n     -H "Authorization: Bearer <token>" \\\n     -H "Content-Type: application/json" \\\n     -d '{"phone_e164":"+8801700000001","first_name":"Rahim","opt_in_whatsapp":true}' \\\n     ${window.location.origin}/api/v1/contacts` },
                            { stepKey: 'api.quickstart_step_send_message', code: `curl -X POST \\\n     -H "Authorization: Bearer <token>" \\\n     -H "Content-Type: application/json" \\\n     -d '{"contact_id":42,"channel":"whatsapp","body":"Hello from the API!"}' \\\n     ${window.location.origin}/api/v1/messages/send` },
                            { stepKey: 'api.quickstart_step_subscribe_webhook', code: `curl -X POST \\\n     -H "Authorization: Bearer <token>" \\\n     -H "Content-Type: application/json" \\\n     -d '{"url":"https://yourapp.com/webhook","events":["contact.created","campaign.completed"]}' \\\n     ${window.location.origin}/api/v1/webhooks` },
                        ].map(({ stepKey, code }) => (
                            <div key={stepKey} className="bg-neutral-50 dark:bg-neutral-800/50 rounded-xl border border-neutral-200 dark:border-neutral-700 p-4">
                                <h3 className="text-sm font-semibold text-neutral-700 dark:text-neutral-300 mb-2">{t(stepKey)}</h3>
                                <pre className="text-xs text-neutral-700 dark:text-neutral-300 bg-white dark:bg-neutral-900 p-3 rounded-soft-lg overflow-x-auto border border-neutral-200 dark:border-neutral-700">{code}</pre>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </ClientLayout>
    );
}
