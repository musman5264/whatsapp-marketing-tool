<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Events\MessageSent;
use App\Models\User;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Services\WebhookDispatchService;
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

    public function handle(ChannelManager $channelManager, WebhookDispatchService $webhooks): void
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

            $this->notifySubscribers($webhooks, $message->refresh(), 'message.failed');

            return;
        }

        $message->update(['status' => 'sent', 'provider_message_id' => $providerMessageId]);
        $message->conversation->update(['last_message_at' => now()]);

        MessageSent::dispatch($message->load('conversation'));

        $this->notifySubscribers($webhooks, $message, 'message.sent');

        Log::channel('json')->info('api.message.sent', [
            'message_id' => $message->id,
            'channel_account_id' => $message->conversation->getAttribute('channel_account_id'),
            'duration_ms' => $this->elapsedMs($startedAt),
        ]);
    }

    /**
     * Tell the webhook endpoints of the API key's user how the send ended. Soft-fail: a webhook problem must
     * never change the outcome of the send itself.
     */
    private function notifySubscribers(WebhookDispatchService $webhooks, Message $message, string $event): void
    {
        try {
            $user = $message->getAttribute('user_id') ? User::find($message->getAttribute('user_id')) : null;
            if (! $user) {
                return;
            }

            $webhooks->dispatch($user, $event, [
                'event' => $event,
                'message' => [
                    'id' => $message->id,
                    'conversation_id' => $message->getAttribute('conversation_id'),
                    'channel' => $message->getAttribute('channel'),
                    'to' => data_get($message, 'conversation.contact.phone_e164'),
                    'status' => $message->getAttribute('status'),
                    'provider_message_id' => $message->getAttribute('provider_message_id'),
                    'error' => $message->error_json['message'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::channel('json')->warning('api.message.webhook_failed', [
                'message_id' => $message->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
