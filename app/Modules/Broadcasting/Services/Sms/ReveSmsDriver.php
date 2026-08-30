<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

class ReveSmsDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $mask,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::timeout(15)->withHeaders([
            'apikey' => $this->apiKey,
            'apisecret' => $this->apiSecret,
        ])->post('https://smpp.revesoft.com/api/sms/', [
            'to' => ltrim($to, '+'),
            'mask' => $opts['from'] ?? $this->mask,
            'message' => $body,
        ]);

        $msgId = $resp->json()['message_id'] ?? $resp->json()['msg_id'] ?? null;

        return $resp->successful() && $msgId
            ? new SmsSendResult(true, (string) $msgId)
            : new SmsSendResult(false, '', 'REVE SMS error: '.$resp->body());
    }

    public function status(string $providerId): SmsStatus
    {
        $resp = Http::timeout(10)->withHeaders([
            'apikey' => $this->apiKey,
            'apisecret' => $this->apiSecret,
        ])->get('https://smpp.revesoft.com/api/report/', [
            'message_id' => $providerId,
        ]);

        if (! $resp->successful()) {
            return new SmsStatus($providerId, 'sent');
        }

        $raw = strtolower($resp->json()['status'] ?? $resp->json()['delivery_status'] ?? '');

        $mapped = match (true) {
            str_contains($raw, 'delivrd') || str_contains($raw, 'delivered') => 'delivered',
            str_contains($raw, 'failed') || str_contains($raw, 'undeliv') || str_contains($raw, 'reject') => 'failed',
            str_contains($raw, 'sent') || str_contains($raw, 'accept') => 'sent',
            default => 'queued',
        };

        return new SmsStatus($providerId, $mapped);
    }
}
