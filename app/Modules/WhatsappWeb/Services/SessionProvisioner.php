<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Support\Facades\DB;

/**
 * Owns the paired-state records for a workspace's WhatsApp-Web session: the
 * WhatsappWebSession row and its shadow ChannelAccount (channel `whatsapp`,
 * provider `whatsapp_web`, phone_number_id = the engine session name) that the
 * inbox / WhatsappDriver resolve inbound messages against.
 */
class SessionProvisioner
{
    /**
     * Ensure a session record + ChannelAccount exist for the workspace and
     * return the (fresh) session. Does NOT touch the engine.
     */
    public function ensure(int $workspaceId, string $engine = 'waha'): WhatsappWebSession
    {
        $name = WhatsappWebSession::sessionNameFor($workspaceId);

        return DB::transaction(function () use ($workspaceId, $name, $engine) {
            $session = WhatsappWebSession::where('session_name', $name)->lockForUpdate()->first();

            if (! $session) {
                $session = new WhatsappWebSession([
                    'workspace_id' => $workspaceId,
                    'session_name' => $name,
                    'engine' => $engine,
                    'status' => 'pending',
                ]);
                $session->assignWebhookToken();
                $session->save();
            }

            ChannelAccount::firstOrCreate(
                ['workspace_id' => $workspaceId, 'channel' => 'whatsapp', 'phone_number_id' => $name],
                [
                    'provider' => 'whatsapp_web',
                    'display_name' => 'WhatsApp (personal)',
                    'status' => 'inactive',
                    'meta_json' => ['whatsapp_web_session_id' => $session->id],
                ],
            );

            return $session->fresh();
        });
    }

    /** Mark the session + ChannelAccount active and record the paired identity. */
    public function markActive(WhatsappWebSession $session, ?string $phoneE164, ?string $pushName): void
    {
        $session->update([
            'status' => 'active',
            'phone_e164' => $phoneE164 ?: $session->phone_e164,
            'push_name' => $pushName ?: $session->push_name,
            'last_qr' => null,
            'last_seen_at' => now(),
        ]);

        $label = $pushName ?: ($phoneE164 ? 'WhatsApp '.$phoneE164 : 'WhatsApp (personal)');

        ChannelAccount::where('workspace_id', $session->workspace_id)
            ->where('channel', 'whatsapp')
            ->where('phone_number_id', $session->session_name)
            ->update([
                'status' => 'active',
                'display_name' => mb_substr($label, 0, 128),
            ]);
    }

    /** Reflect a non-active engine status onto the local records. */
    public function syncStatus(WhatsappWebSession $session, string $status): void
    {
        $session->update(['status' => $status, 'last_seen_at' => now()]);

        if (in_array($status, ['failed', 'disconnected', 'stopped'], true)) {
            ChannelAccount::where('workspace_id', $session->workspace_id)
                ->where('channel', 'whatsapp')
                ->where('phone_number_id', $session->session_name)
                ->update(['status' => 'inactive']);
        }
    }

    /** Tear down: local records only (caller logs the engine out first). */
    public function disconnect(WhatsappWebSession $session, bool $hardDelete = false): void
    {
        $accounts = ChannelAccount::where('workspace_id', $session->workspace_id)
            ->where('channel', 'whatsapp')
            ->where('phone_number_id', $session->session_name);

        if ($hardDelete) {
            $accounts->delete();
            $session->delete();

            return;
        }

        $accounts->update(['status' => 'inactive']);
        $session->update(['status' => 'disconnected', 'last_qr' => null]);
    }
}
