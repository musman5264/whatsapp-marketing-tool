<?php

namespace App\Events;

use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReactionReceived
{
    use Dispatchable, SerializesModels;

    /** @param Message $message the message that was reacted to */
    public function __construct(public Message $message, public string $emoji) {}
}
