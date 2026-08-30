<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

class PlivoDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $authId,
        private readonly string $authToken,
        private readonly string $from,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::withBasicAuth($this->authId, $this->authToken)
            ->timeout(15)
            ->post("https://api.plivo.com/v1/Account/{$this->authId}/Message/", [
                'src' => $opts['from'] ?? $this->from,
                'dst' => ltrim($to, '+'),
                'text' => $body,
            ]);

        if ($resp->successful()) {
            return new SmsSendResult(true, $resp->json()['message_uuid'][0] ?? '');
        }

        return new SmsSendResult(false, '', $resp->json()['error'] ?? 'Plivo error');
    }

    public function status(string $providerId): SmsStatus
    {
        $resp = Http::withBasicAuth($this->authId, $this->authToken)
            ->timeout(10)
            ->get("https://api.plivo.com/v1/Account/{$this->authId}/Message/{$providerId}/");

        $status = match ($resp->json()['message_state'] ?? '') {
            'delivered' => 'delivered',
            'sent' => 'sent',
            'failed', 'undelivered', 'rejected' => 'failed',
            default => 'queued',
        };

        return new SmsStatus($providerId, $status);
    }
}
