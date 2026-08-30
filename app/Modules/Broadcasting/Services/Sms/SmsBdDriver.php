<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

class SmsBdDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderId,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::timeout(15)->get('https://api.smsbd.com/api/v2/SendSMS', [
            'ApiKey' => $this->apiKey,
            'sender' => $opts['from'] ?? $this->senderId,
            'number' => ltrim($to, '+'),
            'message' => $body,
        ]);

        $msgId = $resp->json('Message_ID') ?? $resp->json('msgid') ?? null;

        return $resp->successful() && $msgId
            ? new SmsSendResult(true, (string) $msgId)
            : new SmsSendResult(false, '', 'SMSBD error: '.$resp->status());
    }

    public function status(string $providerId): SmsStatus
    {
        $resp = Http::timeout(10)->get('https://api.smsbd.com/api/v2/MessageReport', [
            'ApiKey' => $this->apiKey,
            'MessageID' => $providerId,
        ]);

        if (! $resp->successful()) {
            return new SmsStatus($providerId, 'sent');
        }

        $raw = strtolower($resp->json('Delivery_Status') ?? $resp->json('status') ?? '');

        $mapped = match (true) {
            str_contains($raw, 'delivrd') || str_contains($raw, 'delivered') => 'delivered',
            str_contains($raw, 'failed') || str_contains($raw, 'undeliv') || str_contains($raw, 'rejectd') => 'failed',
            str_contains($raw, 'sent') || str_contains($raw, 'accept') => 'sent',
            default => 'queued',
        };

        return new SmsStatus($providerId, $mapped);
    }
}
