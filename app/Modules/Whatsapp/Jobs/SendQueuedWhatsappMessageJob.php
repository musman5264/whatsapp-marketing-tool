<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Events\MessageSent;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends a WhatsApp message that the public API already recorded as `queued`.
 *
 * Runs off the HTTP request so a slow WAHA / Cloud API call cannot time out the
 * caller. Single attempt on purpose: a retry after a timeout may deliver the
 * same message twice. Failures are written to the message row (status `failed`).
 */
class SendQueuedWhatsappMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $messageId,
    ) {}

    public function handle(ChannelManager $channelManager): void
    {
        $message = Message::with(['conversation.contact', 'conversation.channelAccount'])->find($this->messageId);

        if (! $message || $message->getAttribute('status') !== 'queued') {
            return;
        }

        $startedAt = microtime(true);

        try {
            $providerMessageId = (string) $channelManager->driver('whatsapp')->send($message);
        } catch (\Throwable $e) {
            $message->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);

            Log::channel('json')->warning('api.message.send_failed', [
                'message_id' => $message->id,
                'channel_account_id' => $message->conversation->getAttribute('channel_account_id'),
                'duration_ms' => $this->elapsedMs($startedAt),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $message->update(['status' => 'sent', 'provider_message_id' => $providerMessageId]);
        $message->conversation->update(['last_message_at' => now()]);

        MessageSent::dispatch($message->load('conversation'));

        Log::channel('json')->info('api.message.sent', [
            'message_id' => $message->id,
            'channel_account_id' => $message->conversation->getAttribute('channel_account_id'),
            'duration_ms' => $this->elapsedMs($startedAt),
        ]);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
