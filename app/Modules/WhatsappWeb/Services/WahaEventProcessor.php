<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Support\Facades\Log;

/**
 * Processes one WAHA webhook event (message / session.status / message.ack).
 *
 * Called INLINE by the webhook controller so inbound messages appear in the
 * inbox within ~1s (no queue/cron wait — important on shared hosting where a
 * persistent queue worker is not possible). ProcessWahaEventJob wraps this for
 * retry/backlog handling only.
 */
class WahaEventProcessor
{
    public function __construct(
        private readonly InboundNormalizer $normalizer,
        private readonly WhatsappDriver $driver,
        private readonly SessionProvisioner $provisioner,
    ) {}

    /**
     * @param  array<string,mixed>  $payload  the full WAHA webhook body
     */
    public function process(array $payload, WhatsappWebSession $session): void
    {
        $event = (string) ($payload['event'] ?? '');

        match (true) {
            $event === 'message' || $event === 'message.any' => $this->handleInbound($payload, $session),
            $event === 'session.status' => $this->handleSessionStatus($payload, $session),
            $event === 'message.ack' => $this->handleAck($payload),
            default => Log::info('whatsapp_web.event.ignored', ['event' => $event]),
        };
    }

    /** Resolve the session by name (stable) with an id fallback. */
    public function resolveSession(int $sessionId, ?string $sessionName): ?WhatsappWebSession
    {
        if ($sessionName) {
            $byName = WhatsappWebSession::where('session_name', $sessionName)->first();
            if ($byName) {
                return $byName;
            }
        }

        return WhatsappWebSession::find($sessionId);
    }

    /** @param array<string,mixed> $payload */
    private function handleInbound(array $payload, WhatsappWebSession $session): void
    {
        $normalized = $this->normalizer->normalize($payload, $session);
        if ($normalized === null) {
            return; // outbound echo, group message, or unparseable
        }

        [$value, $msg] = $normalized;
        $this->driver->ingestNormalizedInbound($value, $msg);
    }

    /** @param array<string,mixed> $payload */
    private function handleSessionStatus(array $payload, WhatsappWebSession $session): void
    {
        $raw = strtoupper((string) ($payload['payload']['status'] ?? ''));
        $status = match ($raw) {
            'WORKING' => 'active',
            'SCAN_QR_CODE' => 'scan_qr',
            'STARTING' => 'connecting',
            'FAILED' => 'failed',
            'STOPPED' => 'disconnected',
            default => null,
        };

        if ($status === null) {
            return;
        }

        if ($status === 'active') {
            $this->provisioner->markActive($session, $session->phone_e164, $session->push_name);
        } else {
            $this->provisioner->syncStatus($session, $status);
        }
    }

    /** @param array<string,mixed> $payload */
    private function handleAck(array $payload): void
    {
        $p = $payload['payload'] ?? [];
        $id = (string) ($p['id']['_serialized'] ?? $p['id'] ?? '');
        if ($id === '') {
            return;
        }

        // WAHA ack: 1=sent(server) 2=delivered(device) 3=read 4=played; -1=error
        $ack = (int) ($p['ack'] ?? 0);
        $status = match (true) {
            $ack < 0 => 'failed',
            $ack === 1 => 'sent',
            $ack === 2 => 'delivered',
            $ack >= 3 => 'read',
            default => null,
        };

        if ($status === null || ! Message::where('provider_message_id', $id)->exists()) {
            return;
        }

        $this->driver->applyStatusUpdate(['id' => $id, 'status' => $status]);
    }
}
