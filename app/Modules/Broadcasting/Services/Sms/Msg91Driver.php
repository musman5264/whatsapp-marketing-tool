<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Provider: MSG91 (https://msg91.com) — India-focused SMS gateway with global reach
class Msg91Driver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $authKey,
        private readonly string $senderId = '',
        private readonly string $route = '4',
        private readonly string $dltTeId = '',
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        // MSG91 expects numbers with country code, no leading +
        $number = ltrim($to, '+');

        $sms = ['message' => $body, 'to' => [$number]];
        // DLT template ID is mandatory for Indian traffic on transactional/promotional routes
        if ($this->dltTeId !== '') {
            $sms['DLT_TE_ID'] = $this->dltTeId;
        }

        try {
            $resp = Http::withHeaders([
                'authkey'      => $this->authKey,
                'content-type' => 'application/json',
            ])
                ->timeout(15)
                ->post('https://api.msg91.com/api/v2/sendsms', [
                    'sender'  => $opts['from'] ?? $this->senderId,
                    'route'   => $this->route,
                    'country' => '0',
                    'sms'     => [$sms],
                ]);
        } catch (\Throwable $e) {
            Log::error('MSG91 HTTP error', ['error' => $e->getMessage(), 'to' => $to]);

            return new SmsSendResult(false, '', 'MSG91 connection error: '.$e->getMessage());
        }

        $json = $resp->json() ?? [];

        if ($resp->successful() && ($json['type'] ?? '') === 'success') {
            return new SmsSendResult(true, (string) ($json['message'] ?? uniqid('msg91_')));
        }

        $error = (string) ($json['message'] ?? ('MSG91 HTTP '.$resp->status()));
        Log::warning('MSG91 send failed', ['to' => $to, 'error' => $error, 'status' => $resp->status()]);

        return new SmsSendResult(false, '', $error);
    }

    public function status(string $providerId): SmsStatus
    {
        // MSG91 delivery reports arrive via configured webhook; no reliable pull API per message
        return new SmsStatus($providerId, 'sent');
    }
}
