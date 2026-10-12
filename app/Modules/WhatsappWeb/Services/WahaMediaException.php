<?php

namespace App\Modules\WhatsappWeb\Services;

use RuntimeException;

/** A user-safe media failure with the HTTP status to answer with. Never carries credentials. */
class WahaMediaException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
