/* Helpers for the E-POS node and status page (manifest data published by a linked EPOS). */

/** A manifest label ({en, ur}) in the user's language, falling back to English, then to any text. */
export function pickLabel(label, lang) {
    if (!label) return '';
    if (typeof label === 'string') return label;
    const l = String(lang || 'en').split('-')[0];
    return label[l] || label.en || Object.values(label).find(Boolean) || '';
}

/**
 * A manifest summary_template uses bare names ({{name}}, {{#each orders}}). In a flow the answer lives under
 * the node's result variable, so top-level names get that prefix. Names inside an each-block are item fields
 * and stay as they are.
 */
export function prefixTemplate(template, variable) {
    let depth = 0;
    return String(template ?? '').replace(/\{\{\s*([^{}]+?)\s*\}\}/g, (whole, inner) => {
        if (inner.startsWith('#each ')) {
            const path = inner.slice(6).trim();
            const out = depth === 0 ? `${variable}.${path}` : path;
            depth += 1;
            return `{{#each ${out}}}`;
        }
        if (inner === '/each') {
            depth = Math.max(0, depth - 1);
            return whole;
        }
        if (depth > 0 || inner.startsWith('@') || inner === 'this' || /^(whatsapp|customer|contact|context)\./.test(inner)) {
            return whole;
        }
        return `{{${variable}.${inner}}}`;
    });
}

/**
 * Same rule the server applies: these params are always the chatting customer's own number.
 * The builder shows them read-only; the server ignores anything a flow author maps there.
 */
export function isCustomerPhoneParam(p) {
    return p?.type === 'phone' || p?.from === 'sender_phone' || String(p?.name ?? '').toLowerCase() === 'phone';
}

/** The variables a capability's answer offers to later nodes, e.g. {{epos.name}}. Actions also expose reason. */
export function outputVariables(capability, variable) {
    const list = (capability?.outputs ?? []).map((o) => ({ token: `{{${variable}.${o.path}}}`, type: o.type }));
    if (capability?.kind === 'action' && !(capability.outputs ?? []).some((o) => o.path === 'reason')) {
        list.push({ token: `{{${variable}.reason}}`, type: 'text' });
    }
    return list;
}
