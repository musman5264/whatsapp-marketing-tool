import { Head, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Input, Button } from '@/Components/ui';
import { KeyRound, Loader2 } from 'lucide-react';
import { licenseCopy } from '@/lib/licenseLabels';
import LicenseTypeTabs from '@/Components/LicenseTypeTabs';

export default function LicenseActivate({ reason = null, verify_type = 'non_envato', verify_types = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        license_code: '',
        client_name: '',
        verify_type,
    });
    const copy = licenseCopy(data.verify_type);

    const submit = (e) => {
        e.preventDefault();
        post(route('license.activate'));
    };

    return (
        <AuthLayout
            variant="admin"
            title={copy.envato ? 'Verify your purchase' : 'Activate your license'}
            subtitle="This installation needs a valid license to continue."
            error={reason}
        >
            <Head title="License activation" />

            <form onSubmit={submit} className="space-y-4">
                <LicenseTypeTabs types={verify_types} value={data.verify_type} onChange={(t) => setData('verify_type', t)} />

                <div>
                    <Input
                        label={copy.label}
                        name="license_code"
                        value={data.license_code}
                        onChange={(e) => setData('license_code', e.target.value)}
                        error={errors.license_code}
                        placeholder={copy.placeholder}
                        autoComplete="off"
                        autoFocus
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
                    onChange={(e) => setData('client_name', e.target.value)}
                    error={errors.client_name}
                    placeholder={copy.namePlaceholder}
                    autoComplete="off"
                    required={copy.nameRequired}
                />

                <Button
                    type="submit"
                    variant="primary"
                    className="w-full"
                    disabled={processing || !data.license_code.trim() || (copy.nameRequired && !data.client_name.trim())}
                >
                    {processing ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    ) : (
                        <KeyRound className="mr-2 h-4 w-4" />
                    )}
                    {processing ? copy.activatingLabel : copy.activateLabel}
                </Button>
            </form>
        </AuthLayout>
    );
}
