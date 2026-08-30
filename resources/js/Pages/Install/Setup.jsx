import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import InstallLayout from '@/Layouts/InstallLayout';
import { Button } from '@/Components/ui';
import { ListChecks, KeyRound, AppWindow, Database, ShieldCheck, Rocket, ArrowLeft, ArrowRight, Loader2 } from 'lucide-react';
import RequirementsStep from '@/Components/Install/RequirementsStep';
import LicenseStep from '@/Components/Install/LicenseStep';
import AppSettingsStep from '@/Components/Install/AppSettingsStep';
import DatabaseStep from '@/Components/Install/DatabaseStep';
import AdminStep from '@/Components/Install/AdminStep';
import SeedStep from '@/Components/Install/SeedStep';
import { licenseCopy } from '@/lib/licenseLabels';

export default function Setup({ requirements, defaults, licensing = { enabled: false } }) {
    const { data, setData, post, processing, errors } = useForm({
        license_code: '',
        client_name: '',
        verify_type: licensing.verify_type || 'non_envato',
        app_name: defaults.app_name || '',
        app_url: defaults.app_url || '',
        app_env: defaults.app_env || 'production',
        db_host: defaults.db_host || '127.0.0.1',
        db_port: defaults.db_port || '3306',
        db_database: defaults.db_database || '',
        db_username: defaults.db_username || '',
        db_password: '',
        admin_name: '',
        admin_email: '',
        admin_password: '',
        admin_password_confirmation: '',
        import_demo: false,
    });

    const [step, setStep] = useState(0);
    const [dbOk, setDbOk] = useState(false);
    const [licenseOk, setLicenseOk] = useState(false);

    const licenseUi = licenseCopy(data.verify_type);

    // Steps are built dynamically so the License step only appears when the
    // distribution is configured for license verification.
    const steps = [
        { key: 'requirements', label: 'Requirements', desc: 'Check your server', icon: ListChecks, title: 'Server requirements', subtitle: 'Make sure your server meets the requirements below.' },
        licensing.enabled && { key: 'license', label: licenseUi.stepLabel, desc: licenseUi.stepDesc, icon: KeyRound, title: licenseUi.stepTitle, subtitle: licenseUi.stepSubtitle },
        { key: 'application', label: 'Application', desc: 'Name & address', icon: AppWindow, title: 'Application settings', subtitle: 'Basic information about your installation.' },
        { key: 'database', label: 'Database', desc: 'Connect your database', icon: Database, title: 'Database connection', subtitle: 'Enter your database credentials and test the connection.' },
        { key: 'admin', label: 'Admin', desc: 'Create your account', icon: ShieldCheck, title: 'Administrator account', subtitle: 'Create the super-admin you will sign in with.' },
        { key: 'finish', label: 'Finish', desc: 'Seed & install', icon: Rocket, title: 'Confirm & install', subtitle: 'Choose what to seed, then start the installation.' },
    ].filter(Boolean);

    const current = steps[step];
    const isLast = step === steps.length - 1;

    const canAdvance = () => {
        switch (current.key) {
            case 'requirements':
                return requirements.ok;
            case 'license':
                return licenseOk;
            case 'application':
                return data.app_name.trim() && data.app_url.trim();
            case 'database':
                return dbOk;
            case 'admin':
                return (
                    data.admin_name.trim() &&
                    data.admin_email.trim() &&
                    data.admin_password.length >= 8 &&
                    data.admin_password === data.admin_password_confirmation
                );
            default:
                return true;
        }
    };

    const back = () => setStep((s) => Math.max(0, s - 1));
    const next = () => setStep((s) => Math.min(steps.length - 1, s + 1));
    const submit = () => post(route('install.run'));

    const errorList = Object.values(errors).filter(Boolean);

    return (
        <InstallLayout
            steps={steps}
            current={step}
            title={current.title}
            subtitle={current.subtitle}
        >
            <Head title="Install" />

            {current.key === 'requirements' && <RequirementsStep requirements={requirements} />}
            {current.key === 'license' && (
                <LicenseStep data={data} setData={setData} errors={errors} onValidityChange={setLicenseOk} verifyType={licensing.verify_type} verifyTypes={licensing.verify_types} />
            )}
            {current.key === 'application' && <AppSettingsStep data={data} setData={setData} errors={errors} />}
            {current.key === 'database' && (
                <DatabaseStep data={data} setData={setData} errors={errors} onValidityChange={setDbOk} />
            )}
            {current.key === 'admin' && <AdminStep data={data} setData={setData} errors={errors} />}
            {current.key === 'finish' && <SeedStep data={data} setData={setData} />}

            {isLast && errorList.length > 0 && (
                <div className="mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                    <p className="font-medium">Installation could not complete:</p>
                    <ul className="mt-1 list-inside list-disc space-y-0.5">
                        {errorList.map((message, i) => (
                            <li key={i}>{message}</li>
                        ))}
                    </ul>
                </div>
            )}

            {isLast && processing && (
                <div className="mt-5 flex items-start gap-2 rounded-lg border border-brand-200 bg-brand-50/60 px-4 py-3 text-sm text-brand-800 dark:border-brand-800 dark:bg-brand-900/10 dark:text-brand-300">
                    <Loader2 className="mt-0.5 h-4 w-4 shrink-0 animate-spin" />
                    <span>
                        Running migrations and seeding data. This can take a minute — please don't
                        close this window.
                    </span>
                </div>
            )}

            <div className="mt-8 flex items-center justify-between border-t border-neutral-100 pt-5 dark:border-neutral-800">
                <Button variant="ghost" onClick={back} disabled={step === 0 || processing}>
                    <ArrowLeft className="mr-2 h-4 w-4" /> Back
                </Button>

                {isLast ? (
                    <Button variant="primary" onClick={submit} disabled={processing}>
                        {processing ? (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        ) : (
                            <Rocket className="mr-2 h-4 w-4" />
                        )}
                        {processing ? 'Installing…' : 'Install now'}
                    </Button>
                ) : (
                    <Button variant="primary" onClick={next} disabled={!canAdvance()}>
                        Next <ArrowRight className="ml-2 h-4 w-4" />
                    </Button>
                )}
            </div>
        </InstallLayout>
    );
}
