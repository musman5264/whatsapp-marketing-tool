<?php

namespace App\Modules\WhatsappWeb\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\EngineManager;
use App\Modules\WhatsappWeb\Services\SessionProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * QR-pairing lifecycle for a workspace's personal WhatsApp number (WAHA engine).
 * All endpoints are workspace-scoped; one session per workspace.
 */
class WhatsappWebSessionController extends Controller
{
    public function __construct(
        private readonly EngineManager $engines,
        private readonly SessionProvisioner $provisioner,
    ) {}

    /** POST /app/whatsapp-web/connect — create/start the engine session, begin QR pairing. */
    public function connect(Request $request): JsonResponse
    {
        if (! $this->engines->enabled()) {
            return response()->json([
                'message' => 'WhatsApp Web is not available. Ask an administrator to configure the engine in Admin → Integrations → WhatsApp Web.',
            ], 422);
        }

        $workspaceId = $this->workspaceId($request);
        $creds = $this->engines->credentials();
        $session = $this->provisioner->ensure($workspaceId, $creds?->engine() ?? 'waha');

        try {
            $webhookUrl = route('webhooks.whatsapp-web.receive', ['token' => $session->webhook_token]);
            $this->engines->adapter()->startSession($session->session_name, $webhookUrl, $creds?->webhookSecret());
            $status = $this->engines->adapter()->getStatus($session->session_name);
            $session->update(['status' => $this->localStatus($status), 'last_seen_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('whatsapp_web.connect_failed', ['workspace_id' => $workspaceId, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not start the WhatsApp Web session: '.$e->getMessage()], 422);
        }

        return response()->json(['status' => $session->fresh()->status]);
    }

    /** GET /app/whatsapp-web/qr — current QR (polled by the UI while scanning). */
    public function qr(Request $request): JsonResponse
    {
        $session = $this->currentSession($request);
        if (! $session) {
            return response()->json(['qr' => null, 'status' => 'pending']);
        }

        $qr = null;
        $status = $session->status;

        try {
            $adapter = $this->engines->adapter();
            $status = $this->localStatus($adapter->getStatus($session->session_name));

            if ($status === 'scan_qr') {
                $qr = $adapter->getQr($session->session_name);
            }

            $session->update([
                'status' => $status,
                'last_qr' => $status === 'scan_qr' ? $qr : null,
                'last_seen_at' => now(),
            ]);

            if ($status === 'active') {
                $this->finalizePairing($session);
            }
        } catch (\Throwable $e) {
            Log::warning('whatsapp_web.qr_poll_failed', ['session' => $session->session_name, 'error' => $e->getMessage()]);
        }

        return response()->json(['qr' => $qr, 'status' => $session->fresh()->status]);
    }

    /** GET /app/whatsapp-web/status — connection status + paired identity. */
    public function status(Request $request): JsonResponse
    {
        $session = $this->currentSession($request);
        if (! $session) {
            return response()->json(['configured' => $this->engines->enabled(), 'status' => 'pending']);
        }

        try {
            $status = $this->localStatus($this->engines->adapter()->getStatus($session->session_name));
            if ($status === 'active' && $session->status !== 'active') {
                $this->finalizePairing($session);
            } else {
                $session->update(['status' => $status, 'last_seen_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('whatsapp_web.status_failed', ['session' => $session->session_name, 'error' => $e->getMessage()]);
        }

        $session = $session->fresh();

        return response()->json([
            'configured' => true,
            'status' => $session->status,
            'phone_e164' => $session->phone_e164,
            'push_name' => $session->push_name,
        ]);
    }

    /** DELETE /app/whatsapp-web/disconnect — unlink the device. */
    public function disconnect(Request $request): JsonResponse
    {
        $session = $this->currentSession($request);
        if (! $session) {
            return response()->json(['status' => 'disconnected']);
        }

        try {
            $this->engines->adapter()->logout($session->session_name);
        } catch (\Throwable $e) {
            Log::warning('whatsapp_web.logout_failed', ['session' => $session->session_name, 'error' => $e->getMessage()]);
        }

        $this->provisioner->disconnect($session, hardDelete: (bool) $request->boolean('hard'));

        return response()->json(['status' => 'disconnected']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function finalizePairing(WhatsappWebSession $session): void
    {
        $me = null;
        try {
            $me = $this->engines->adapter()->getMe($session->session_name);
        } catch (\Throwable $e) {
            Log::warning('whatsapp_web.getme_failed', ['session' => $session->session_name, 'error' => $e->getMessage()]);
        }

        $this->provisioner->markActive($session, $me['phone_e164'] ?? null, $me['push_name'] ?? null);
    }

    private function currentSession(Request $request): ?WhatsappWebSession
    {
        return WhatsappWebSession::where('session_name', WhatsappWebSession::sessionNameFor($this->workspaceId($request)))->first();
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    /** Engine status → our enum (they share names except 'stopped'). */
    private function localStatus(string $engineStatus): string
    {
        return match ($engineStatus) {
            'scan_qr' => 'scan_qr',
            'connecting' => 'connecting',
            'active' => 'active',
            'failed' => 'failed',
            'stopped' => 'disconnected',
            default => 'pending',
        };
    }
}
