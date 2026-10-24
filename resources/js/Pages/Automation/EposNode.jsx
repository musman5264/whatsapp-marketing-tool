import { createContext, useContext, useState } from 'react';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, Check, Copy, ExternalLink } from 'lucide-react';
import { isCustomerPhoneParam, outputVariables, pickLabel, prefixTemplate } from '@/lib/epos';

/**
 * The linked E-POS systems and what each offers, loaded by the builder after it opens
 * (GET client.automations.epos). `flowVars` are the answers earlier steps save, offered
 * for parameters that come from the chat.
 */
export const EposContext = createContext({ loaded: false, failed: false, integrations: [], flowVars: [] });

const inputCls = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition';
const labelCls = 'block text-xs font-semibold text-gray-600 mb-1';
const noteCls = 'text-xs text-gray-500 leading-relaxed';

const eposResultVar = (d) => ((d.result_var ?? '').trim() || 'epos');

/** The integration and capability a node points at, plus why they may be missing. */
export function resolveEpos(data, integrations) {
    const integration = integrations.find((i) => i.id === data.integration_id) ?? (integrations.length === 1 ? integrations[0] : null);
    const capability = integration?.capabilities.find((c) => c.key === data.capability) ?? null;
    return { integration, capability, gone: Boolean(data.capability && integration && !capability) };
}

/** Labels of the node's two exits: found / no data, or sent / not sent for an action. */
export function eposExitKeys(capability) {
    return capability?.kind === 'action'
        ? ['automation.epos_exit_sent', 'automation.epos_exit_not_sent']
        : ['automation.epos_exit_found', 'automation.epos_exit_nodata'];
}

function Notice({ tone = 'amber', children }) {
    const tones = {
        amber: 'border-amber-200 bg-amber-50 text-amber-900',
        red: 'border-red-200 bg-red-50 text-red-800',
    };
    return (
        <div role="alert" className={`flex items-start gap-2 rounded-lg border p-2.5 text-xs leading-relaxed ${tones[tone]}`}>
            <AlertTriangle size={14} className="mt-0.5 shrink-0" aria-hidden="true" />
            <div>{children}</div>
        </div>
    );
}

function Param({ param, value, onChange, flowVars, lang }) {
    const { t } = useTranslation();
    const id = `epos-param-${param.name}`;
    const title = (
        <label htmlFor={id} className={labelCls}>
            {param.name.replace(/_/g, ' ')}
            {param.optional && <span className="ms-1 font-normal text-gray-400">({t('automation.epos_optional')})</span>}
        </label>
    );

    if (isCustomerPhoneParam(param)) {
        return (
            <div>
                {title}
                <input id={id} className={`${inputCls} bg-gray-50 text-gray-500`} value={t('automation.epos_customer_number')} readOnly disabled aria-describedby={`${id}-hint`} />
                <p id={`${id}-hint`} className={`${noteCls} mt-1`}>{t('automation.epos_customer_number_hint')}</p>
            </div>
        );
    }

    const fromChat = param.from === 'chat_answer';
    const answers = fromChat ? flowVars.map((v) => `{{${v}}}`) : [];

    if (param.type === 'choice') {
        return (
            <div>
                {title}
                <select id={id} className={inputCls} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
                    <option value="">{t('automation.epos_pick_value')}</option>
                    <optgroup label={t('automation.epos_fixed_value')}>
                        {(param.choices ?? []).map((c) => (
                            <option key={c.value} value={c.value}>{pickLabel(c.label, lang) || c.value}</option>
                        ))}
                    </optgroup>
                    {answers.length > 0 && (
                        <optgroup label={t('automation.epos_from_chat')}>
                            {answers.map((a) => <option key={a} value={a}>{a}</option>)}
                        </optgroup>
                    )}
                </select>
                {fromChat && answers.length === 0 && <p className={`${noteCls} mt-1`}>{t('automation.epos_no_answers_yet')}</p>}
            </div>
        );
    }

    return (
        <div>
            {title}
            <input
                id={id}
                className={inputCls}
                type={param.type === 'number' ? 'number' : param.type === 'date' ? 'date' : 'text'}
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
                dir="auto"
            />
            {answers.length > 0 && (
                <select className={`${inputCls} mt-1`} value="" aria-label={t('automation.epos_from_chat')} onChange={(e) => e.target.value && onChange(e.target.value)}>
                    <option value="">{t('automation.epos_from_chat')}…</option>
                    {answers.map((a) => <option key={a} value={a}>{a}</option>)}
                </select>
            )}
        </div>
    );
}

