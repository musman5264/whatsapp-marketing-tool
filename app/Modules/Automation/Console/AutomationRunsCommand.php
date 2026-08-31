<?php

namespace App\Modules\Automation\Console;

use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Models\AutomationRunLog;
use Illuminate\Console\Command;

/**
 *   php artisan automation:runs {automationId?} {--limit=15}
 *
 * Dumps recent automation runs with their status, stored context and node logs
 * so an "Ask question never resumes" style bug can be diagnosed on a server
 * without a queue worker or tinker.
 */
class AutomationRunsCommand extends Command
{
    protected $signature = 'automation:runs {automation? : automation id} {--limit=15} {--contact=}';

    protected $description = 'Show recent automation runs, their context and node logs';

    public function handle(): int
    {
        $q = AutomationRun::query()->latest('id');

        if ($a = $this->argument('automation')) {
            $q->where('automation_id', (int) $a);
        }
        if ($c = $this->option('contact')) {
            $q->where('contact_id', (int) $c);
        }

        $runs = $q->limit((int) $this->option('limit'))->get();
        if ($runs->isEmpty()) {
            $this->line('(no runs)');

            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $ctx = $run->context ?? [];
            $this->line(sprintf(
                "run #%d  auto=%d  contact=%s  status=%-9s  current=%s  resume=%s",
                $run->id,
                $run->automation_id,
                $run->contact_id ?? '-',
                $run->status,
                $run->current_node_id ?? '-',
                $run->resume_node_id ?? '-',
            ));
            $this->line('   context: '.json_encode($ctx, JSON_UNESCAPED_SLASHES));
            if ($run->error) {
                $this->line('   error:   '.$run->error);
            }
            $logs = AutomationRunLog::where('run_id', $run->id)->orderBy('id')->get();
            foreach ($logs as $log) {
                $this->line(sprintf('   · %-14s %-8s %s', $log->node_type, $log->result, mb_substr((string) $log->message, 0, 90)));
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
