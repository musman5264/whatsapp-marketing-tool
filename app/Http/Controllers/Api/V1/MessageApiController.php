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
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Services\StorageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class MessageApiController extends WorkspaceScopedController
{
    /** Message types that carry a file / URL instead of a text body. */
    private const MEDIA_TYPES = ['image', 'video', 'audio', 'document'];

    public function __construct(
        private ChannelManager $channelManager,
        private StorageManager $storageManager,
    ) {}

    /**
     * POST /api/v1/messages/send
     * Outbound transactional message to a known contact.
     *
     * Text / template:  application/json body.
     * Media (image / video / audio / document):  either multipart/form-data with
     * an `attachment` file, or JSON with a public `media_url`.
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'channel' => ['required', 'string', 'in:whatsapp,sms,email'],
            'type' => ['nullable', 'string', 'in:text,template,image,video,audio,document'],
            'body' => ['nullable', 'string'],
            'caption' => ['nullable', 'string', 'max:1024'],
            'filename' => ['nullable', 'string', 'max:255'],
            'media_url' => ['nullable', 'url', 'max:2048'],
            'template_name' => ['nullable', 'string'],
            'template_vars' => ['nullable', 'array'],
            'channel_account_id' => ['nullable', 'integer'],
            'attachment' => [
                'nullable', 'file', 'max:20480',
                'mimes:jpg,jpeg,png,webp,gif,mp4,3gp,mov,mp3,aac,m4a,amr,ogg,opus,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv',
            ],
        ]);

        $isMedia = in_array($validated['type'] ?? null, self::MEDIA_TYPES, true)
            || $request->hasFile('attachment')
            || ! empty($validated['media_url']);

        if ($isMedia) {
            if ($validated['channel'] !== 'whatsapp') {
                return response()->json(
                    ['error' => 'Media messages are only supported on the whatsapp channel.'],
                    422,
                );
            }
            if (! $request->hasFile('attachment') && empty($validated['media_url'])) {
                throw ValidationException::withMessages([
                    'attachment' => 'Provide an `attachment` file or a `media_url` for a media message.',
                ]);
            }
        }

        $wsId = $this->workspaceId($request);

        $contact = Contact::where('workspace_id', $wsId)->find($validated['contact_id']);
        if (! $contact) {
            return response()->json(['error' => 'Contact not found.'], 404);
        }

        $channel = $validated['channel'];

        try {
            $result = match ($channel) {
                'whatsapp' => $this->sendWhatsapp($request, $wsId, $contact, $validated, $isMedia),
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
    private function sendWhatsapp(Request $request, int $wsId, Contact $contact, array $payload, bool $isMedia): array
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
        $isWhatsappWeb = $account->provider === 'whatsapp_web';

        [$type, $body, $messagePayload] = $isMedia
            ? $this->buildMediaMessage($request, $wsId, $isWhatsappWeb, $payload)
            : [
                $isTemplate ? 'template' : 'text',
                $payload['body'] ?? null,
                $isTemplate ? $this->templatePayload($payload) : null,
            ];

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
            'type' => $type,
            'body' => $body,
            'payload' => $messagePayload,
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
        if ($isTemplate && $isWhatsappWeb) {
            $result['degraded_to_text'] = true;
        }

        return $result;
    }

    /**
     * Turn an uploaded file / media URL into a [type, body, payload] triple the
     * WhatsApp driver understands. Mirrors MobileConversationController::reply:
     * Cloud API needs an uploaded media_id, WhatsApp Web sends by public link.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function buildMediaMessage(Request $request, int $workspaceId, bool $isWhatsappWeb, array $payload): array
    {
        $caption = $payload['caption'] ?? $payload['body'] ?? null;

        $file = $request->file('attachment');
        $mimeType = $file?->getMimeType()
            ?? $this->guessMimeFromUrl($payload['media_url'] ?? '')
            ?? 'application/octet-stream';

        $type = $payload['type'] ?? null;
        if (! in_array($type, self::MEDIA_TYPES, true)) {
            $type = $this->mediaTypeFromMime($mimeType);
        }

        $messagePayload = [
            'caption' => $caption,
            'filename' => $payload['filename']
                ?? $file?->getClientOriginalName()
                ?? basename(parse_url($payload['media_url'] ?? '', PHP_URL_PATH) ?: 'file'),
        ];

        if ($file instanceof UploadedFile) {
            $storedPath = $this->storageManager->prefixedPath('message-media/'.$file->hashName());
            $this->storageManager->disk()->putFileAs(dirname($storedPath), $file, basename($storedPath));
            $publicUrl = $this->storageManager->disk()->url($storedPath);
            $messagePayload['preview_url'] = $publicUrl;

            if ($isWhatsappWeb) {
                $messagePayload['link'] = $publicUrl;
            } else {
                $client = CloudApiClient::forWorkspace($workspaceId);
                if (! $client) {
                    throw new \RuntimeException('No active WhatsApp Cloud API account for media upload.');
                }
                $messagePayload['media_id'] = $client->uploadMedia($file->getRealPath(), $mimeType);
            }
        } else {
            // media_url — hand the public link straight to the provider (Cloud API
            // accepts a `link`, WAHA downloads it).
            $messagePayload['link'] = $payload['media_url'];
            $messagePayload['preview_url'] = $payload['media_url'];
        }

        $body = $caption ?? $messagePayload['filename'];

        return [$type, $body, array_filter($messagePayload, fn ($v) => $v !== null)];
    }

    private function mediaTypeFromMime(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'document',
        };
    }

    private function guessMimeFromUrl(string $url): ?string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'ogg', 'opus' => 'audio/ogg',
            'pdf' => 'application/pdf',
            default => null,
        };
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
