<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Integrations\Services\Credentials\WhatsappWebCredentials;
use App\Modules\WhatsappWeb\Contracts\EngineAdapter;
use App\Modules\WhatsappWeb\Services\Waha\WahaAdapter;
use App\Modules\WhatsappWeb\Services\Waha\WahaClient;
use RuntimeException;

/**
 * Resolves the configured WhatsApp-Web engine adapter from the system-level
 * `whatsapp_web` IntegrationConfig. One engine per deployment.
 *
 * `enabled()` is what the UI checks to decide whether to show the QR tab.
 */
class EngineManager
{
    /** True when an admin has configured + enabled a usable engine. */
    public function enabled(): bool
    {
        $creds = $this->credentials();

        return $creds !== null && $creds->baseUrl() !== null;
    }

    public function credentials(): ?WhatsappWebCredentials
    {
        $vo = CredentialResolver::system()->whatsappWeb();
        if (! $vo) {
            return null;
        }

        return $vo;
    }

    /**
     * The active adapter.
     *
     * @throws RuntimeException when no engine is configured.
     */
    public function adapter(): EngineAdapter
    {
        $creds = $this->credentials();
        if (! $creds || ! $creds->baseUrl()) {
            throw new RuntimeException('The WhatsApp Web engine is not configured. Ask an administrator to set it up in Admin → Integrations → WhatsApp Web.');
        }

        return match ($creds->engine()) {
            'waha' => new WahaAdapter(new WahaClient($creds->baseUrl(), $creds->apiKey())),
            default => throw new RuntimeException("Unknown WhatsApp Web engine [{$creds->engine()}]."),
        };
    }
}
