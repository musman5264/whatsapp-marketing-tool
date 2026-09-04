<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A personal (WhatsApp Web) number received an incoming call. Dispatched once
 * per `call.received` webhook, after the call has been logged (and, when the
 * per-number toggle is on, auto-rejected). Drives the `call.received`
 * automation trigger.
 */
class CallReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $workspaceId,
        public int $contactId,
        public string $callId,
        public string $callType,
        public string $callerPhone,
    ) {}
}
