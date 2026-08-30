import { Input, Select } from '@/Components/ui';

const ENV_OPTIONS = [
    { value: 'production', label: 'Production' },
    { value: 'local', label: 'Local / development' },
];

export default function AppSettingsStep({ data, setData, errors }) {
    return (
        <div className="space-y-4">
            <Input
                label="Application name"
                name="app_name"
                value={data.app_name}
                onChange={(e) => setData('app_name', e.target.value)}
                error={errors.app_name}
            />

            <Input
                type="url"
                label="Application URL"
                name="app_url"
                value={data.app_url}
                onChange={(e) => setData('app_url', e.target.value)}
                error={errors.app_url}
                hint="The public address where this app is hosted, e.g. https://app.example.com"
            />

            <Select
                label="Environment"
                name="app_env"
                value={data.app_env}
                onChange={(e) => setData('app_env', e.target.value)}
                error={errors.app_env}
                placeholder={null}
                options={ENV_OPTIONS}
            />
        </div>
    );
}
