<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

// Provider: MimSMS (https://mimsms.com)
class MimSmsDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderId,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::timeout(15)->post('https://mimsms.com/smsapi', [
            'api_key'           => $this->apiKey,
            'senderid'          => $opts['from'] ?? $this->senderId,
            'number'            => ltrim($to, '+'),
            'message'           => $body,
            'type'              => 'text',
            'scheduledDateTime' => '',
        ]);

        $json = $resp->json();
        $code = $json['response_code'] ?? null;

        if ($resp->successful() && in_array($code, [200, 202], true)) {
            $msgId = (string) ($json['message_id'] ?? $json['data'][0]['message_id'] ?? uniqid('mim_'));
            return new SmsSendResult(true, $msgId);
        }

        return new SmsSendResult(false, '', $json['success_message'] ?? $json['error_message'] ?? "MimSMS error (code: {$code})");
    }

    public function status(string $providerId): SmsStatus
    {
        $resp = Http::timeout(10)->get('https://mimsms.com/smsapi/report', [
            'api_key'    => $this->apiKey,
            'message_id' => $providerId,
        ]);

        if (! $resp->successful()) {
            return new SmsStatus($providerId, 'sent');
        }

        $raw = strtolower($resp->json()['status'] ?? $resp->json()['delivery_status'] ?? '');

        $mapped = match (true) {
            str_contains($raw, 'delivrd') || str_contains($raw, 'delivered') => 'delivered',
            str_contains($raw, 'failed') || str_contains($raw, 'undeliv') || str_contains($raw, 'reject') => 'failed',
            str_contains($raw, 'sent') || str_contains($raw, 'submit') => 'sent',
            default => 'queued',
        };

        return new SmsStatus($providerId, $mapped);
    }
}
