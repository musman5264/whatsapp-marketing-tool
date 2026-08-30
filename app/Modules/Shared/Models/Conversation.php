<?php

namespace App\Modules\Shared\Models;

use App\Models\InternalNote;
use App\Models\User;
use App\Modules\Inbox\Models\ConversationActivity;
use App\Modules\Inbox\Models\InboxLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Conversation extends Model
{
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
        static::created(function (self $model) {
            ConversationActivity::log($model, 'created', [
                'status' => $model->status,
            ]);
        });
        static::updated(function (self $model) {
            $model->recordActivityChanges();
        });
    }

    /**
     * Model-level activity trail: hooking `updated` (rather than each
     * controller) also captures changes made by automation, campaigns,
     * chatbot handover and the mobile API. Actor attribution comes from
     * the authenticated user inside ConversationActivity::log().
     */
    protected function recordActivityChanges(): void
    {
        if ($this->wasChanged('assigned_user_id')) {
            $fromId = $this->getOriginal('assigned_user_id');
            $toId = $this->assigned_user_id;
            $names = User::whereIn('id', array_filter([$fromId, $toId]))->pluck('name', 'id');

            $type = match (true) {
                ! $fromId && $toId => 'assigned',
                $fromId && ! $toId => 'unassigned',
                default => 'transferred',
            };

            ConversationActivity::log($this, $type, [
                'from_id' => $fromId,
                'from_name' => $fromId ? $names->get($fromId) : null,
                'to_id' => $toId,
                'to_name' => $toId ? $names->get($toId) : null,
            ]);
        }

        if ($this->wasChanged('status')) {
            ConversationActivity::log($this, 'status_changed', [
                'from' => $this->getOriginal('status'),
                'to' => $this->status,
            ]);
        }

        if ($this->wasChanged('assigned_to')) {
            ConversationActivity::log($this, 'handover', [
                'from' => $this->getOriginal('assigned_to'),
                'to' => $this->assigned_to,
            ]);
        }
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'workspace_id', 'channel_account_id', 'contact_id', 'external_thread_id',
        'status', 'assigned_user_id', 'assigned_to', 'handover_at',
        'last_message_at', 'unread_count',
        'first_response_at', 'resolved_at', 'last_inbound_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'handover_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany('sent_at');
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(InternalNote::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ConversationActivity::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(
            InboxLabel::class,
            'inbox_label_conversation',
            'conversation_id',
            'label_id'
        );
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Whether the WhatsApp customer-service window allows free-form (session) messages.
     *
     * Meta only allows non-template outbound content while a user-initiated
     * conversation is within the rolling ~24h window from the contact's last
     * **inbound** message. Sending a template (including campaigns) does not
     * open this window until the contact sends a message (including tapping a
     * template button).
     *
     * Inbounds are scoped by workspace + contact across all WhatsApp threads so
     * a campaign-mirrored conversation still reflects replies if webhooks
     * attached to a different row (e.g. mismatched channel_account_id).
     */
    /**
     * Whether this conversation is subject to WhatsApp's 24-hour session window
     * and template gating. Only the official Cloud API (`provider = meta`) is —
     * a personal number linked via WhatsApp Web (`provider = whatsapp_web`) has
     * no window and no templates, so free-form sends are always allowed.
     */
    public function requiresWhatsappTemplateWindow(): bool
    {
        return $this->channelAccount?->channel === 'whatsapp'
            && $this->channelAccount?->provider !== 'whatsapp_web';
    }

    public function isWhatsappWindowOpen(): bool
    {
        if (! $this->requiresWhatsappTemplateWindow()) {
            return true;
        }

        $latestInbound = Message::query()
            ->where('direction', 'in')
            ->where('channel', 'whatsapp')
            ->whereHas('conversation', function ($q) {
                $q->where('workspace_id', $this->workspace_id)
                    ->where('contact_id', $this->contact_id);
            })
            ->latest('sent_at')
            ->value('sent_at');

        return (bool) $latestInbound && now()->diffInHours($latestInbound) < 24;
    }
}
