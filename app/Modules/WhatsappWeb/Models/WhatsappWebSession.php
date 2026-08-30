<?php

namespace App\Modules\WhatsappWeb\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One linked personal number per workspace, driven by an unofficial engine
 * (WAHA). `session_name` (`ws-{workspaceId}`) is the engine-side session id and
 * doubles as the `phone_number_id` on the matching ChannelAccount so the
 * existing WhatsappDriver inbound lookup resolves it with no change.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $session_name
 * @property string $engine
 * @property string|null $phone_e164
 * @property string|null $push_name
 * @property string $status
 * @property string|null $last_qr
 * @property string|null $webhook_token
 * @property string|null $webhook_token_hash
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property array<string,mixed>|null $meta_json
 */
class WhatsappWebSession extends Model
{
    protected $table = 'whatsapp_web_sessions';

    protected $fillable = [
        'workspace_id', 'session_name', 'engine', 'phone_e164', 'push_name',
        'status', 'last_qr', 'webhook_token', 'webhook_token_hash',
        'last_seen_at', 'meta_json',
    ];

    protected $hidden = ['last_qr', 'webhook_token', 'webhook_token_hash'];

    protected function casts(): array
    {
        return [
            'meta_json' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function sessionNameFor(int $workspaceId): string
    {
        return 'ws-'.$workspaceId;
    }

    public static function hashWebhookToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** O(1) lookup for the per-session webhook route. */
    public static function findByWebhookToken(string $token): ?self
    {
        return static::where('webhook_token_hash', static::hashWebhookToken($token))->first();
    }

    /** Generate and set a fresh webhook token (call before first save). */
    public function assignWebhookToken(): void
    {
        $this->webhook_token = Str::random(48);
    }

    protected static function booted(): void
    {
        static::saving(function (self $session) {
            if ($session->isDirty('webhook_token') && $session->webhook_token) {
                $session->webhook_token_hash = static::hashWebhookToken($session->webhook_token);
            }
        });
    }
}
