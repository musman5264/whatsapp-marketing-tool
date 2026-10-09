<?php

namespace App\Modules\WhatsappWeb\Console;

use App\Modules\WhatsappWeb\Jobs\SyncWhatsappWebHistoryJob;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\WahaHistorySync;
use App\Modules\WhatsappWeb\Services\WhatsappWebResubscriber;
use Illuminate\Console\Command;

/**
 *   php artisan whatsapp-web:sync                      backfill every active number now (inline)
 *   php artisan whatsapp-web:sync --workspace=12      only workspace 12
 *   php artisan whatsapp-web:sync --queue             queue one job per active number (scheduler)
 *   php artisan whatsapp-web:sync --resubscribe       re-register webhooks first (no logout)
 */
class WhatsappWebSyncCommand extends Command
{
    protected $signature = 'whatsapp-web:sync
        {--workspace= : limit to one workspace id}
        {--chats=50 : recent chats to inspect}
        {--messages=30 : recent messages per chat}
        {--queue : dispatch jobs instead of running inline}
        {--resubscribe : re-register the webhook on the engine before syncing}
        {--quiet-output : no output (scheduler)}';

    protected $description = 'Backfill WhatsApp Web chats and messages from the engine history';

    public function handle(WahaHistorySync $sync, WhatsappWebResubscriber $resubscriber): int
    {
        $quiet = (bool) $this->option('quiet-output');
        $chats = max(1, (int) $this->option('chats'));
        $messages = max(1, (int) $this->option('messages'));

        $sessions = WhatsappWebSession::query()
            ->where('status', 'active')
            ->when($this->option('workspace'), fn ($q, $ws) => $q->where('workspace_id', (int) $ws))
            ->get();

        foreach ($sessions as $session) {
            if ($this->option('queue')) {
                SyncWhatsappWebHistoryJob::dispatch($session->id, $chats, $messages, (bool) $this->option('resubscribe'))
                    ->onQueue('whatsapp');
                $quiet || $this->line("queued workspace {$session->workspace_id}");

                continue;
            }

            if ($this->option('resubscribe')) {
                try {
                    $resubscriber->resubscribe($session);
                    $quiet || $this->line("workspace {$session->workspace_id}: webhook re-registered");
                } catch (\Throwable $e) {
                    $this->error("workspace {$session->workspace_id}: resubscribe failed: ".$e->getMessage());
                }
            }

            $stats = $sync->run($session, $chats, $messages);
            $quiet || $this->line("workspace {$session->workspace_id}: ".json_encode($stats));
        }

        $quiet || $this->info($sessions->isEmpty() ? 'No active WhatsApp Web numbers.' : 'Done.');

        return self::SUCCESS;
    }
}
