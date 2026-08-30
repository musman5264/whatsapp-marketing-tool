<?php

namespace App\Modules\Deployment;

use Illuminate\Support\ServiceProvider;

/**
 * In-app deployment manager (GitHub → server).
 *
 * The app never runs deploy commands itself — DeployClient makes HTTP requests to
 * a standalone public/deploy.php on the target server, gated by a shared secret.
 * Admin routes live in the central routes/admin.php (like the Integrations module).
 */
class DeploymentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
