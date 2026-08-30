<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Provider: Fast2SMS (https://www.fast2sms.com) — India-only SMS gateway
class Fast2SmsDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderId = 'FSTSMS',
        private readonly string $route = 'q',
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        // Fast2SMS expects 10-digit Indian mobile numbers (no country code)
        $number = preg_replace('/^\+?91/', '', ltrim($to, '+'));

        try {
            $resp = Http::withHeaders(['authorization' => $this->apiKey])
                ->timeout(15)
                ->asForm()
                ->post('https://www.fast2sms.com/dev/bulkV2', [
                    'sender_id' => $opts['from'] ?? $this->senderId,
                    'message'   => $body,
                    'language'  => 'english',
                    'route'     => $this->route,
                    'numbers'   => $number,
                ]);
        } catch (\Throwable $e) {
            Log::error('Fast2SMS HTTP error', ['error' => $e->getMessage(), 'to' => $to]);
            return new SmsSendResult(false, '', 'Fast2SMS connection error: '.$e->getMessage());
        }

        $json = $resp->json() ?? [];

        if ($resp->successful() && ($json['return'] ?? false)) {
            return new SmsSendResult(true, $json['request_id'] ?? uniqid('f2s_'));
        }

        $msg = $json['message'] ?? ('Fast2SMS HTTP '.$resp->status());
        $error = is_array($msg) ? implode(', ', $msg) : (string) $msg;
        Log::warning('Fast2SMS send failed', ['to' => $to, 'error' => $error, 'status' => $resp->status()]);

        return new SmsSendResult(false, '', $error);
    }

    public function status(string $providerId): SmsStatus
    {
        // Fast2SMS does not provide a per-message pull status API
        return new SmsStatus($providerId, 'sent');
    }
}
