<?php

namespace App\Modules\WhatsappWeb\Jobs;

use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Services\External\EposExecutor;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the linked EPOS that the WhatsApp number dropped and needs its QR scanned again.
 * Uses EPOS's own manifest action, so it is skipped quietly when EPOS has not published it.
 * Not retried: the EPOS executor never throws and a missed alert is repeated by the next drop.
 */
class NotifyEposReconnectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CAPABILITY = 'whatsapp.reconnect_alert';

    public int $tries = 1;

    public function __construct(private readonly int $sessionId, private readonly string $status) {}

    public function handle(EposExecutor $executor): void
    {
        $session = WhatsappWebSession::find($this->sessionId);
        if (! $session) {
            return;
        }

        $number = $session->phone_e164 ? ' ('.$session->phone_e164.')' : '';
        $params = [
            'status' => $this->status,
            'title' => 'Reconnect WhatsApp',
            'message' => "The WhatsApp number{$number} is disconnected. Please scan the QR code again to receive and send messages.",
        ];

        foreach (ExternalIntegration::where('workspace_id', $session->workspace_id)->where('type', 'epos')->get() as $integration) {
            $executor->execute($integration, self::CAPABILITY, null, $params, "reconnect:{$session->id}:{$this->status}:".now()->timestamp);
        }
    }
}
