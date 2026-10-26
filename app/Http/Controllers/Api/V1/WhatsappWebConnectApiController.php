<?php

namespace App\Http\Controllers\Api\V1;

use App\Modules\WhatsappWeb\Http\Controllers\WhatsappWebSessionController;
use Illuminate\Http\Request;

/**
 * Lets the linked system (EPOS) show the QR code to re-link the workspace's WhatsApp number.
 * Same code as the inbox screen (qr + connect); only the workspace lookup differs: an API
 * token always means the token owner's own workspace. Who may see the QR is EPOS's decision.
 */
class WhatsappWebConnectApiController extends WhatsappWebSessionController
{
    protected function workspaceId(Request $request): int
    {
        return (int) $request->user()->workspace_id;
    }
}
