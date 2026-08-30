/**
 * Copy for the license/activation UI, adapted to the configured verify_type.
 * 'envato' → buyers enter their Envato/CodeCanyon purchase code; otherwise a
 * generic license code.
 */
export const ENVATO_PURCHASE_CODE_HELP_URL =
    'https://help.market.envato.com/hc/en-us/articles/202822600-Where-Is-My-Purchase-Code';

/** Short label for a verify type, used in the code-type chooser. */
export function typeLabel(type) {
    if (type === 'envato') return 'Envato purchase code';
    if (type === 'gumroad') return 'Gumroad license';
    return 'License code';
}

export function licenseCopy(verifyType) {
    const envato = verifyType === 'envato';

    return {
        envato,
        label: envato ? 'Envato purchase code' : 'License code',
        placeholder: envato
            ? 'e.g. 8f9c1e2a-4b6d-4f3a-9c7e-1a2b3c4d5e6f'
            : 'e.g. XXXX-XXXX-XXXX-XXXX',
        hint: envato
            ? 'Find your purchase code in your Envato account: Downloads → this item → License certificate & purchase code.'
            : 'Your license code was sent with your purchase. Activation registers this installation with the license server.',
        // How/where to find the purchase code (Envato only).
        helpUrl: envato ? ENVATO_PURCHASE_CODE_HELP_URL : null,
        helpText: 'Where do I find my purchase code?',
        // Buyer/name field.
        nameLabel: envato ? 'Envato Buyer Name' : 'Your name (optional)',
        namePlaceholder: envato
            ? 'The Envato username the item was purchased with'
            : 'The name your license was issued to',
        nameRequired: envato,
        activateLabel: envato ? 'Verify & activate' : 'Activate license',
        activatingLabel: envato ? 'Verifying…' : 'Activating…',
        stepLabel: 'License',
        stepDesc: envato ? 'Verify your purchase' : 'Activate your license',
        stepTitle: envato ? 'Verify your purchase' : 'License activation',
        stepSubtitle: envato
            ? 'Enter your Envato purchase code to activate this installation.'
            : 'Enter your license code to activate this installation.',
    };
}
