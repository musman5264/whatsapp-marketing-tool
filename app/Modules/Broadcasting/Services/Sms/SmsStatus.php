<?php

namespace App\Modules\Broadcasting\Services\Sms;

class SmsStatus
{
    public function __construct(
        public readonly string $providerId,
        public readonly string $status,  // queued|sent|delivered|failed
        public readonly ?string $errorMessage = null,
    ) {}
}
