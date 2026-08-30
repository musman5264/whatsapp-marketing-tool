<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

class InfobipDriver implements SmsDriverInterface
{
    private readonly string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl,
        private readonly string $from,
    ) {
        // Infobip issues a per-account host, e.g. "xyz.api.infobip.com".
        // Accept it with or without scheme/trailing slash.
        $this->baseUrl = rtrim(preg_replace('#^https?://#', '', trim($baseUrl)), '/');
    }

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::withHeaders(['Authorization' => "App {$this->apiKey}"])
            ->timeout(15)
            ->post("https://{$this->baseUrl}/sms/2/text/advanced", [
                'messages' => [[
                    'from' => $opts['from'] ?? $this->from,
                    'destinations' => [['to' => ltrim($to, '+')]],
                    'text' => $body,
                ]],
            ]);

        if ($resp->successful()) {
            return new SmsSendResult(true, $resp->json()['messages'][0]['messageId'] ?? '');
        }

        return new SmsSendResult(false, '', $resp->json()['requestError']['serviceException']['text'] ?? 'Infobip error');
    }

    public function status(string $providerId): SmsStatus
    {
        $resp = Http::withHeaders(['Authorization' => "App {$this->apiKey}"])
            ->timeout(10)
            ->get("https://{$this->baseUrl}/sms/1/reports", ['messageId' => $providerId]);

        $status = match ($resp->json()['results'][0]['status']['groupName'] ?? '') {
            'DELIVERED' => 'delivered',
            'PENDING' => 'sent',
            'UNDELIVERABLE', 'REJECTED', 'EXPIRED' => 'failed',
            default => 'queued',
        };

        return new SmsStatus($providerId, $status);
    }
}
