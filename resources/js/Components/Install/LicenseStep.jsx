import { useState } from 'react';
import { Input, Button } from '@/Components/ui';
import { KeyRound, Loader2, CheckCircle2, XCircle } from 'lucide-react';
import { licenseCopy } from '@/lib/licenseLabels';
import LicenseTypeTabs from '@/Components/LicenseTypeTabs';

export default function LicenseStep({ data, setData, errors, onValidityChange, verifyType = 'non_envato', verifyTypes = [] }) {
    const selectedType = data.verify_type || verifyType;
    const copy = licenseCopy(selectedType);
    const [activating, setActivating] = useState(false);
    const [result, setResult] = useState(null);

    const invalidate = () => {
        setResult(null);
        onValidityChange(false);
    };

    // Editing any field (or switching code type) invalidates a prior activation.
    const change = (field) => (e) => {
        setData(field, e.target.value);
        invalidate();
    };

    const selectType = (type) => {
        setData('verify_type', type);
        invalidate();
    };

    const canActivate =
        (data.license_code || '').trim().length > 0 &&
        (!copy.nameRequired || (data.client_name || '').trim().length > 0);

    const activate = async () => {
        setActivating(true);
        setResult(null);
        try {
            const res = await window.axios.post(route('install.activate-license'), {
                license_code: data.license_code,
                client_name: data.client_name,
                verify_type: selectedType,
            });
            setResult(res.data);
            onValidityChange(Boolean(res.data?.ok));
        } catch (err) {
            const message =
                err?.response?.data?.message ||
                Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
                'Could not activate the license. Please try again.';
            setResult({ ok: false, message });
            onValidityChange(false);
        } finally {
            setActivating(false);
        }
    };

    return (
        <div className="space-y-4">
            <LicenseTypeTabs types={verifyTypes} value={selectedType} onChange={selectType} />

            <div>
                <Input
                    label={copy.label}
                    name="license_code"
                    value={data.license_code}
                    onChange={change('license_code')}
                    error={errors.license_code}
                    placeholder={copy.placeholder}
                    autoComplete="off"
                />
                {copy.helpUrl && (
                    <p className="mt-1.5 text-xs text-neutral-500 dark:text-neutral-400">
                        Your purchase code is in your Envato/CodeCanyon account under{' '}
                        <span className="font-medium">Downloads</span>.{' '}
                        <a
                            href={copy.helpUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-brand-600 underline hover:text-brand-700 dark:text-brand-400"
                        >
                            {copy.helpText}
                        </a>
                    </p>
                )}
            </div>

            <Input
                label={copy.nameLabel}
                name="client_name"
                value={data.client_name}
                onChange={change('client_name')}
                error={errors.client_name}
                placeholder={copy.namePlaceholder}
                autoComplete="off"
                required={copy.nameRequired}
            />

            <div className="flex items-center gap-3 pt-1">
                <Button variant="outline" onClick={activate} disabled={activating || !canActivate}>
                    {activating ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    ) : (
                        <KeyRound className="mr-2 h-4 w-4" />
                    )}
                    {activating ? copy.activatingLabel : copy.activateLabel}
                </Button>
                {!activating && !result && (
                    <span className="text-xs text-neutral-400 dark:text-neutral-500">
                        {copy.envato ? 'Verify your purchase code to continue.' : 'Activate your license to continue.'}
                    </span>
                )}
            </div>

            {result && (
                <div
                    className={[
                        'flex items-start gap-2 rounded-lg border px-4 py-3 text-sm',
                        result.ok
                            ? 'border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300'
                            : 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300',
                    ].join(' ')}
                >
                    {result.ok ? (
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                    ) : (
                        <XCircle className="mt-0.5 h-4 w-4 shrink-0" />
                    )}
                    <span>{result.message}</span>
                </div>
            )}

            <p className="text-xs text-neutral-400 dark:text-neutral-500">
                {copy.hint}
            </p>
        </div>
    );
}
