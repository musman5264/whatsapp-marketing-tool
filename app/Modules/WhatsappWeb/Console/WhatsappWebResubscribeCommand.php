<?php

namespace App\Modules\WhatsappWeb\Console;

use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\WhatsappWebResubscriber;
use Illuminate\Console\Command;

/**
 * Re-registers the webhook (URL, event list, HMAC) on every WhatsApp Web session
 * so sessions created before an event was added pick it up. Never logs out.
 *
 *   php artisan whatsapp-web:resubscribe
 *   php artisan whatsapp-web:resubscribe --workspace=12
 */
class WhatsappWebResubscribeCommand extends Command
{
    protected $signature = 'whatsapp-web:resubscribe {--workspace= : limit to one workspace id}';

    protected $description = 'Re-register the WAHA webhook (events + URL) on existing WhatsApp Web sessions without logging them out';

    public function handle(WhatsappWebResubscriber $resubscriber): int
    {
        $sessions = WhatsappWebSession::query()
            ->whereNotNull('webhook_token')
            ->when($this->option('workspace'), fn ($q, $ws) => $q->where('workspace_id', (int) $ws))
            ->get();

        if ($sessions->isEmpty()) {
            $this->info('No WhatsApp Web sessions found.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($sessions as $session) {
            try {
                $resubscriber->resubscribe($session);
                $this->line("workspace {$session->workspace_id} ({$session->session_name}): webhook re-registered");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("workspace {$session->workspace_id} ({$session->session_name}): ".$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
