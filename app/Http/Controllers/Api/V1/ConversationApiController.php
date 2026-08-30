<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\ConversationResource;
use App\Http\Resources\Api\V1\MessageResource;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationApiController extends WorkspaceScopedController
{
    /**
     * GET /api/v1/conversations
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $wsId = $this->workspaceId($request);
        $query = Conversation::with('channelAccount')
            ->where('workspace_id', $wsId)
            ->latest('last_message_at');

        if ($request->filled('channel')) {
            $query->whereHas('channelAccount', fn ($q) => $q->where('channel', $request->channel));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_user_id', $request->assigned_to);
        }

        return ConversationResource::collection($query->cursorPaginate(25));
    }

    /**
     * GET /api/v1/conversations/{id}/messages
     */
    public function messages(Request $request, int $id): AnonymousResourceCollection|JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))->find($id);

        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found.'], 404);
        }

        $messages = $conversation->messages()
            ->orderBy('sent_at')
            ->paginate(50);

        return MessageResource::collection($messages);
    }
}
