<?php

namespace App\Modules\Integrations\Services\Credentials;

/**
 * Connection details for the WhatsApp-Web engine (WAHA), configured once per
 * deployment in Admin → Integrations → WhatsApp Web. The webhook HMAC secret
 * lives on IntegrationConfig::webhook_secret (a separate encrypted column), so
 * it is injected here by the resolver rather than read from $data.
 */
class WhatsappWebCredentials extends CredentialValueObject
{
    /** waha (the only engine today). */
    public function engine(): string
    {
        return (string) ($this->get('engine') ?: 'waha');
    }

    public function baseUrl(): ?string
    {
        $url = $this->get('base_url');

        return $url ? rtrim((string) $url, '/') : null;
    }

    public function apiKey(): ?string
    {
        return $this->get('api_key');
    }

    public function webhookSecret(): ?string
    {
        return $this->get('webhook_secret');
    }
}
