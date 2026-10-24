import { describe, it, expect } from 'vitest';
import { pickLabel, prefixTemplate, isCustomerPhoneParam, outputVariables } from '@/lib/epos';

describe('pickLabel', () => {
    const label = { en: 'My profile', ur: 'میری پروفائل' };

    it('uses the user language when the manifest has it', () => {
        expect(pickLabel(label, 'ur')).toBe('میری پروفائل');
        expect(pickLabel(label, 'ur-PK')).toBe('میری پروفائل');
    });

    it('falls back to English, then to any text', () => {
        expect(pickLabel(label, 'fr')).toBe('My profile');
        expect(pickLabel({ ur: 'x' }, 'fr')).toBe('x');
        expect(pickLabel(null, 'en')).toBe('');
        expect(pickLabel('plain', 'ur')).toBe('plain');
    });
});

describe('prefixTemplate', () => {
    it('puts the result variable in front of top-level fields and each-lists', () => {
        expect(prefixTemplate('Hi {{name}}: {{#each orders}}{{ref}} {{total}}\n{{/each}}Bye', 'epos'))
            .toBe('Hi {{epos.name}}: {{#each epos.orders}}{{ref}} {{total}}\n{{/each}}Bye');
    });

    it('leaves item fields, @n, this and the built-in names alone', () => {
        expect(prefixTemplate('{{#each lines}}{{@n}} {{this}} {{whatsapp.name}}{{/each}} {{contact.first_name}}', 'e'))
            .toBe('{{#each e.lines}}{{@n}} {{this}} {{whatsapp.name}}{{/each}} {{contact.first_name}}');
    });

    it('does not prefix a nested each twice', () => {
        expect(prefixTemplate('{{#each a}}{{#each b}}{{x}}{{/each}}{{/each}}{{y}}', 'e'))
            .toBe('{{#each e.a}}{{#each b}}{{x}}{{/each}}{{/each}}{{e.y}}');
    });

    it('handles empty input', () => {
        expect(prefixTemplate(undefined, 'epos')).toBe('');
    });
});

describe('isCustomerPhoneParam (mirrors the server rule)', () => {
    it('is true for phone type, sender_phone source or a param named phone', () => {
        expect(isCustomerPhoneParam({ name: 'x', type: 'phone', from: 'fixed' })).toBe(true);
        expect(isCustomerPhoneParam({ name: 'x', type: 'text', from: 'sender_phone' })).toBe(true);
        expect(isCustomerPhoneParam({ name: 'Phone', type: 'text', from: 'chat_answer' })).toBe(true);
    });

    it('is false for ordinary params', () => {
        expect(isCustomerPhoneParam({ name: 'period', type: 'choice', from: 'chat_answer' })).toBe(false);
    });
});

describe('outputVariables', () => {
    it('lists each output as a variable under the result name, plus reason for actions', () => {
        const cap = { kind: 'action', outputs: [{ path: 'ok', type: 'boolean' }, { path: 'orders.*.status', type: 'text' }] };
        expect(outputVariables(cap, 'epos')).toEqual([
            { token: '{{epos.ok}}', type: 'boolean' },
            { token: '{{epos.orders.*.status}}', type: 'text' },
            { token: '{{epos.reason}}', type: 'text' },
        ]);
    });

    it('does not repeat reason when the manifest already lists it', () => {
        const cap = { kind: 'action', outputs: [{ path: 'reason', type: 'text' }] };
        expect(outputVariables(cap, 'epos')).toEqual([{ token: '{{epos.reason}}', type: 'text' }]);
    });
});
