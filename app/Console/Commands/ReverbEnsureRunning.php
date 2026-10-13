<?php

namespace App\Console\Commands;

use App\Services\ReverbRuntime;
use Illuminate\Console\Command;

/**
 * Scheduled every minute (the existing cron entry runs schedule:run). Starts Reverb while it is
 * switched on in the admin panel and it does not answer its health check.
 */
class ReverbEnsureRunning extends Command
{
    protected $signature = 'reverb:ensure-running';

    protected $description = 'Start Reverb if it is switched on and not running';

    public function handle(ReverbRuntime $runtime): int
    {
        $result = $runtime->watchdog();

        $this->line($result['result'].': '.$result['message']);

        return $result['result'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
