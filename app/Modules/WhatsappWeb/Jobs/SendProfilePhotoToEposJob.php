<?php

namespace App\Modules\WhatsappWeb\Jobs;

use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Services\External\EposExecutor;
use App\Modules\Shared\Models\Contact;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\EngineManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends the customer's own WhatsApp profile photo to EPOS (manifest action contact.photo_update) so the shop sees it
 * on the contact. Background only and soft-fail: a hidden photo, a failed lookup or any EPOS answer other than
 * "saved" just ends here; it never blocks a message and is never retried. EPOS spec: TOOL-contact-photo.md.
 */
class SendProfilePhotoToEposJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CAPABILITY = 'contact.photo_update';

    /** The most EPOS accepts after decoding. A bigger picture is skipped; WhatsApp's own are well under this. */
    public const MAX_BYTES = 600000;

    public int $tries = 1;

    public function __construct(
        public readonly int $contactId,
        public readonly int $sessionId,
    ) {}

    /** The workspace's EPOS link whose cached manifest lists the photo action, or null. */
    public static function integrationFor(int $workspaceId): ?ExternalIntegration
    {
        foreach (ExternalIntegration::where('workspace_id', $workspaceId)->where('type', 'epos')->orderBy('id')->get() as $integration) {
            foreach ($integration->manifest['capabilities'] ?? [] as $capability) {
                if (($capability['key'] ?? null) === self::CAPABILITY) {
                    return $integration;
                }
            }
        }

        return null;
    }

    public static function sentKey(int $contactId): string
    {
        return 'epos-photo-sent:'.$contactId;
    }

    public function handle(EngineManager $engines, EposExecutor $executor): void
    {
        $contact = Contact::find($this->contactId);
        $session = WhatsappWebSession::find($this->sessionId);
        $phone = $contact ? (string) $contact->phone_e164 : '';
        if (! $contact || ! $session || ! preg_match('/^\+[1-9]\d{6,14}$/', $phone) || Cache::has(self::sentKey($contact->id))) {
            return;
        }
        $integration = self::integrationFor((int) $contact->workspace_id);
        if (! $integration) {
            return;
        }

        try {
            $url = $engines->adapter()->profilePictureUrl($session->session_name, substr($phone, 1).'@c.us');
            $bytes = $url ? Http::timeout(10)->get($url) : null;
        } catch (\Throwable $e) {
            Log::info('whatsapp_web.epos_photo.lookup_failed', ['contact_id' => $contact->id, 'error' => $e->getMessage()]);

            return;
        }
        // Nothing readable (private, none, error) is never reported to EPOS as "removed": send nothing.
        if (! $bytes || ! $bytes->successful() || $bytes->body() === '' || strlen($bytes->body()) > self::MAX_BYTES) {
            return;
        }

        $param = collect($integration->manifest['capabilities'])->firstWhere('key', self::CAPABILITY)['params'] ?? [];
        $name = collect($param)->first(fn ($p) => ($p['from'] ?? '') === 'profile_photo' && ($p['type'] ?? '') === 'text')['name'] ?? 'photo';

        $result = $executor->execute($integration, self::CAPABILITY, $phone, [$name => base64_encode($bytes->body())], 'photo:'.$contact->id.':'.now()->toDateString());

        // Remembered for 30 days only when EPOS saved it; any refusal or failure lets a later message try again (the hook allows one try a day).
        if ($result['outcome'] === 'found') {
            Cache::put(self::sentKey($contact->id), 1, now()->addDays(30));
        }
    }
}
