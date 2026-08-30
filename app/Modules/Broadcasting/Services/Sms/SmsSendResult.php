<?php

namespace App\Modules\Broadcasting\Services\Sms;

class SmsSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $messageId,
        public readonly string $error = '',
    ) {}
}
