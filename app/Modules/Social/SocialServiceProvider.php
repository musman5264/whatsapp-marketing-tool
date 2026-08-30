<?php

namespace App\Modules\Social;

use Illuminate\Support\ServiceProvider;

class SocialServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
