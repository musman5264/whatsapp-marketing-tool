<?php

namespace App\Modules\Broadcasting\Services\Sms;

interface SmsDriverInterface
{
    public function send(string $to, string $body, array $opts = []): SmsSendResult;

    public function status(string $providerId): SmsStatus;
}
