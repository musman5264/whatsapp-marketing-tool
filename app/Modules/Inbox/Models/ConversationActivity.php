<?php

namespace App\Modules\Inbox\Models;

use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationActivity extends Model
{
    protected $fillable = ['workspace_id', 'conversation_id', 'type', 'user_id', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an activity on a conversation. The actor is resolved from the
     * authenticated client user when present; null means the change came from
     * the system (automation, bot, webhook or the contact themself).
     */
    public static function log(Conversation $conversation, string $type, array $meta = []): self
    {
        $actor = auth()->user();

        return static::create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'type' => $type,
            'user_id' => $actor instanceof User ? $actor->id : null,
            'meta' => $meta ?: null,
        ]);
    }
}
