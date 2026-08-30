<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

class TwilioDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly string $from,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::withBasicAuth($this->accountSid, $this->authToken)
            ->timeout(15)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json", [
                'To' => $to,
                'From' => $opts['from'] ?? $this->from,
                'Body' => $body,
            ]);

        if ($resp->successful()) {
            return new SmsSendResult(true, $resp->json()['sid'] ?? '');
        }

        return new SmsSendResult(false, '', $resp->json()['message'] ?? 'Twilio error');
    }

    public function status(string $providerId): SmsStatus
    {
        $resp = Http::withBasicAuth($this->accountSid, $this->authToken)
            ->timeout(10)
            ->get("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages/{$providerId}.json");

        $statusMap = ['queued' => 'queued', 'sent' => 'sent', 'delivered' => 'delivered', 'failed' => 'failed', 'undelivered' => 'failed'];
        $s = $statusMap[$resp->json()['status'] ?? ''] ?? 'queued';

        return new SmsStatus($providerId, $s);
    }
}
