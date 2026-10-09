<?php

namespace App\Modules\WhatsappWeb\Console;

use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\WhatsappWebHealth;
use Illuminate\Console\Command;

/**
 * Prints the same health report as Inbox → Setup → "Run health check".
 *
 *   php artisan whatsapp-web:diagnose
 *   php artisan whatsapp-web:diagnose --workspace=12
 */
class WhatsappWebDiagnoseCommand extends Command
{
    protected $signature = 'whatsapp-web:diagnose {--workspace= : limit to one workspace id}';

    protected $description = 'Check why WhatsApp Web chats/messages may not be arriving (engine, webhook, queue, scheduler, realtime)';

    public function handle(): int
    {
        $health = WhatsappWebHealth::fromSystem();
        if (! $health) {
            $this->error('The WhatsApp Web engine is not configured (Admin → Integrations → WhatsApp Web).');

            return self::FAILURE;
        }

        $sessions = WhatsappWebSession::query()
            ->when($this->option('workspace'), fn ($q, $ws) => $q->where('workspace_id', (int) $ws))
            ->get();

        if ($sessions->isEmpty()) {
            $this->warn('No WhatsApp Web sessions found.');

            return self::SUCCESS;
        }

        $this->line('APP_URL = '.config('app.url').'   queue = '.config('queue.default').'   broadcast = '.config('broadcasting.default'));

        $failed = false;
        foreach ($sessions as $session) {
            $this->newLine();
            $this->info("Workspace {$session->workspace_id} — session {$session->session_name} ({$session->status})");

            foreach ($health->run($session) as $c) {
                $mark = ['ok' => 'OK  ', 'warn' => 'WARN', 'fail' => 'FAIL'][$c['status']] ?? '??  ';
                $this->line("[{$mark}] {$c['label']}: {$c['detail']}");
                if ($c['hint'] !== '' && $c['status'] !== 'ok') {
                    $this->line("       → {$c['hint']}");
                }
                $failed = $failed || $c['status'] === 'fail';
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
