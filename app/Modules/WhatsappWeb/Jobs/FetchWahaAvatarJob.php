<?php

namespace App\Modules\WhatsappWeb\Jobs;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Services\ContactService;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\EngineManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Gives a contact their WhatsApp profile picture when they have no photo of their own. WhatsApp's link
 * expires, so the picture is downloaded and kept. Soft-fail: a missing picture, a hidden one or an engine
 * error just leaves the initials; it never touches a saved photo and never blocks a message.
 */
class FetchWahaAvatarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $contactId,
        public readonly int $sessionId,
    ) {}

    public function handle(EngineManager $engines, ContactService $contacts): void
    {
        $contact = Contact::find($this->contactId);
        $session = WhatsappWebSession::find($this->sessionId);
        $digits = $contact ? preg_replace('/\D+/', '', (string) $contact->phone_e164) : '';
        if (! $contact || ! $session || $contact->avatar || $digits === '') {
            return;
        }

        try {
            $url = $engines->adapter()->profilePictureUrl($session->session_name, $digits.'@c.us');
        } catch (\Throwable $e) {
            Log::info('whatsapp_web.avatar.lookup_failed', ['contact_id' => $contact->id, 'error' => $e->getMessage()]);

            return;
        }

        if ($url !== null) {
            $contacts->downloadAndStoreAvatar($contact, $url);
        }
    }
}
