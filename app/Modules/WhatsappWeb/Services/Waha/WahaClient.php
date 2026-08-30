<?php

namespace App\Modules\WhatsappWeb\Services\Waha;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper around a WAHA instance. Base URL + API key come from the
 * `whatsapp_web` IntegrationConfig (system level). Works against both the free
 * `devlikeapro/waha` image (single session) and `devlikeapro/waha-plus`
 * (multi-session) — the REST surface is identical.
 */
class WahaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
    ) {}

    /** @param array<string,mixed> $query */
    public function get(string $path, array $query = []): Response
    {
        return $this->http()->get($this->url($path), $query);
    }

    /**
     * GET a binary payload (e.g. a QR PNG). Does not send Accept: application/json
     * so WAHA returns the image rather than a JSON wrapper.
     *
     * @param array<string,mixed> $query
     */
    public function getBinary(string $path, array $query = []): Response
    {
        $req = Http::timeout(20);
        if ($this->apiKey) {
            $req = $req->withHeaders(['X-Api-Key' => $this->apiKey]);
        }

        return $req->get($this->url($path), $query);
    }

    /** @param array<string,mixed> $body */
    public function post(string $path, array $body = []): Response
    {
        return $this->http()->post($this->url($path), $body);
    }

    /** @param array<string,mixed> $body */
    public function delete(string $path, array $body = []): Response
    {
        return $this->http()->delete($this->url($path), $body);
    }

    /** WAHA health/status probe — used by the admin "Test connection" button. */
    public function ping(): Response
    {
        return $this->http()->get($this->url('/api/server/status'));
    }

    private function http(): PendingRequest
    {
        $req = Http::acceptJson()->asJson()->timeout(20);

        return $this->apiKey ? $req->withHeaders(['X-Api-Key' => $this->apiKey]) : $req;
    }

    private function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }
}