function SuggestedReply({ text }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);
    const copy = () => {
        try {
            navigator.clipboard.writeText(text).then(() => {
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            });
        } catch { /* clipboard blocked: the text is selectable above */ }
    };
    return (
        <div>
            <label className={labelCls} htmlFor="epos-suggested">{t('automation.epos_suggested_reply')}</label>
            <textarea id="epos-suggested" readOnly rows={4} dir="auto" className={`${inputCls} resize-none bg-gray-50 font-mono`} value={text} onFocus={(e) => e.target.select()} />
            <button type="button" onClick={copy} className="mt-1 inline-flex items-center gap-1.5 rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                {copied ? <Check size={12} aria-hidden="true" /> : <Copy size={12} aria-hidden="true" />}
                {copied ? t('automation.epos_copied') : t('automation.epos_copy_reply')}
            </button>
        </div>
    );
}

/** Config panel body of the E-POS node. No URL, header or key anywhere. */
export function EposFields({ d, set, setMany }) {
    const { t, i18n } = useTranslation();
    const { loaded, failed, integrations, flowVars } = useContext(EposContext);
    const lang = i18n.language;

    if (!loaded) return <p className={noteCls} aria-live="polite">{t('automation.epos_loading')}</p>;
    if (failed) return <Notice tone="red">{t('automation.epos_load_failed')}</Notice>;
    if (integrations.length === 0) return <Notice>{t('automation.epos_not_linked')}</Notice>;

    const { integration, capability, gone } = resolveEpos(d, integrations);
    const rv = eposResultVar(d);
    const groups = [...new Set((integration?.capabilities ?? []).map((c) => c.group || ''))];
    const params = d.params ?? {};
    const setParam = (name, value) => set('params', { ...params, [name]: value });

    return (
        <>
            {integration?.status === 'unreachable' && (
                <Notice tone="red">
                    {t('automation.epos_unreachable_banner')}{' '}
                    <Link href={route('client.integrations.epos.index')} className="font-semibold underline">{t('automation.epos_open_status')}</Link>
                </Notice>
            )}

            {integrations.length > 1 && (
                <div>
                    <label className={labelCls} htmlFor="epos-integration">{t('automation.epos_pick_integration')}</label>
                    <select
                        id="epos-integration"
                        className={inputCls}
                        value={d.integration_id ?? ''}
                        onChange={(e) => setMany({ integration_id: Number(e.target.value) || null, capability: '', params: {} })}
                    >
                        <option value="">{t('automation.epos_pick_value')}</option>
                        {integrations.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
                    </select>
                </div>
            )}

            {integration && (
                <div>
                    <label className={labelCls} htmlFor="epos-capability">{t('automation.epos_pick_question')}</label>
                    <select
                        id="epos-capability"
                        className={inputCls}
                        value={capability ? d.capability : ''}
                        onChange={(e) => setMany({ integration_id: integration.id, capability: e.target.value, params: {}, result_var: d.result_var || 'epos' })}
                    >
                        <option value="">{t('automation.epos_question_placeholder')}</option>
                        {groups.map((g) => (
                            <optgroup key={g} label={g}>
                                {integration.capabilities.filter((c) => (c.group || '') === g).map((c) => (
                                    <option key={c.key} value={c.key}>{pickLabel(c.label, lang)}</option>
                                ))}
                            </optgroup>
                        ))}
                    </select>
                    {capability?.description && <p className={`${noteCls} mt-1`} dir="auto">{pickLabel(capability.description, lang)}</p>}
                </div>
            )}

            {gone && <Notice>{t('automation.epos_gone')}</Notice>}

            {capability && (
                <>
                    {capability.kind === 'action' && <p className={noteCls}>{t('automation.epos_action_hint')}</p>}

                    {(capability.params ?? []).map((p) => (
                        <Param key={p.name} param={p} value={params[p.name]} onChange={(v) => setParam(p.name, v)} flowVars={flowVars} lang={lang} />
                    ))}

                    <div>
                        <label className={labelCls} htmlFor="epos-result">{t('automation.epos_save_as')}</label>
                        <input id="epos-result" className={inputCls} value={d.result_var ?? ''} placeholder="epos" onChange={(e) => set('result_var', e.target.value.replace(/\s/g, '_'))} />
                    </div>

                    <p className={noteCls}>{t(capability.kind === 'action' ? 'automation.epos_exits_hint_action' : 'automation.epos_exits_hint_query')}</p>

                    {outputVariables(capability, rv).length > 0 && (
                        <div>
                            <div className={labelCls}>{t('automation.epos_variables')}</div>
                            <ul className="flex flex-wrap gap-1" dir="ltr">
                                {outputVariables(capability, rv).map((v) => (
                                    <li key={v.token} className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-700">
                                        {v.token} <span className="text-gray-400">{v.type}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {capability.summary_template && <SuggestedReply text={prefixTemplate(capability.summary_template, rv)} />}
                </>
            )}

            {integration?.status !== undefined && (
                <Link href={route('client.integrations.epos.index')} className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline">
                    {t('automation.epos_open_status')} <ExternalLink size={11} aria-hidden="true" />
                </Link>
            )}
        </>
    );
}
