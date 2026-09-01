<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\MessageSent;
use App\Modules\Broadcasting\Services\Sms\SmsDriverManager;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Whatsapp\Exceptions\WhatsappWindowClosedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageApiController extends WorkspaceScopedController
{
    public function __construct(private ChannelManager $channelManager) {}

    /**
     * POST /api/v1/messages/send
     * Outbound transactional message to a known contact.
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'channel' => ['required', 'string', 'in:whatsapp,sms,email'],
            'type' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'template_name' => ['nullable', 'string'],
            'template_vars' => ['nullable', 'array'],
            'channel_account_id' => ['nullable', 'integer'],
        ]);

        $wsId = $this->workspaceId($request);

        $contact = Contact::where('workspace_id', $wsId)->find($validated['contact_id']);
        if (! $contact) {
            return response()->json(['error' => 'Contact not found.'], 404);
        }

        $channel = $validated['channel'];

        try {
            $result = match ($channel) {
                'whatsapp' => $this->sendWhatsapp($request, $wsId, $contact, $validated),
                'sms' => ['provider_message_id' => $this->sendSms($wsId, $contact, $validated)],
                default => throw new \InvalidArgumentException("Channel '{$channel}' send not yet implemented."),
            };
        } catch (WhatsappWindowClosedException $e) {
            return response()->json(['error' => $e->getMessage(), 'window_closed' => true], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(array_merge(['status' => 'sent'], $result));
    }

    /**
     * Resolve the WhatsApp account, build a conversation + outbound message, and
     * send it through the shared WhatsApp driver — which branches Cloud API vs.
     * WhatsApp Web (WAHA) internally, exactly like the inbox and mobile paths.
     *
     * @param  array<string, mixed>  $payload
     * @return array{provider_message_id: string, degraded_to_text?: bool}
     */
    private function sendWhatsapp(Request $request, int $wsId, Contact $contact, array $payload): array
    {
        if (! $contact->phone_e164) {
            throw new \RuntimeException('Contact has no E.164 phone number.');
        }

        $account = $this->resolveWhatsappAccount($wsId, $contact, $payload['channel_account_id'] ?? null);
        if (! $account) {
            throw new \RuntimeException('No active WhatsApp channel account for this workspace.');
        }

        $conversation = Conversation::firstOrCreate(
            [
                'workspace_id' => $wsId,
                'contact_id' => $contact->id,
                'channel_account_id' => $account->id,
            ],
            ['status' => 'open'],
        );
        $conversation->setRelation('channelAccount', $account);

        $isTemplate = ! empty($payload['template_name']);

        // Cloud API enforces WhatsApp's 24-hour session window for non-template
        // free-form messages; WhatsApp Web personal numbers are exempt.
        if (! $isTemplate
            && $conversation->requiresWhatsappTemplateWindow()
            && ! $conversation->isWhatsappWindowOpen()) {
            throw new WhatsappWindowClosedException(
                'WhatsApp 24-hour session is closed. Use an approved template to re-engage this contact.'
            );
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => $isTemplate ? 'template' : 'text',
            'body' => $payload['body'] ?? null,
            'payload' => $isTemplate ? $this->templatePayload($payload) : null,
            'status' => 'queued',
            'sent_by' => 'automation',
            'user_id' => $request->user()->id,
            'sent_at' => now(),
        ]);

        try {
            $providerMessageId = (string) $this->channelManager->driver('whatsapp')->send($message);
        } catch (\Throwable $e) {
            $message->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);
            throw new \RuntimeException($e->getMessage());
        }

        $message->update(['status' => 'sent', 'provider_message_id' => $providerMessageId]);
        $conversation->update(['last_message_at' => now()]);

        $message->load('conversation');
        MessageSent::dispatch($message);

        $result = ['provider_message_id' => $providerMessageId];

        // A template sent through a WhatsApp Web account is rendered to plain text
        // by the driver — flag it so the caller knows it was not a real template.
        if ($isTemplate && $account->provider === 'whatsapp_web') {
            $result['degraded_to_text'] = true;
        }

        return $result;
    }

    /**
     * Build the Meta-shaped template payload the WhatsApp driver expects. Body
     * vars map to a single {type:body} component in the order given.
     *
     * @param  array<string, mixed>  $payload
     * @return array{template: array{name: string, language: string, components: array<int, array<string, mixed>>}}
     */
    private function templatePayload(array $payload): array
    {
        $vars = $payload['template_vars'] ?? [];
        $components = [];
        if (! empty($vars)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($v) => ['type' => 'text', 'text' => (string) $v],
                    array_values($vars),
                ),
            ];
        }

        return [
            'template' => [
                'name' => $payload['template_name'],
                'language' => 'en',
                'components' => $components,
            ],
        ];
    }

    /**
     * Pick the WhatsApp ChannelAccount to send from:
     *   1. an explicit channel_account_id (must be an active whatsapp account)
     *   2. the account of an existing conversation with this contact
     *   3. the workspace's active Cloud API account
     *   4. the workspace's active WhatsApp Web (WAHA) account
     */
    private function resolveWhatsappAccount(int $wsId, Contact $contact, ?int $channelAccountId): ?ChannelAccount
    {
        if ($channelAccountId) {
            return ChannelAccount::where('workspace_id', $wsId)
                ->where('channel', 'whatsapp')
                ->where('status', 'active')
                ->find($channelAccountId);
        }

        $existing = Conversation::where('workspace_id', $wsId)
            ->where('contact_id', $contact->id)
            ->whereHas('channelAccount', fn ($q) => $q->where('channel', 'whatsapp'))
            ->with('channelAccount')
            ->latest('id')
            ->first();
        if ($existing?->channelAccount) {
            return $existing->channelAccount;
        }

        $base = ChannelAccount::where('workspace_id', $wsId)
            ->where('channel', 'whatsapp')
            ->where('status', 'active');

        return (clone $base)->where('provider', '!=', 'whatsapp_web')->orderBy('id')->first()
            ?? (clone $base)->where('provider', 'whatsapp_web')->orderBy('id')->first();
    }

    private function sendSms(int $wsId, Contact $contact, array $payload): string
    {
        $phone = $contact->phone_e164;
        if (! $phone) {
            throw new \RuntimeException('Contact has no E.164 phone number.');
        }

        $driver = SmsDriverManager::forWorkspace($wsId);
        $result = $driver->send($phone, $payload['body'] ?? '');

        if (! $result->success) {
            throw new \RuntimeException('SMS send failed: '.$result->error);
        }

        return $result->messageId;
    }
}
