<?php

namespace App\Services\Install;

use App\Models\AdminUser;
use App\Models\Role;
use App\Modules\Integrations\Database\Seeders\IntegrationConfigSeeder;
use Database\Seeders\CmsPageSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\LandingPageSeeder;
use Database\Seeders\PaymentGatewayConfigSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Single source of truth for first-run installation, shared by the CLI
 * (SaasInstallCommand) and the web installer (InstallController). Keeps the
 * seeder list, super-admin creation, and cache clearing in one place.
 */
class InstallerService
{
    public const MIN_PHP_VERSION = '8.2.0';

    public const REQUIRED_EXTENSIONS = [
        'pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer',
        'ctype', 'json', 'bcmath', 'fileinfo', 'curl',
    ];

    /**
     * Core data required for the app to run (no demo content). Order matters:
     * permissions must exist before RoleSeeder syncs them. Mirrors the list in
     * SaasInstallCommand; every seeder is idempotent (firstOrCreate/updateOrCreate).
     */
    public const CORE_SEEDERS = [
        PermissionSeeder::class,
        RoleSeeder::class,
        CurrencySeeder::class,
        PlanSeeder::class,
        PaymentGatewayConfigSeeder::class,
        EmailTemplateSeeder::class,
        IntegrationConfigSeeder::class,
        LandingPageSeeder::class,
        CmsPageSeeder::class,
    ];

    public function __construct(private EnvWriter $env) {}

    public function isInstalled(): bool
    {
        return (bool) config('app.installed');
    }

    /**
     * Pre-flight check results for the requirements step.
     *
     * @return array{php: array{name: string, current: string, passed: bool}, extensions: list<array{name: string, passed: bool}>, writable: list<array{name: string, passed: bool}>, ok: bool}
     */
    public function requirements(): array
    {
        $php = [
            'name' => 'PHP >= '.self::MIN_PHP_VERSION,
            'current' => PHP_VERSION,
            'passed' => version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>='),
        ];

        $extensions = array_map(fn (string $ext) => [
            'name' => $ext,
            'passed' => extension_loaded($ext),
        ], self::REQUIRED_EXTENSIONS);

        $writable = [];
        foreach ([
            '.env' => base_path('.env'),
            'storage/' => storage_path(),
            'bootstrap/cache/' => base_path('bootstrap/cache'),
        ] as $label => $path) {
            $writable[] = ['name' => $label, 'passed' => $this->isWritable($path)];
        }

        $ok = $php['passed']
            && ! in_array(false, array_column($extensions, 'passed'), true)
            && ! in_array(false, array_column($writable, 'passed'), true);

        return ['php' => $php, 'extensions' => $extensions, 'writable' => $writable, 'ok' => $ok];
    }

    /**
     * Test a database connection at runtime without persisting anything.
     *
     * @param  array{connection?: string, host: string, port: string|int, database: string, username: string, password?: string}  $db
     * @return array{ok: bool, message: string}
     */
    public function testConnection(array $db): array
    {
        $connection = $db['connection'] ?? 'mysql';

        try {
            $this->applyDatabaseConfig($db);
            DB::connection($connection)->getPdo();

            return ['ok' => true, 'message' => 'Connection successful — the database is reachable.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->friendlyDbError($e)];
        }
    }

    /**
     * Point the live request's database connection at the supplied credentials
     * and reconnect. MUST be called before migrating/seeding — writing .env does
     * not change the connection already resolved for this request.
     *
     * @param  array{connection?: string, host: string, port: string|int, database: string, username: string, password?: string}  $db
     */
    public function applyDatabaseConfig(array $db): void
    {
        $connection = $db['connection'] ?? 'mysql';
        $base = config("database.connections.$connection", []);

        config([
            "database.connections.$connection" => array_merge($base, [
                'host' => $db['host'],
                'port' => (string) $db['port'],
                'database' => $db['database'],
                'username' => $db['username'],
                'password' => $db['password'] ?? '',
            ]),
            'database.default' => $connection,
        ]);

        DB::purge($connection);
        DB::reconnect($connection);
    }

    public function ensureAppKey(): void
    {
        if (empty(config('app.key'))) {
            Artisan::call('key:generate', ['--force' => true]);
        }
    }

    public function runMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    /** Seed the core data required for the app to run (no demo content). */
    public function seedCore(): void
    {
        Artisan::call('i18n:seed-defaults');

        foreach (self::CORE_SEEDERS as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }
    }

    /**
     * Seed sample/demo content: one fully-populated demo client (SpaGreen
     * Wellness — client@spagreen.net) covering every module, plus a couple of
     * lighter secondary clients. DatabaseSeeder runs DemoSeeder as its final
     * step, so a single call covers everything.
     */
    public function seedDemo(): void
    {
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    }

    public function createSuperAdmin(string $name, string $email, string $password): AdminUser
    {
        $admin = AdminUser::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'status' => AdminUser::STATUS_ACTIVE,
            ]
        );

        $superAdminRole = Role::where('key', Role::KEY_SUPER_ADMIN)->first();
        if ($superAdminRole && ! $admin->roles()->where('roles.id', $superAdminRole->id)->exists()) {
            $admin->roles()->attach($superAdminRole->id);
        }

        return $admin;
    }

    public function clearCaches(): void
    {
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');

        try {
            Artisan::call('cache:clear');
        } catch (\Throwable) {
            // The cache store may be unavailable on a fresh box; non-fatal.
        }
    }

    /**
     * Persist environment values written by the installer (DB creds, app info).
     *
     * @param  array<string, string|int|bool|null>  $pairs
     */
    public function writeEnv(array $pairs): void
    {
        $this->env->set($pairs);
    }

    /** Final step: flip APP_INSTALLED so the wizard locks itself. */
    public function markInstalled(): void
    {
        $this->env->set(['APP_INSTALLED' => 'true']);
    }

    private function isWritable(string $path): bool
    {
        if (is_file($path) || is_dir($path)) {
            return is_writable($path);
        }

        // .env may not exist yet — the parent dir must be writable to create it.
        return is_writable(dirname($path));
    }

    private function friendlyDbError(\Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'Unknown database') => 'That database does not exist on the server. Create it first, then retry.',
            str_contains($message, 'Access denied') => 'Access denied — check the database username and password.',
            str_contains($message, 'Connection refused'),
            str_contains($message, 'getaddrinfo'),
            str_contains($message, 'php_network_getaddresses'),
            str_contains($message, 'timed out'),
            str_contains($message, "Can't connect") => 'Could not reach the database server — check the host and port.',
            default => 'Connection failed: '.$message,
        };
    }
}
