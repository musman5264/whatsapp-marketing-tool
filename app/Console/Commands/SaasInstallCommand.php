<?php

namespace App\Console\Commands;

use App\Services\Install\InstallerService;
use Illuminate\Console\Command;

class SaasInstallCommand extends Command
{
    protected $signature = 'saas:install {--fresh : Drop and re-create all tables} {--seed : Seed demo data}';

    protected $description = 'Interactive first-run setup: run migrations, create the super-admin, and seed defaults.';

    public function handle(InstallerService $installer): int
    {
        $this->info('');
        $this->info('  ██╗    ██╗██╗  ██╗ █████╗ ████████╗███████╗');
        $this->info('  ██║    ██║██║  ██║██╔══██╗╚══██╔══╝██╔════╝');
        $this->info('  ██║ █╗ ██║███████║███████║   ██║   ███████╗');
        $this->info('  ██║███╗██║██╔══██║██╔══██║   ██║   ╚════██║');
        $this->info('  ╚███╔███╔╝██║  ██║██║  ██║   ██║   ███████║');
        $this->info('   ╚══╝╚══╝ ╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝   ╚══════╝');
        $this->info('  '.config('app.name').' — Install Wizard');
        $this->info('');

        // 0. Ensure an .env / app key exists before anything touches the DB.
        if (! file_exists(base_path('.env'))) {
            copy(base_path('.env.example'), base_path('.env'));
            $this->info('Created .env from .env.example');
        }
        $installer->ensureAppKey();

        // 1. Run migrations (--fresh drops everything first; CLI-only safety prompt).
        if ($this->option('fresh')) {
            if (! $this->confirm('This will DROP all tables. Continue?', false)) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }
            $this->call('migrate:fresh', ['--force' => true]);
        } else {
            $installer->runMigrations();
        }

        // 2. Seed core data required for the app to run (no demo content).
        $this->info('');
        $this->info('Seeding core data…');
        $installer->seedCore();

        // 3. Optionally seed demo data (sample client, conversations, etc.).
        if ($this->option('seed')) {
            $installer->seedDemo();
        }

        // 4. Create the platform super-admin (with the super-admin RBAC role).
        $this->info('');
        $this->info('Creating platform super-admin…');
        $this->createSuperAdmin($installer);

        // 5. Clear caches.
        $installer->clearCaches();

        $this->info('');
        $this->info('✅  Installation complete!');
        $this->info('   Open '.config('app.url').' in your browser.');
        $this->info('   Sign in to the admin panel at '.config('app.url').'/admin');

        return self::SUCCESS;
    }

    private function createSuperAdmin(InstallerService $installer): void
    {
        $name = $this->ask('Admin name', 'Super Admin');
        $email = $this->ask('Admin email', 'admin@example.com');

        do {
            $password = (string) $this->secret('Admin password (min 8 chars)');
            if (strlen($password) < 8) {
                $this->error('Password must be at least 8 characters.');
            }
        } while (strlen($password) < 8);

        try {
            $admin = $installer->createSuperAdmin($name, $email, $password);
            $this->info("Admin ready: {$admin->email}");
        } catch (\Throwable $e) {
            $this->error('Could not create admin: '.$e->getMessage());
        }
    }
}
