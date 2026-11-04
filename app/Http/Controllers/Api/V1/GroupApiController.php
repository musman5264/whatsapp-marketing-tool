<?php

namespace App\Http\Controllers\Api\V1;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\WhatsappWeb\Services\EngineManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GroupApiController extends WorkspaceScopedController
{
    /**
     * GET /api/v1/groups
     * The WhatsApp groups the workspace's connected WhatsApp Web (QR) number is a member of:
     * {"data":[{"id":"120363…@g.us","name":"Design team"}]}. A workspace with no QR number has none (empty list).
     * shortcut: one response, no paging (WAHA returns them all); add paging if a number is in thousands of groups.
     */
    public function index(Request $request): JsonResponse
    {
        $account = ChannelAccount::where('workspace_id', $this->workspaceId($request))
            ->where('channel', 'whatsapp')->where('status', 'active')->where('provider', 'whatsapp_web')
            ->orderBy('id')->first();

        if (! $account || ! $account->phone_number_id) {
            return response()->json(['data' => []]);
        }

        try {
            return response()->json(['data' => app(EngineManager::class)->adapter()->listGroups($account->phone_number_id)]);
        } catch (\Throwable $e) {
            Log::warning('api.groups.list_failed', ['workspace_id' => $account->workspace_id, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return response()->json(['error' => 'Could not read the groups from the WhatsApp number right now.'], 502);
        }
    }
}
