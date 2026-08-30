<?php

namespace Database\Seeders;

use App\Modules\Integrations\Database\Seeders\IntegrationConfigSeeder;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Core / framework data. NOTE: no AdminUserSeeder — the platform
            // super-admin is created interactively by the installer, never seeded.
            UserSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
            CurrencySeeder::class,
            LocaleSeeder::class,
            TranslationSeeder::class,
            PlanSeeder::class,
            PaymentGatewayConfigSeeder::class,
            EmailTemplateSeeder::class,
            IntegrationConfigSeeder::class,
            SmtpConfigurationSeeder::class,
            LandingPageSeeder::class,
            CmsPageSeeder::class,

            // Comprehensive demo content: one fully-populated client
            // (SpaGreen Wellness) across every module, plus light secondaries.
            DemoSeeder::class,
        ]);

        // User::factory(10)->create();
        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}
