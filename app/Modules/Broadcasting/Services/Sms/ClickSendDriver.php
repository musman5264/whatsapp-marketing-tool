<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

class ClickSendDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $username,
        private readonly string $apiKey,
        private readonly string $from,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::withBasicAuth($this->username, $this->apiKey)
            ->timeout(15)
            ->post('https://rest.clicksend.com/v3/sms/send', [
                'messages' => [[
                    'source' => 'php',
                    'from' => $opts['from'] ?? $this->from,
                    'to' => $to,
                    'body' => $body,
                ]],
            ]);

        $message = $resp->json()['data']['messages'][0] ?? [];

        // ClickSend returns HTTP 200 even for per-message errors; the message-level
        // status is "SUCCESS" only on accept.
        if ($resp->successful() && ($message['status'] ?? '') === 'SUCCESS') {
            return new SmsSendResult(true, (string) ($message['message_id'] ?? ''));
        }

        return new SmsSendResult(false, '', $message['status'] ?? $resp->json()['response_msg'] ?? 'ClickSend error');
    }

    public function status(string $providerId): SmsStatus
    {
        // ClickSend exposes no per-message status GET; delivery is reported via the
        // status webhook. Treat a known id as accepted here.
        return new SmsStatus($providerId, 'sent');
    }
}
