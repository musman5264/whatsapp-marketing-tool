<?php

namespace App\Modules\Integrations\Services\External;

use App\Events\MessageSent;
use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Support\Facades\Log;

/**
 * A delivery rider answers an order on the shop's WhatsApp number ("13827" then "delivered"). Before the normal
 * flow sees the message, EPOS is asked (manifest action delivery.rider_reply); when it deals with the message its
 * text is sent back to the sender and the normal flow does not run. Everything else, including every EPOS failure,
 * is "not handled": the message goes on as before and the sender sees nothing. The verified number is the contact's,
 * bound by the executor, never taken from the message text.
 */
class RiderReplyHandler
{
    public const CAPABILITY = 'delivery.rider_reply';

    public function __construct(
        private readonly EposExecutor $executor,
        private readonly ChannelManager $channels,
    ) {}

    /**
     * @return bool true when EPOS handled the message: the caller must stop the normal flow
     */
    public function handle(Message $message, Conversation $conversation, Contact $contact): bool
    {
        try {
            $answer = $this->ask($message, $conversation, $contact);
        } catch (\Throwable $e) {
            Log::warning('external.rider_reply_failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);

            return false;
        }
        if ($answer === null) {
            return false;
        }

        $reply = trim((string) ($answer['reply'] ?? ''));
        if ($reply !== '') {
            $this->send($conversation, $reply);
        }

        return true;
    }

    /** @return array<string, mixed>|null EPOS's body when it handled the message, otherwise null */
    private function ask(Message $message, Conversation $conversation, Contact $contact): ?array
    {
        $body = (string) $message->getAttribute('body');
        if ($message->getAttribute('direction') !== 'in' || $message->getAttribute('type') !== 'text' || ! preg_match('/\p{Nd}/u', $body)) {
            return null;
        }
        $phone = preg_match('/^\+[1-9]\d{6,14}$/', (string) $contact->phone_e164) ? (string) $contact->phone_e164 : null;
        if ($phone === null) {
            return null;
        }

        [$integration, $param] = $this->find((int) $conversation->getAttribute('workspace_id'));
        if (! $integration) {
            return null;
        }

        $max = min((int) ($param['max_length'] ?? EposExecutor::DEFAULT_TEXT), EposExecutor::MAX_TEXT);
        $result = $this->executor->execute(
            $integration,
            self::CAPABILITY,
            $phone,
            [$param['name'] => mb_substr($body, 0, $max)],
            'rider:'.($message->getAttribute('provider_message_id') ?: 'm'.$message->id),
        );

        $body = $result['body'];

        return ($body['handled'] ?? false) === true ? $body : null;
    }

    /**
     * The workspace's EPOS link that publishes the capability, and the manifest param that takes the raw message.
     *
     * @return array{0: ?ExternalIntegration, 1: ?array<string, mixed>}
     */
    private function find(int $workspaceId): array
    {
        $links = ExternalIntegration::where('workspace_id', $workspaceId)->where('type', 'epos')->orderBy('id')->get();
        foreach ($links as $integration) {
            foreach ($integration->manifest['capabilities'] ?? [] as $capability) {
                if (($capability['key'] ?? null) !== self::CAPABILITY) {
                    continue;
                }
                foreach ($capability['params'] ?? [] as $param) {
                    if (($param['from'] ?? '') === 'incoming_message' || ($param['name'] ?? '') === 'message') {
                        return [$integration, $param];
                    }
                }
            }
        }

        return [null, null];
    }

    private function send(Conversation $conversation, string $text): void
    {
        $out = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $text,
            'status' => 'queued',
            'sent_by' => 'automation',
            'sent_at' => now(),
        ]);

        try {
            $providerId = $this->channels->driver('whatsapp')->send($out);
            $out->update(['status' => 'sent', 'provider_message_id' => $providerId]);
        } catch (\Throwable $e) {
            $out->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);
            Log::warning('external.rider_reply_send_failed', ['message_id' => $out->id, 'error' => $e->getMessage()]);

            return;
        }

        $conversation->update(['last_message_at' => now()]);
        $out->load('conversation');
        MessageSent::dispatch($out);
    }
}
