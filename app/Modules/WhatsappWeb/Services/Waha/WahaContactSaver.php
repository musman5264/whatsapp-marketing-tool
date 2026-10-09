<?php

namespace App\Modules\WhatsappWeb\Services\Waha;

use App\Modules\Shared\Models\Contact;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\EngineManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Saves a contact into the linked phone's address book via WAHA
 * (PUT /api/{session}/contacts/{chatId}, body {firstName, lastName}).
 *
 * WAHA docs: this only works where the engine can edit the address book; on the
 * WhatsApp Web path brand-new contacts may be rejected, and the docs suggest
 * repeating the request a few seconds later. So this is strictly soft-fail:
 * it never throws, it logs the engine response, and it records the outcome on
 * the contact so it is attempted once per contact, not on every message.
 *
 * Recorded on contact.custom_fields:
 *   saved_to_phone_at  ISO timestamp on success
 *   phone_save_error   last error text on failure (the attempt is then final)
 */
class WahaContactSaver
{
    /** Seconds to wait before the single retry. Public so tests can set it to 0. */
    public static int $retryDelaySeconds = 2;

    public function __construct(private readonly WahaClient $client) {}

    /** Builds a saver bound to the system WAHA engine, or null when none is configured. */
    public static function fromSystem(): ?self
    {
        $creds = app(EngineManager::class)->credentials();
        if (! $creds || ! $creds->baseUrl() || $creds->engine() !== 'waha') {
            return null;
        }

        return new self(new WahaClient($creds->baseUrl(), $creds->apiKey()));
    }

    /**
     * @return bool true when the contact is (now) recorded as saved to the phone
     */
    public function saveToPhone(WhatsappWebSession $session, Contact $contact): bool
    {
        try {
            $contact = $contact->fresh() ?? $contact;
            $cf = $contact->custom_fields ?? [];

            if (! empty($cf['saved_to_phone_at'])) {
                return true;
            }
            if (array_key_exists('phone_save_error', $cf)) {
                return false;
            }

            $digits = preg_replace('/\D+/', '', (string) $contact->phone_e164);
            if ($digits === '') {
                return false;
            }

            $chatId = $digits.'@c.us';
            [$firstName, $lastName] = $this->nameParts($contact, $cf, $digits);
            $path = "/api/{$session->session_name}/contacts/{$chatId}";

            $error = null;
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                if ($attempt === 2) {
                    if (self::$retryDelaySeconds > 0) {
                        sleep(self::$retryDelaySeconds);
                    }
                }

                $resp = $this->client->put($path, ['firstName' => $firstName, 'lastName' => $lastName]);

                Log::info('whatsapp_web.contact_save.response', [
                    'session' => $session->session_name,
                    'contact_id' => $contact->id,
                    'attempt' => $attempt,
                    'status' => $resp->status(),
                    'body' => Str::limit((string) $resp->body(), 300),
                ]);

                if ($resp->successful()) {
                    $cf['saved_to_phone_at'] = now()->toIso8601String();
                    unset($cf['phone_save_error']);
                    $contact->update(['custom_fields' => $cf]);

                    return true;
                }

                $error = 'HTTP '.$resp->status().': '.Str::limit((string) $resp->body(), 300);
            }

            return $this->recordError($contact, $cf, $error ?? 'unknown error');
        } catch (Throwable $e) {
            Log::warning('whatsapp_web.contact_save.exception', [
                'session' => $session->session_name,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);

            try {
                $cf = $contact->custom_fields ?? [];

                return $this->recordError($contact, $cf, 'exception: '.$e->getMessage());
            } catch (Throwable) {
                return false;
            }
        }
    }

    /** @param array<string,mixed> $cf */
    private function recordError(Contact $contact, array $cf, string $error): bool
    {
        $cf['phone_save_error'] = Str::limit($error, 500);
        $contact->update(['custom_fields' => $cf]);

        Log::warning('whatsapp_web.contact_save.failed', [
            'contact_id' => $contact->id,
            'error' => Str::limit($error, 300),
        ]);

        return false;
    }

    /**
     * Name precedence: contact first/last name, then the WhatsApp push name, then
     * the phone number itself.
     *
     * @param  array<string,mixed>  $cf
     * @return array{0:string,1:string}
     */
    private function nameParts(Contact $contact, array $cf, string $digits): array
    {
        $first = trim((string) $contact->first_name);
        $last = trim((string) $contact->last_name);

        if ($first === '' && $last === '') {
            $push = trim((string) ($cf['wa_push_name'] ?? ''));
            if ($push !== '') {
                $parts = preg_split('/\s+/u', $push, 2) ?: [$push];

                return [$parts[0], $parts[1] ?? ''];
            }

            return ['+'.$digits, ''];
        }

        return [$first !== '' ? $first : $last, $first !== '' ? $last : ''];
    }
}
