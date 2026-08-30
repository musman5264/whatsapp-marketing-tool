<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovers any app/Modules/{Name}/ModuleServiceProvider.php and boots them.
 *
 * Each module must extend Illuminate\Support\ServiceProvider and may register
 * its own routes, nav items, and migrations.
 *
 * To create a new module use: php artisan saas:make-module {Name}
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $modulesPath = app_path('Modules');

        if (! is_dir($modulesPath)) {
            return;
        }

        foreach (glob($modulesPath.'/*', GLOB_ONLYDIR) as $moduleDir) {
            $moduleName = basename($moduleDir);

            // Support both {Name}ServiceProvider.php (saas:make-module default) and ModuleServiceProvider.php
            $providerClass = class_exists('App\\Modules\\'.$moduleName.'\\'.$moduleName.'ServiceProvider')
                ? 'App\\Modules\\'.$moduleName.'\\'.$moduleName.'ServiceProvider'
                : 'App\\Modules\\'.$moduleName.'\\ModuleServiceProvider';

            if (class_exists($providerClass)) {
                $this->app->register($providerClass);
            }
        }
    }
}
