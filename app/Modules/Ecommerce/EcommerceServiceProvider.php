<?php

namespace App\Modules\Ecommerce;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class EcommerceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Commerce webhooks + sync depend on an async queue (the webhook flush
        // pattern and chained sync jobs break under QUEUE_CONNECTION=sync).
        if ($this->app->environment('production') && config('queue.default') === 'sync') {
            Log::critical('ecommerce.queue.sync_in_production', [
                'message' => 'QUEUE_CONNECTION=sync will block webhook responses and break chained sync; use redis/database.',
            ]);
        }
    }
}