<?php

namespace App\Modules\WhatsappWeb\Listeners;

use App\Events\MessageReceived;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\Waha\WahaContactSaver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * On an inbound WhatsApp Web message, try to save the sender into the linked
 * phone's address book (once per contact, gated by the session toggle).
 * Never throws: the inbound pipeline must not depend on the engine's answer.
 */
class SaveContactToPhone
{
    public function handle(MessageReceived $event): void
    {
        try {
            $message = $event->message;
            if ($message->direction !== 'in' || $message->channel !== 'whatsapp') {
                return;
            }

            $conversation = $message->conversation;
            $contact = $conversation?->contact;
            $phoneId = $conversation?->channelAccount?->phone_number_id;
            if (! $contact || ! $phoneId) {
                return;
            }

            $session = WhatsappWebSession::where('workspace_id', $conversation->workspace_id)
                ->where('session_name', $phoneId)
                ->first();
            if (! $session || ! $session->auto_save_contacts) {
                return;
            }

            WahaContactSaver::fromSystem()?->saveToPhone($session, $contact);
        } catch (Throwable $e) {
            Log::warning('whatsapp_web.contact_save.listener_failed', ['error' => $e->getMessage()]);
        }
    }
}
