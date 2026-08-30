<?php

namespace App\Modules\WhatsappWeb\Jobs;

use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\InboundNormalizer;
use App\Modules\WhatsappWeb\Services\SessionProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Handles one WAHA webhook event: an inbound `message`, a `session.status`
 * change, or a `message.ack` delivery receipt. Inbound messages are normalised
 * to the Meta shape and fed through the existing WhatsappDriver so contacts,
 * conversations, auto-replies and AI all work identically to the Cloud API.
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
    ) {}

    public function handle(
        InboundNormalizer $normalizer,
        WhatsappDriver $driver,
        SessionProvisioner $provisioner,
    ): void {
        $session = WhatsappWebSession::find($this->sessionId);
        if (! $session) {
            Log::warning('whatsapp_web.event.session_gone', ['session_id' => $this->sessionId]);

            return;
        }

        $event = (string) ($this->payload['event'] ?? '');

        match (true) {
            $event === 'message' || $event === 'message.any' => $this->handleInbound($normalizer, $driver, $session),
            $event === 'session.status' => $this->handleSessionStatus($provisioner, $session),
            $event === 'message.ack' => $this->handleAck($driver),
            default => Log::info('whatsapp_web.event.ignored', ['event' => $event]),
        };
    }

    private function handleInbound(InboundNormalizer $normalizer, WhatsappDriver $driver, WhatsappWebSession $session): void
    {
        $normalized = $normalizer->normalize($this->payload, $session);
        if ($normalized === null) {
            return; // outbound echo, group message, or unparseable
        }

        [$value, $msg] = $normalized;
        $driver->ingestNormalizedInbound($value, $msg);
    }

    private function handleSessionStatus(SessionProvisioner $provisioner, WhatsappWebSession $session): void
    {
        $raw = strtoupper((string) ($this->payload['payload']['status'] ?? ''));
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
            $provisioner->markActive($session, $session->phone_e164, $session->push_name);
        } else {
            $provisioner->syncStatus($session, $status);
        }
    }

    private function handleAck(WhatsappDriver $driver): void
    {
        $p = $this->payload['payload'] ?? [];
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

        $driver->applyStatusUpdate(['id' => $id, 'status' => $status]);
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
