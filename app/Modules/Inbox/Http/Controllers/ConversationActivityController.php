<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationActivityController extends Controller
{
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);

        $activities = $conversation->activities()
            ->with('user:id,name')
            ->latest()
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json($activities);
    }

    private function authorise(Request $request, Conversation $conversation): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $conversation->workspace_id === (int) $workspaceId, 403);
    }
}
