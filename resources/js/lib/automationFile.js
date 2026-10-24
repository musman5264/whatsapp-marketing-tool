/* Export of an automation to a file (JSON to import again, Markdown to read) and the helpers around it. */

export const EXPORT_FORMAT = 'whatsmine.automation';
export const EXPORT_VERSION = 1;

const SECRET_HEADER = /authori[sz]ation|token|secret|password|api[-_]?key|cookie/i;
const SKIP_KEYS = new Set(['nodeType', 'configured', 'label', 'triggerType']);

/**
 * An API Call step can carry a key in its headers, and the file may be sent to someone else.
 * Header values whose name looks secret are replaced with REDACTED (templates like {{token}} are kept).
 */
export function redactSecrets(data) {
    if (!data || typeof data !== 'object' || data.headers == null) return data;
    const asText = typeof data.headers === 'string';
    let headers = data.headers;
    if (asText) {
        try { headers = JSON.parse(data.headers); } catch { return data; }
    }
    if (!headers || typeof headers !== 'object') return data;
    const clean = Object.fromEntries(Object.entries(headers).map(([k, v]) => [
        k,
        SECRET_HEADER.test(k) && !String(v).includes('{{') ? 'REDACTED' : v,
    ]));
    return { ...data, headers: asText ? JSON.stringify(clean) : clean };
}

/** The file for the Export > JSON choice. Built from what is on screen, so unsaved edits are included. */
export function buildExport(automation, nodes, edges, now = new Date()) {
    return {
        format: EXPORT_FORMAT,
        version: EXPORT_VERSION,
        exported_at: now.toISOString(),
        automation: {
            name: automation.name,
            trigger_type: automation.trigger_type ?? null,
            trigger_config: automation.trigger_config ?? {},
            nodes: nodes.map((n) => ({ id: n.id, type: n.data?.nodeType ?? n.type, position: n.position, data: redactSecrets(n.data ?? {}) })),
            edges: edges.map((e) => ({ id: e.id, source: e.source, target: e.target, sourceHandle: e.sourceHandle ?? null, targetHandle: e.targetHandle ?? null })),
        },
    };
}

export function fileSafeName(name) {
    return String(name ?? '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'automation';
}

export function downloadText(filename, text, mime) {
    const url = URL.createObjectURL(new Blob([text], { type: `${mime};charset=utf-8` }));
    const a = Object.assign(document.createElement('a'), { href: url, download: filename });
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

const clip = (s, n = 300) => (s.length > n ? `${s.slice(0, n)}…` : s);

function detailLines(data) {
    return Object.entries(data ?? {})
        .filter(([k, v]) => !SKIP_KEYS.has(k) && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0))
        .map(([k, v]) => `- ${k}: ${clip(typeof v === 'string' ? v.replace(/\s*\n\s*/g, ' ⏎ ') : JSON.stringify(v))}`);
}

function branchName(type, handle, t) {
    if (handle === 'true') return type === 'epos' ? t.found : t.yes;
    if (handle === 'false') return type === 'epos' ? t.noData : t.no;
    return t.next;
}

/**
 * A readable Markdown description of the flow: trigger, then every step in the order the flow reaches it with
 * its settings and where each branch goes, then any steps that are not connected. Built from the export object,
 * so secrets are already hidden. `labelFor(type)` gives the step's display name.
 */
export function flowToMarkdown(exported, labelFor) {
    const { name, trigger_type: trigger, trigger_config: cfg = {}, nodes, edges } = exported.automation;
    const t = { yes: 'Yes', no: 'No', found: 'Found / sent', noData: 'No data / not sent', next: 'Next' };
    const byId = new Map(nodes.map((n) => [n.id, n]));
    const out = (id) => edges.filter((e) => e.source === id);

    const triggerNode = nodes.find((n) => n.type === 'trigger' || n.type === 'triggerNode' || n.data?.triggerType);
    const order = [];
    const seen = new Set(triggerNode ? [triggerNode.id] : []);
    const walk = (id) => {
        if (seen.has(id) || !byId.has(id)) return;
        seen.add(id);
        order.push(id);
        out(id).forEach((e) => walk(e.target));
    };
    if (triggerNode) out(triggerNode.id).forEach((e) => walk(e.target));
    const number = new Map(order.map((id, i) => [id, i + 1]));
    const title = (n) => {
        const base = labelFor(n.type);
        return n.data?.label && n.data.label !== base ? `${base} — ${n.data.label}` : base;
    };

    const lines = [`# ${name}`, '', `- Trigger: \`${trigger ?? 'none'}\``];
    if ((cfg.keywords ?? []).length) lines.push(`- Keyword filter: ${cfg.keywords.map((k) => `\`${k}\``).join(', ')} (match: ${cfg.match_mode ?? 'contains'})`);
    lines.push(`- Exported: ${exported.exported_at}`, '', '## Steps', '');

    const describe = (n, heading) => {
        lines.push(heading, '', ...detailLines(n.data));
        out(n.id).forEach((e) => {
            const target = byId.get(e.target);
            const where = number.has(e.target) ? `step ${number.get(e.target)}: ${title(target)}` : (target ? title(target) : 'missing step');
            lines.push(`- ${branchName(n.type, e.sourceHandle, t)} → ${where}`);
        });
        lines.push('');
    };

    if (!order.length) lines.push('_No steps are connected to the trigger._', '');
    order.forEach((id) => describe(byId.get(id), `### ${number.get(id)}. ${title(byId.get(id))}`));

    const loose = nodes.filter((n) => n !== triggerNode && !number.has(n.id));
    if (loose.length) {
        lines.push('## Not connected', '');
        loose.forEach((n) => describe(n, `### ${title(n)}`));
    }
    return `${lines.join('\n').trimEnd()}\n`;
}
