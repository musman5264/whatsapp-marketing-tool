<?php

namespace App\Modules\WhatsappWeb\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\WhatsappWebHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /app/whatsapp-web/health — "Run health check" in Inbox → Setup. Read-only
 * report on why chats/messages may not be arriving (engine, webhook, queue,
 * scheduler, realtime).
 */
class WhatsappWebHealthController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        $session = WhatsappWebSession::where('session_name', WhatsappWebSession::sessionNameFor($workspaceId))->first();
        if (! $session) {
            return response()->json(['message' => 'Connect your WhatsApp number first.'], 422);
        }

        $health = WhatsappWebHealth::fromSystem();
        if (! $health) {
            return response()->json(['message' => 'The WhatsApp Web engine is not configured (Admin → Integrations → WhatsApp Web).'], 422);
        }

        return response()->json(['checks' => $health->run($session)]);
    }
}
