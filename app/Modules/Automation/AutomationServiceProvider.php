<?php

namespace App\Modules\Automation;

use App\Modules\Automation\Console\AutomationRunsCommand;
use Illuminate\Support\ServiceProvider;

class AutomationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([AutomationRunsCommand::class]);
        }
    }
}
