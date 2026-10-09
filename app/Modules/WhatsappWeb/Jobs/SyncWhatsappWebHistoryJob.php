<?php

namespace App\Modules\WhatsappWeb\Jobs;

use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\WahaHistorySync;
use App\Modules\WhatsappWeb\Services\WhatsappWebResubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Backfills one workspace's WhatsApp Web inbox from the engine's history.
 * Queued by: session becoming active, the 10-minute schedule, and the
 * "Sync now" button (which can also re-register the webhook first).
 */
class SyncWhatsappWebHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public readonly int $sessionId,
        public readonly int $chats = 50,
        public readonly int $messages = 30,
        public readonly bool $resubscribe = false,
    ) {}

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('whatsapp-web-sync:'.$this->sessionId))->dontRelease()->expireAfter(900),
        ];
    }

    public function handle(WahaHistorySync $sync, WhatsappWebResubscriber $resubscriber): void
    {
        $session = WhatsappWebSession::find($this->sessionId);
        if (! $session) {
            return;
        }

        // Scheduled and on-connect runs only make sense for a linked number.
        if ($session->status !== 'active' && ! $this->resubscribe) {
            return;
        }

        if ($this->resubscribe) {
            try {
                $resubscriber->resubscribe($session);
            } catch (\Throwable $e) {
                Log::warning('whatsapp_web.sync.resubscribe_failed', ['session' => $session->session_name, 'error' => $e->getMessage()]);
            }
        }

        $sync->run($session, $this->chats, $this->messages);
    }
}
