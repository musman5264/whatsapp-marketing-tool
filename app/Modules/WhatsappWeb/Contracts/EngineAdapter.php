<?php

namespace App\Modules\WhatsappWeb\Contracts;

/**
 * A WhatsApp-Web engine: something that can pair a personal number by QR and
 * send/receive on its behalf. Implemented for WAHA now; a bundled Baileys
 * sidecar could implement this later without touching the driver or the UI.
 *
 * Session lifecycle: startSession → (poll getQr / getStatus) → active → logout.
 * Recipients are always passed as E.164 ("+<digits>"); the adapter converts
 * to/from the engine's own JID format.
 */
interface EngineAdapter
{
    /**
     * Idempotently create/start the engine session and point its webhook at
     * $webhookUrl. When $hmacSecret is given, the engine is told to sign each
     * webhook (so the app can verify authenticity).
     */
    public function startSession(string $session, string $webhookUrl, ?string $hmacSecret = null): void;

    /** Current QR as a data URI (image/png;base64). Null once paired or not in scan state. */
    public function getQr(string $session): ?string;

    /**
     * Normalised session status:
     * 'pending' | 'scan_qr' | 'connecting' | 'active' | 'failed' | 'stopped'.
     */
    public function getStatus(string $session): string;

    /**
     * The paired account once active, or null.
     *
     * @return array{phone_e164: ?string, push_name: ?string}|null
     */
    public function getMe(string $session): ?array;

    /**
     * Resolve a contact id to a real phone number + name. The id may be a LID
     * (`123@lid`) which hides the number in webhook payloads.
     *
     * @return array{phone_e164: ?string, name: ?string}|null
     */
    public function resolveContact(string $session, string $contactId): ?array;

    /** Unlink the device and delete the engine session. Best-effort. */
    public function logout(string $session): void;

    /** Send a plain-text message. Returns the engine message id. */
    public function sendText(string $session, string $toE164, string $body): string;

    /**
     * Send a media message by public URL.
     *
     * @param  string  $type  image|video|audio|document
     */
    public function sendMedia(
        string $session,
        string $toE164,
        string $type,
        string $url,
        ?string $caption,
        ?string $filename,
    ): string;

    /** Send a location pin. Returns the engine message id. */
    public function sendLocation(
        string $session,
        string $toE164,
        float $latitude,
        float $longitude,
        ?string $name,
        ?string $address,
    ): string;
}
