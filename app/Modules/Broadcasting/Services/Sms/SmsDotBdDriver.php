<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

// Provider: sms.net.bd (https://sms.net.bd / api.sms.net.bd)
class SmsDotBdDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderId,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::timeout(15)->get('https://api.sms.net.bd/sendsms', [
            'api_key'  => $this->apiKey,
            'msg'      => $body,
            'to'       => ltrim($to, '+'),
            'sender_id' => $opts['from'] ?? $this->senderId ?: null,
        ]);

        $json = $resp->json();

        if ($resp->successful() && ($json['error'] ?? 1) == 0) {
            $msgId = (string) ($json['data']['batch_id'] ?? uniqid('smsbd_'));
            return new SmsSendResult(true, $msgId);
        }

        return new SmsSendResult(false, '', $json['msg'] ?? 'SMS.BD error: '.$resp->body());
    }

    public function status(string $providerId): SmsStatus
    {
        $resp = Http::timeout(10)->get('https://api.sms.net.bd/report/request/'.$providerId, [
            'api_key' => $this->apiKey,
        ]);

        if (! $resp->successful() || ($resp->json()['error'] ?? 1) != 0) {
            return new SmsStatus($providerId, 'sent');
        }

        $raw = strtolower($resp->json()['data']['status'] ?? '');

        $mapped = match (true) {
            str_contains($raw, 'delivrd') || str_contains($raw, 'delivered') => 'delivered',
            str_contains($raw, 'failed') || str_contains($raw, 'undeliv') || str_contains($raw, 'reject') => 'failed',
            str_contains($raw, 'sent') || str_contains($raw, 'submit') => 'sent',
            default => 'queued',
        };

        return new SmsStatus($providerId, $mapped);
    }
}
