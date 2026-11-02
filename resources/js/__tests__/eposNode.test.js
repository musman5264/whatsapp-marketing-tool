import { describe, expect, it } from 'vitest';
import { resolveEpos } from '@/Pages/Automation/EposNode';

const epos = { id: 1, type: 'epos', capabilities: [{ key: 'contact.profile' }] };
const rent = { id: 2, type: 'rentoyee', capabilities: [{ key: 'customer.update_info' }] };

describe('resolveEpos: E-POS and RentOyee are separate steps', () => {
    it('a RentOyee step picks the RentOyee link, an E-POS step the E-POS one, without a saved id', () => {
        expect(resolveEpos({ nodeType: 'rentoyee' }, [epos, rent]).integration).toBe(rent);
        expect(resolveEpos({ nodeType: 'epos' }, [epos, rent]).integration).toBe(epos);
    });

    it('a RentOyee step never falls back to an E-POS link', () => {
        expect(resolveEpos({ nodeType: 'rentoyee' }, [epos]).integration).toBeNull();
    });

    it('a saved id of the other system still resolves (flows from before the split)', () => {
        const r = resolveEpos({ nodeType: 'epos', integration_id: 2, capability: 'customer.update_info' }, [epos, rent]);
        expect(r.integration).toBe(rent);
        expect(r.capability.key).toBe('customer.update_info');
    });
});
