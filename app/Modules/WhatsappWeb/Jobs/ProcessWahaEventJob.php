<?php

namespace App\Modules\WhatsappWeb\Jobs;

use App\Modules\WhatsappWeb\Services\WahaEventProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Retry/backlog wrapper around WahaEventProcessor. The webhook controller
 * processes events INLINE for speed; this job only runs when inline processing
 * threw (queued for a later retry) or from the cron drain.
 */
class ProcessWahaEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public int $maxExceptions = 3;

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [30, 60, 120, 240, 300];
    }

    /** @param array<string,mixed> $payload */
    public function __construct(
        private readonly array $payload,
        private readonly int $sessionId,
        private readonly ?string $sessionName = null,
    ) {}

    public function handle(WahaEventProcessor $processor): void
    {
        $session = $processor->resolveSession($this->sessionId, $this->sessionName);
        if (! $session) {
            Log::warning('whatsapp_web.event.session_gone', [
                'session_id' => $this->sessionId,
                'session_name' => $this->sessionName,
            ]);

            return;
        }

        $processor->process($this->payload, $session);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessWahaEventJob failed permanently', [
            'session_id' => $this->sessionId,
            'event' => $this->payload['event'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
