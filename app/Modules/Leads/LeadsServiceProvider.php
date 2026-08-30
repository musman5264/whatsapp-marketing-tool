<?php

namespace App\Modules\Leads;

use App\Modules\Leads\Contracts\LeadScorer;
use App\Modules\Leads\Services\Scoring\RuleBasedLeadScorer;
use Illuminate\Support\ServiceProvider;

class LeadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Swap this binding to layer in an LLM-backed scorer; nothing downstream
        // of the LeadScore value object needs to change.
        $this->app->singleton(LeadScorer::class, RuleBasedLeadScorer::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
