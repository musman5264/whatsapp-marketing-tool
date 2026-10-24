<?php

namespace App\Modules\Integrations\Services\External;

/** A failed call to a linked system. The message is safe to show to the API caller: never holds a key or a body. */
class ExternalCallException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
