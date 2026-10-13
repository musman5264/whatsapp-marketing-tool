<?php

namespace App\Console\Commands;

use App\Services\ReverbRuntime;
use Illuminate\Console\Command;

/**
 * Starts Reverb in the background with the saved settings, then exits. Unlike `reverb:start`,
 * which runs in the foreground until it is stopped, this returns within seconds, so the
 * Deployment command runner can call it (it waits for the command to finish).
 */
class ReverbLaunch extends Command
{
    protected $signature = 'reverb:launch';

    protected $description = 'Start Reverb in the background with the saved settings, then exit';

    public function handle(ReverbRuntime $runtime): int
    {
        $error = $runtime->launch();
        $runtime->recordManualStart($error);

        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $this->info('Reverb is running on '.$runtime->listenHost().':'.$runtime->listenPort().'.');

        return self::SUCCESS;
    }
}
