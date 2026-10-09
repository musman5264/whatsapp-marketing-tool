<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Modules\WhatsappWeb\Models\WhatsappWebSession;

/**
 * Re-registers this app's webhook (URL, subscribed events, HMAC secret) on the
 * engine session. Safe to call repeatedly. It never logs the number out: WAHA
 * keeps the linked device, it just restarts the session with the new config.
 */
class WhatsappWebResubscriber
{
    public function __construct(private readonly EngineManager $engines) {}

    public function resubscribe(WhatsappWebSession $session): void
    {
        $url = route('webhooks.whatsapp-web.receive', ['token' => $session->webhook_token]);

        $this->engines->adapter()->resubscribe(
            $session->session_name,
            $url,
            $this->engines->credentials()?->webhookSecret(),
        );
    }
}
