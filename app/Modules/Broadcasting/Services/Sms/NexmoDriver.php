<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

class NexmoDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $from,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::timeout(15)->post('https://rest.nexmo.com/sms/json', [
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
            'to' => ltrim($to, '+'),
            'from' => $opts['from'] ?? $this->from,
            'text' => $body,
        ]);

        $messages = $resp->json()['messages'] ?? [];
        $first = $messages[0] ?? [];

        if (($first['status'] ?? '9') === '0') {
            return new SmsSendResult(true, $first['message-id'] ?? '');
        }

        return new SmsSendResult(false, '', $first['error-text'] ?? 'Vonage error');
    }

    public function status(string $providerId): SmsStatus
    {
        return new SmsStatus($providerId, 'sent');
    }
}
