import { Input } from '@/Components/ui';

export default function AdminStep({ data, setData, errors }) {
    const mismatch =
        data.admin_password &&
        data.admin_password_confirmation &&
        data.admin_password !== data.admin_password_confirmation;

    return (
        <div className="space-y-4">
            <Input
                label="Full name"
                name="admin_name"
                value={data.admin_name}
                onChange={(e) => setData('admin_name', e.target.value)}
                error={errors.admin_name}
            />

            <Input
                type="email"
                label="Email address"
                name="admin_email"
                autoComplete="username"
                value={data.admin_email}
                onChange={(e) => setData('admin_email', e.target.value)}
                error={errors.admin_email}
            />

            <Input
                type="password"
                label="Password"
                name="admin_password"
                autoComplete="new-password"
                value={data.admin_password}
                onChange={(e) => setData('admin_password', e.target.value)}
                error={errors.admin_password}
                hint="At least 8 characters."
            />

            <Input
                type="password"
                label="Confirm password"
                name="admin_password_confirmation"
                autoComplete="new-password"
                value={data.admin_password_confirmation}
                onChange={(e) => setData('admin_password_confirmation', e.target.value)}
                error={mismatch ? 'Passwords do not match.' : undefined}
            />
        </div>
    );
}
