<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

class TelnyxDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $from,
        private readonly string $messagingProfileId = '',
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $payload = [
            'from' => $opts['from'] ?? $this->from,
            'to' => $to,
            'text' => $body,
        ];

        if ($this->messagingProfileId !== '') {
            $payload['messaging_profile_id'] = $this->messagingProfileId;
        }

        $resp = Http::withToken($this->apiKey)
            ->timeout(15)
            ->post('https://api.telnyx.com/v2/messages', $payload);

        if ($resp->successful()) {
            return new SmsSendResult(true, $resp->json()['data']['id'] ?? '');
        }

        return new SmsSendResult(false, '', $resp->json()['errors'][0]['detail'] ?? 'Telnyx error');
    }

    public function status(string $providerId): SmsStatus
    {
        $resp = Http::withToken($this->apiKey)
            ->timeout(10)
            ->get("https://api.telnyx.com/v2/messages/{$providerId}");

        $status = match ($resp->json()['data']['to'][0]['status'] ?? '') {
            'delivered' => 'delivered',
            'sent', 'sending' => 'sent',
            'delivery_failed', 'sending_failed', 'expired' => 'failed',
            default => 'queued',
        };

        return new SmsStatus($providerId, $status);
    }
}
