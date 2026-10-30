<?php

namespace App\Modules\Integrations\Services\External;

use App\Events\MessageSent;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\WhatsappWeb\Services\WahaMediaStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * RentOyee asks the customer a question through POST /api/v1/messages/send ("your CNIC has expired, send the new date")
 * and sends a `reply_context` with it. The API stores that context on the contact (see key()). The customer's next
 * message is the answer: a text goes to customer.update_info, a photo to customer.update_cnic_photo, and the
 * `reply_text` of the answer is sent back to the chat. Anything that goes wrong is "not handled": the message continues
 * as before and the context stays. Spec: rentalLaravel/docs/integrations/e-whatsapp-tool/README.md §3 items 15-18.
 */
class ReplyContextHandler
{
    public const TEXT_CAPABILITY = 'customer.update_info';

    public const PHOTO_CAPABILITY = 'customer.update_cnic_photo';

    /** Spec §6 messages for the refusals that carry no reply_text of their own. */
    private const REASON_TEXT = [
        'request_closed' => 'That request is closed.',
        'field_not_allowed' => "I can't change that here.",
        'rate_limited' => 'Please try again in a little while.',
        'not_found' => 'I could not find your details. Please contact the rental office.',
        'ambiguous' => 'Your number is registered twice. Please contact the rental office.',
    ];

    public function __construct(
        private readonly EposExecutor $executor,
        private readonly ChannelManager $channels,
        private readonly WahaMediaStore $media,
    ) {}

    public static function key(int $contactId): string
    {
        return 'reply-context:'.$contactId;
    }

    /** @return bool true when RentOyee dealt with the message: the caller must stop the normal flow */
    public function handle(Message $message, Conversation $conversation, Contact $contact): bool
    {
        try {
            return $this->apply($message, $conversation, $contact);
        } catch (\Throwable $e) {
            Log::warning('external.reply_context_failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function apply(Message $message, Conversation $conversation, Contact $contact): bool
    {
        $context = Cache::get(self::key($contact->id));
        if ($message->getAttribute('direction') !== 'in' || ! is_array($context) || ($context['source'] ?? '') !== 'rentoyee' || empty($context['request_id'])) {
            return false;
        }
        $phone = preg_match('/^\+[1-9]\d{6,14}$/', (string) $contact->phone_e164) ? (string) $contact->phone_e164 : null;
        $integration = ExternalIntegration::where('workspace_id', $conversation->getAttribute('workspace_id'))->where('type', 'rentoyee')->orderBy('id')->first();
        if ($phone === null || ! $integration || $this->waitingForFlowAnswer($contact->id)) {
            return false; // the customer is answering a flow's question, or picked a menu entry: keep the context
        }

        $type = (string) $message->getAttribute('type');
        $params = ['request_id' => (string) $context['request_id']];
        $file = null;
        if ($type === 'text') {
            $capability = self::TEXT_CAPABILITY;
            $params['value'] = (string) $message->getAttribute('body');
        } elseif ($type === 'image' && ($file = $this->photo($message))) {
            $capability = self::PHOTO_CAPABILITY;
        } else {
            return false;
        }

        $result = $this->executor->execute($integration, $capability, $phone, $params, 'replyctx:'.($message->getAttribute('provider_message_id') ?: 'm'.$message->id), $file);
        if ($result['outcome'] === 'no_data') {
            return false;
        }

        $body = $result['body'];
        // Spec §3.16: stay open after status "invalid" so the customer can try again; close after anything final.
        if (in_array($body['status'] ?? null, ['applied', 'pending_approval', 'no_change'], true) || ($body['reason'] ?? null) === 'request_closed') {
            Cache::forget(self::key($contact->id));
        }
        $reply = trim((string) ($body['reply_text'] ?? '')) ?: (self::REASON_TEXT[$body['reason'] ?? ''] ?? '');
        if ($reply !== '') {
            $this->send($conversation, $reply);
        }

        return true;
    }

    private function waitingForFlowAnswer(int $contactId): bool
    {
        return AutomationRun::where('contact_id', $contactId)->where('status', 'waiting')->get()
            ->contains(fn ($run) => ! empty(($run->context ?? [])['_awaiting_reply']));
    }

    /** @return array{contents: string, filename: string}|null the photo as received (WhatsApp Web only: the Cloud API path has no link here) */
    private function photo(Message $message): ?array
    {
        $payload = $message->getAttribute('payload');
        $link = is_array($payload) ? ($payload['image']['link'] ?? null) : null;
        if (! is_string($link) || $link === '') {
            return null;
        }
        $stored = $this->media->store($message, $payload, $link);
        $contents = Storage::disk('local')->get($stored['media_path']);

        return $contents ? ['contents' => $contents, 'filename' => basename($stored['media_path'])] : null;
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
            Log::warning('external.reply_context_send_failed', ['message_id' => $out->id, 'error' => $e->getMessage()]);

            return;
        }

        $conversation->update(['last_message_at' => now()]);
        $out->load('conversation');
        MessageSent::dispatch($out);
    }
}
