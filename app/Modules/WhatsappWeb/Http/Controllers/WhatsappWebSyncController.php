<?php

namespace App\Modules\WhatsappWeb\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WhatsappWeb\Jobs\SyncWhatsappWebHistoryJob;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /app/whatsapp-web/sync — queue a history backfill for the workspace's
 * number ("Sync now"). Optionally re-registers the webhook first, which keeps the
 * device linked. Returns immediately; the work runs on the whatsapp queue.
 */
class WhatsappWebSyncController extends Controller
{
    public function sync(Request $request): JsonResponse
    {
        $resubscribe = $request->boolean('resubscribe');

        $session = WhatsappWebSession::where(
            'session_name',
            WhatsappWebSession::sessionNameFor($this->workspaceId($request)),
        )->first();

        if (! $session) {
            return response()->json(['message' => 'Connect your WhatsApp number first.'], 422);
        }

        if ($session->status !== 'active' && ! $resubscribe) {
            return response()->json(['message' => 'WhatsApp is not connected. Reconnect the number, then sync.'], 422);
        }

        SyncWhatsappWebHistoryJob::dispatch($session->id, 50, 30, $resubscribe)->onQueue('whatsapp');

        return response()->json(['queued' => true]);
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
