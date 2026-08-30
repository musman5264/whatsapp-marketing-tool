import { useState } from 'react';
import { Input, Button } from '@/Components/ui';
import { Database, Loader2, CheckCircle2, XCircle } from 'lucide-react';

export default function DatabaseStep({ data, setData, errors, onValidityChange }) {
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState(null);

    // Editing any field invalidates a previous successful test.
    const change = (field) => (e) => {
        setData(field, e.target.value);
        setResult(null);
        onValidityChange(false);
    };

    const canTest = data.db_host && data.db_port && data.db_database && data.db_username;

    const test = async () => {
        setTesting(true);
        setResult(null);
        try {
            const res = await window.axios.post(route('install.test-database'), {
                db_host: data.db_host,
                db_port: data.db_port,
                db_database: data.db_database,
                db_username: data.db_username,
                db_password: data.db_password,
            });
            setResult(res.data);
            onValidityChange(Boolean(res.data?.ok));
        } catch (err) {
            const message =
                err?.response?.data?.message ||
                Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
                'Could not test the connection. Check the fields and try again.';
            setResult({ ok: false, message });
            onValidityChange(false);
        } finally {
            setTesting(false);
        }
    };

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div className="sm:col-span-2">
                    <Input
                        label="Host"
                        name="db_host"
                        value={data.db_host}
                        onChange={change('db_host')}
                        error={errors.db_host}
                    />
                </div>
                <Input
                    label="Port"
                    name="db_port"
                    value={data.db_port}
                    onChange={change('db_port')}
                    error={errors.db_port}
                />
            </div>

            <Input
                label="Database name"
                name="db_database"
                value={data.db_database}
                onChange={change('db_database')}
                error={errors.db_database}
            />

            <Input
                label="Username"
                name="db_username"
                value={data.db_username}
                onChange={change('db_username')}
                error={errors.db_username}
            />

            <Input
                type="password"
                label="Password"
                name="db_password"
                value={data.db_password}
                onChange={change('db_password')}
                error={errors.db_password}
                hint="Leave blank if the database user has no password."
            />

            <div className="flex items-center gap-3 pt-1">
                <Button variant="outline" onClick={test} disabled={testing || !canTest}>
                    {testing ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    ) : (
                        <Database className="mr-2 h-4 w-4" />
                    )}
                    {testing ? 'Testing…' : 'Test connection'}
                </Button>
                {!testing && !result && (
                    <span className="text-xs text-neutral-400 dark:text-neutral-500">
                        Test the connection to continue.
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
                The database must already exist — the installer creates the tables, not the database itself.
            </p>
        </div>
    );
}
