<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

class MessageBirdDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $originator,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::withToken($this->apiKey)
            ->timeout(15)
            ->post('https://rest.messagebird.com/messages', [
                'originator' => $opts['from'] ?? $this->originator,
                'recipients' => ltrim($to, '+'),
                'body' => $body,
            ]);

        if ($resp->successful()) {
            return new SmsSendResult(true, $resp->json()['id'] ?? '');
        }

        return new SmsSendResult(false, '', $resp->json()['errors'][0]['description'] ?? 'MessageBird error');
    }

    public function status(string $providerId): SmsStatus
    {
        $resp = Http::withToken($this->apiKey)->timeout(10)->get("https://rest.messagebird.com/messages/{$providerId}");
        $recipients = $resp->json()['recipients']['items'][0] ?? [];
        $status = match ($recipients['status'] ?? '') {
            'delivered' => 'delivered',
            'failed', 'undeliverable' => 'failed',
            default => 'sent',
        };

        return new SmsStatus($providerId, $status);
    }
}
