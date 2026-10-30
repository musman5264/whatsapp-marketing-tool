<?php

namespace App\Http\Controllers\Api\V1;

use App\Modules\Broadcasting\Services\Sms\SmsDriverManager;
use App\Modules\Integrations\Services\External\ReplyContextHandler;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Exceptions\WhatsappWindowClosedException;
use App\Modules\Whatsapp\Jobs\SendQueuedWhatsappMessageJob;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Services\StorageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class MessageApiController extends WorkspaceScopedController
{
    /** Message types that carry a file / URL instead of a text body. */
    private const MEDIA_TYPES = ['image', 'video', 'audio', 'document'];

    /** Seconds the send call waits for the background send to finish when the caller sends no `wait`. */
    private const DEFAULT_WAIT_SECONDS = 15;

    private const MAX_WAIT_SECONDS = 30;

    public function __construct(
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
            'type' => ['nullable', 'string', 'in:text,template,image,video,audio,document,poll'],
            'body' => ['nullable', 'required_if:type,poll', 'string'],
            // type=poll: `body` is the question. 2-12 options is WhatsApp's own poll limit.
            'poll_options' => ['required_if:type,poll', 'array', 'min:2', 'max:12'],
            'poll_options.*' => ['string', 'max:255'],
            'poll_multiple' => ['nullable', 'boolean'],
            'caption' => ['nullable', 'string', 'max:1024'],
            'filename' => ['nullable', 'string', 'max:255'],
            'media_url' => ['nullable', 'url', 'max:2048'],
            'template_name' => ['nullable', 'string'],
            'template_vars' => ['nullable', 'array'],
            'channel_account_id' => ['nullable', 'integer'],
            'wait' => ['nullable', 'integer', 'between:0,'.self::MAX_WAIT_SECONDS],
            // A question for the customer (RentOyee): their next message is the answer. Stored on the contact below.
            'reply_context' => ['nullable', 'array'],
            'reply_context.source' => ['required_with:reply_context', 'string', 'in:rentoyee'],
            'reply_context.request_id' => ['required_with:reply_context', 'string', 'max:64'],
            'reply_context.field' => ['nullable', 'string', 'max:64'],
            'reply_context.expires_at' => ['required_with:reply_context', 'date', 'after:now'],
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

        if (! empty($validated['reply_context'])) {
            // The latest question wins; it lapses at expires_at (at most 30 days).
            // shortcut: kept in the cache, so a cache flush forgets pending questions; move to a column if that bites.
            $context = $validated['reply_context'];
            Cache::put(ReplyContextHandler::key($contact->id), $context, Carbon::parse($context['expires_at'])->min(now()->addDays(30)));
        }

        try {
            if ($channel === 'whatsapp') {
                // Queued: the provider call (WAHA / Cloud API) can be slow, so it runs
                // on the whatsapp queue and the caller gets 202 right away.
                $result = $this->queueWhatsapp($request, $wsId, $contact, $validated, $isMedia);

                return $this->outcomeResponse($result, (int) ($validated['wait'] ?? self::DEFAULT_WAIT_SECONDS));
            }

            $result = match ($channel) {
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
     * GET /api/v1/messages/{id}
     * Final outcome of a message sent through this API: queued, sent or failed.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        $message = Message::query()
            ->whereKey($id)
            ->where('direction', 'out')
            ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $wsId))
            ->first();

        if (! $message) {
            return response()->json(['error' => 'Message not found.'], 404);
        }

        return response()->json([
            'message_id' => $message->id,
            'conversation_id' => $message->getAttribute('conversation_id'),
            'status' => $message->getAttribute('status'),
            'provider_message_id' => $message->getAttribute('provider_message_id'),
            'error' => $message->error_json['message'] ?? null,
            'sent_at' => $message->getAttribute('sent_at')?->toIso8601String(),
        ]);
    }

    /**
     * Give the background send up to $waitSeconds to finish, then answer with what happened:
     * 200 sent (with provider_message_id), 422 failed (with the provider error), or 202 still queued.
     *
     * @param  array{message_id: int, degraded_to_text?: bool}  $result
     */
    private function outcomeResponse(array $result, int $waitSeconds): JsonResponse
    {
        $extra = isset($result['degraded_to_text']) ? ['degraded_to_text' => $result['degraded_to_text']] : [];

        if ($waitSeconds === 0) {
            return response()->json(array_merge(['status' => 'queued'], $result), 202);
        }

        $deadline = microtime(true) + $waitSeconds;

        while (true) {
            $message = Message::query()->find($result['message_id']);
            $status = $message?->getAttribute('status');

            if ($status === 'sent') {
                return response()->json(array_merge(
                    ['status' => 'sent', 'message_id' => $result['message_id'], 'provider_message_id' => $message->getAttribute('provider_message_id')],
                    $extra,
                ));
            }

            if ($status === 'failed') {
                return response()->json(array_merge(
                    ['status' => 'failed', 'message_id' => $result['message_id'], 'error' => $message->error_json['message'] ?? 'Send failed.'],
                    $extra,
                ), 422);
            }

            if (microtime(true) >= $deadline) {
                return response()->json(array_merge(['status' => 'queued'], $result), 202);
            }

            usleep(250_000);
        }
    }

    /**
     * Resolve the WhatsApp account, record an outbound message as `queued`, and
     * dispatch the send to the whatsapp queue. Validation, account resolution,
     * the 24-hour window check and media upload stay synchronous, so the caller
     * still gets a clear error for those. Provider failures are recorded on the
     * message row (status `failed`), not returned here.
     *
     * @param  array<string, mixed>  $payload
     * @return array{message_id: int, degraded_to_text?: bool}
     */
    private function queueWhatsapp(Request $request, int $wsId, Contact $contact, array $payload, bool $isMedia): array
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

        $isPoll = ($payload['type'] ?? null) === 'poll';
        if ($isPoll && ! $isWhatsappWeb) {
            throw new \RuntimeException('Polls are only supported on a WhatsApp Web (QR) number.');
        }

        [$type, $body, $messagePayload] = match (true) {
            $isMedia => $this->buildMediaMessage($request, $wsId, $isWhatsappWeb, $payload),
            $isPoll => ['poll', $payload['body'], ['poll' => [
                'question' => $payload['body'],
                'options' => array_values($payload['poll_options']),
                'multiple' => (bool) ($payload['poll_multiple'] ?? false),
            ]]],
            default => [
                $isTemplate ? 'template' : 'text',
                $payload['body'] ?? null,
                $isTemplate ? $this->templatePayload($payload) : null,
            ],
        };

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

        SendQueuedWhatsappMessageJob::dispatch($message->id)->onQueue('whatsapp');

        $result = ['message_id' => $message->id];

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
