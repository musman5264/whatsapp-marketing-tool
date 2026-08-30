<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Services\Install\InstallerService;
use App\Services\License\LicenseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class InstallController extends Controller
{
    public function __construct(
        private InstallerService $installer,
        private LicenseManager $license,
    ) {}

    /** Render the setup wizard, or bounce away if the app is already installed. */
    public function show(): InertiaResponse|RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            return redirect()->route('admin.login');
        }

        return Inertia::render('Install/Setup', [
            'requirements' => $this->installer->requirements(),
            'licensing' => [
                'enabled' => $this->license->enabled(),
                'verify_type' => $this->license->defaultVerifyType(),
                'verify_types' => $this->license->verifyTypes(),
            ],
            'defaults' => [
                'app_name' => config('app.name'),
                'app_url' => config('app.url', 'http://localhost'),
                'app_env' => config('app.env', 'production'),
                'db_host' => (string) config('database.connections.mysql.host', '127.0.0.1'),
                'db_port' => (string) config('database.connections.mysql.port', '3306'),
                'db_database' => (string) config('database.connections.mysql.database', ''),
                'db_username' => (string) config('database.connections.mysql.username', ''),
            ],
        ]);
    }

    /** Live connection check for the "Test connection" button (JSON). */
    public function testDatabase(Request $request): JsonResponse
    {
        if ($this->installer->isInstalled()) {
            abort(404);
        }

        $data = $request->validate([
            'db_host' => ['required', 'string'],
            'db_port' => ['required'],
            'db_database' => ['required', 'string'],
            'db_username' => ['required', 'string'],
            'db_password' => ['nullable', 'string'],
        ]);

        return response()->json($this->installer->testConnection($this->dbCredentials($data)));
    }

    /** Activate the license for the installer's "Activate" button (JSON). */
    public function activateLicense(Request $request): JsonResponse
    {
        if ($this->installer->isInstalled()) {
            abort(404);
        }

        $isEnvato = $request->input('verify_type', $this->license->defaultVerifyType()) === 'envato';

        $data = $request->validate([
            'license_code' => ['required', 'string'],
            'verify_type' => ['nullable', Rule::in(LicenseManager::TYPES)],
            'client_name' => [Rule::requiredIf($isEnvato), 'nullable', 'string', 'max:255'],
        ], [], ['client_name' => 'Envato buyer name']);

        return response()->json($this->license->activate(
            $data['license_code'],
            (string) ($data['client_name'] ?? ''),
            $data['verify_type'] ?? null,
        ));
    }

    /** Run the full install: write env, migrate, seed, create admin, lock. */
    public function run(Request $request): RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            return redirect()->route('admin.login');
        }

        $licenseIsEnvato = $request->input('verify_type', $this->license->defaultVerifyType()) === 'envato';

        $data = $request->validate([
            'license_code' => [Rule::requiredIf($this->license->enabled()), 'nullable', 'string'],
            'verify_type' => ['nullable', Rule::in(LicenseManager::TYPES)],
            'client_name' => [Rule::requiredIf($this->license->enabled() && $licenseIsEnvato), 'nullable', 'string', 'max:255'],
            'app_name' => ['required', 'string', 'max:255'],
            'app_url' => ['required', 'url'],
            'app_env' => ['required', 'in:production,local'],
            'db_host' => ['required', 'string'],
            'db_port' => ['required'],
            'db_database' => ['required', 'string'],
            'db_username' => ['required', 'string'],
            'db_password' => ['nullable', 'string'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
            'import_demo' => ['boolean'],
        ], [], ['client_name' => 'Envato buyer name']);

        // 1. Activate + verify the license (when licensing is enabled). The
        //    License step normally activates already; activate here as a
        //    fallback, then confirm validity with a fresh (uncached) verify.
        if ($this->license->enabled()) {
            if (! $this->license->isActivated()) {
                $activation = $this->license->activate(
                    (string) ($data['license_code'] ?? ''),
                    (string) ($data['client_name'] ?? ''),
                    $data['verify_type'] ?? null,
                );
                if (! $activation['ok']) {
                    throw ValidationException::withMessages(['license_code' => $activation['message']]);
                }
            }
            $verification = $this->license->verify(useCache: false);
            if (! $verification['ok']) {
                throw ValidationException::withMessages(['license_code' => $verification['message']]);
            }
        }

        $db = $this->dbCredentials($data);

        // 2. Verify the database is reachable before persisting anything.
        $test = $this->installer->testConnection($db);
        if (! $test['ok']) {
            throw ValidationException::withMessages(['db_database' => $test['message']]);
        }

        // 3. Persist environment (DB creds + app info). NOT APP_INSTALLED yet.
        $env = [
            'APP_NAME' => $data['app_name'],
            'APP_URL' => $data['app_url'],
            'APP_ENV' => $data['app_env'],
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'],
        ];
        $this->installer->writeEnv($env);
        $this->installer->ensureAppKey();

        // 4. Migrate + seed against the new connection. This is the long part.
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $this->installer->applyDatabaseConfig($db);
            $this->installer->runMigrations();
            $this->installer->seedCore();
            if ($request->boolean('import_demo')) {
                $this->installer->seedDemo();
            }
            $this->installer->createSuperAdmin(
                $data['admin_name'],
                $data['admin_email'],
                $data['admin_password'],
            );

            // 5. Lock the installer and refresh caches. Written LAST so any
            //    failure above leaves the wizard reachable for a clean retry.
            $this->installer->markInstalled();
            $this->installer->clearCaches();
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'install' => 'Installation failed: '.$e->getMessage(),
            ]);
        }

        return redirect()->route('admin.login')
            ->with('status', 'Installation complete — sign in with your new admin account.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function dbCredentials(array $data): array
    {
        return [
            'host' => $data['db_host'],
            'port' => (string) $data['db_port'],
            'database' => $data['db_database'],
            'username' => $data['db_username'],
            'password' => $data['db_password'] ?? '',
        ];
    }
}
