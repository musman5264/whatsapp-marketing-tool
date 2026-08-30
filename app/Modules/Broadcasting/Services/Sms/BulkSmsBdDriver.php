<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

class BulkSmsBdDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderId,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::timeout(15)->post('https://bulksmsbd.net/api/smsapi', [
            'api_key'  => $this->apiKey,
            'senderid' => $opts['from'] ?? $this->senderId,
            'number'   => ltrim($to, '+'),
            'message'  => $body,
        ]);

        $json = $resp->json();
        $code = $json['response_code'] ?? null;

        // BulkSMSBD returns 202 on success
        if ($resp->successful() && $code == 202) {
            $msgId = (string) ($json['data'][0]['message_id'] ?? $json['success_message'] ?? uniqid('bsbd_'));
            return new SmsSendResult(true, $msgId);
        }

        $error = $json['error_message'] ?? $json['success_message'] ?? "BulkSMSBD error (code: {$code})";
        return new SmsSendResult(false, '', $error);
    }

    public function status(string $providerId): SmsStatus
    {
        // BulkSMSBD does not expose a per-message status endpoint in the basic API
        return new SmsStatus($providerId, 'sent');
    }
}
