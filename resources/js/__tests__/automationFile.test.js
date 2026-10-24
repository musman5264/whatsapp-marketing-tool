import { describe, it, expect } from 'vitest';
import { buildExport, flowToMarkdown, fileSafeName, redactSecrets } from '@/lib/automationFile';

const automation = { name: 'Menu flow', trigger_type: 'message.received', trigger_config: { keywords: ['m'], match_mode: 'equals' }, trigger_token: 'secret-token' };
const nodes = [
    { id: 'trigger-1', type: 'triggerNode', position: { x: 0, y: 0 }, data: { triggerType: 'message.received' } },
    { id: 'q1', type: 'automationNode', position: { x: 0, y: 100 }, data: { nodeType: 'ask_question', question: 'Pick one', variable: 'user', configured: true } },
    { id: 'c1', type: 'automationNode', position: { x: 0, y: 200 }, data: { nodeType: 'condition', field: 'user', operator: 'equals', value: '1', label: 'Profile check' } },
    { id: 'w1', type: 'automationNode', position: { x: 0, y: 300 }, data: { nodeType: 'webhook', url: 'https://x.test', headers: '{"Authorization":"Bearer abc123","Accept":"json"}' } },
    { id: 'lonely', type: 'automationNode', position: { x: 300, y: 300 }, data: { nodeType: 'wait', amount: 5, unit: 'minutes' } },
];
const edges = [
    { id: 'e1', source: 'trigger-1', target: 'q1' },
    { id: 'e2', source: 'q1', target: 'c1' },
    { id: 'e3', source: 'c1', target: 'w1', sourceHandle: 'true' },
];
const labelFor = (type) => ({ ask_question: 'Ask Question', condition: 'Condition', webhook: 'API Call', wait: 'Wait' }[type] ?? type);

describe('buildExport', () => {
    const out = buildExport(automation, nodes, edges, new Date('2026-10-24T12:00:00Z'));

    it('has the format marker, version and the flow', () => {
        expect(out.format).toBe('whatsmine.automation');
        expect(out.version).toBe(1);
        expect(out.exported_at).toBe('2026-10-24T12:00:00.000Z');
        expect(out.automation.name).toBe('Menu flow');
        expect(out.automation.trigger_config.keywords).toEqual(['m']);
        expect(out.automation.nodes).toHaveLength(5);
        expect(out.automation.edges).toHaveLength(3);
    });

    it('never carries the trigger token', () => {
        expect(JSON.stringify(out)).not.toContain('secret-token');
    });

    it('blanks secret header values but keeps harmless ones', () => {
        const hook = out.automation.nodes.find((n) => n.id === 'w1');
        const headers = JSON.parse(hook.data.headers);
        expect(headers.Authorization).toBe('REDACTED');
        expect(headers.Accept).toBe('json');
        expect(JSON.stringify(out)).not.toContain('abc123');
    });
});

describe('redactSecrets', () => {
    it('handles headers given as an object and leaves other nodes alone', () => {
        expect(redactSecrets({ nodeType: 'webhook', headers: { 'X-Api-Key': 'k', Accept: 'a' } }).headers).toEqual({ 'X-Api-Key': 'REDACTED', Accept: 'a' });
        const wait = { nodeType: 'wait', amount: 5 };
        expect(redactSecrets(wait)).toEqual(wait);
    });

    it('leaves headers that are not valid JSON untouched', () => {
        expect(redactSecrets({ nodeType: 'webhook', headers: 'not json' }).headers).toBe('not json');
    });
});

describe('flowToMarkdown', () => {
    const md = flowToMarkdown(buildExport(automation, nodes, edges, new Date('2026-10-24T12:00:00Z')), labelFor);

    it('has the title, trigger and keyword filter', () => {
        expect(md).toContain('# Menu flow');
        expect(md).toContain('message.received');
        expect(md).toContain('m');
        expect(md).toContain('equals');
    });

    it('lists the steps in flow order with their settings', () => {
        const ask = md.indexOf('Ask Question');
        const cond = md.indexOf('Profile check');
        const hook = md.indexOf('API Call');
        expect(ask).toBeGreaterThan(-1);
        expect(cond).toBeGreaterThan(ask);
        expect(hook).toBeGreaterThan(cond);
        expect(md).toContain('Pick one');
        expect(md).toContain('variable: user');
    });

    it('shows where each branch of a condition goes', () => {
        expect(md).toMatch(/Yes.*API Call/s);
    });

    it('lists steps that are not connected, and hides secrets', () => {
        expect(md).toContain('Not connected');
        expect(md).toContain('Wait');
        expect(md).not.toContain('abc123');
    });
});

describe('fileSafeName', () => {
    it('makes a safe file name', () => {
        expect(fileSafeName('Menu / flow: "A"')).toBe('menu-flow-a');
        expect(fileSafeName('')).toBe('automation');
        expect(fileSafeName('مینو')).toBe('automation');
    });
});
