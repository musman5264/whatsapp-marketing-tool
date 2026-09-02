<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Events\ConversationAssigned;
use App\Events\MessageSent;
use App\Events\TypingChanged;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Notifications\ConversationHandoverNotification;
use App\Services\StorageManager;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function __construct(
        private ChannelManager $channelManager,
        private StorageManager $storageManager,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $userId = $request->user()->id;

        $conversations = Conversation::where('workspace_id', $workspaceId)
            ->with(['contact', 'channelAccount', 'lastMessage', 'labels'])
            ->when($request->folder === 'mine', fn ($q) => $q->where('assigned_user_id', $userId))
            ->when($request->folder === 'unassigned', fn ($q) => $q->whereNull('assigned_user_id'))
            ->when($request->channel, fn ($q) => $q->whereHas('channelAccount', fn ($q) => $q->where('channel', $request->channel)))
            ->when($request->account_id, fn ($q) => $q->where('channel_account_id', $request->account_id))
            ->when(! in_array($request->folder, ['resolved', 'snoozed'], true), fn ($q) => $q->where('status', 'open'))
            ->when($request->folder === 'resolved', fn ($q) => $q->where('status', 'resolved'))
            ->when($request->folder === 'snoozed', fn ($q) => $q->where('status', 'snoozed'))
            ->when($request->label, fn ($q) => $q->whereHas('labels', fn ($q) => $q->where('inbox_labels.id', $request->label)))
            ->orderByDesc('last_message_at')
            ->paginate(30)
            ->withQueryString();

        $labels = InboxLabel::where('workspace_id', $workspaceId)->orderBy('name')->get(['id', 'name', 'color']);
        $channelAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->orderBy('channel')
            ->orderBy('display_name')
            ->get(['id', 'channel', 'display_name', 'phone_number_id']);

        return Inertia::render('Inbox/Index', [
            'conversations' => $conversations,
            'filters' => $request->only('folder', 'channel', 'label', 'account_id'),
            'labels' => $labels,
            'channelAccounts' => $channelAccounts,
        ]);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        $this->authorise($request, $conversation);

        $conversation->load(['contact', 'channelAccount', 'labels']);
        $messages = $conversation->messages()->with('conversation')->orderBy('sent_at')->get();

        // Mark as read
        $conversation->update(['unread_count' => 0]);

        // Align UI with WhatsApp session rules (inbound-only window; see Conversation::isWhatsappWindowOpen).
        // isWhatsappWindowOpen() already returns true for non-Cloud-API conversations
        // (other channels + WhatsApp Web personal numbers).
        $conversation->setAttribute('is_whatsapp_window_open', $conversation->isWhatsappWindowOpen());

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $userId = $request->user()->id;
        $allLabels = InboxLabel::where('workspace_id', $workspaceId)->orderBy('name')->get(['id', 'name', 'color']);

        // Team members for agent assignment
        $teamMembers = User::where('workspace_id', $workspaceId)
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        // WhatsApp approved templates for the template picker (used when 24h session is closed)
        $whatsappTemplates = $conversation->requiresWhatsappTemplateWindow()
            ? WhatsappTemplate::where('workspace_id', $workspaceId)
                ->where('status', 'APPROVED')
                ->orderBy('name')
                ->get(['id', 'name', 'language', 'components'])
            : collect();

        // Pass conversation list so the left panel stays populated on the show page
        $filters = $request->only('folder', 'channel', 'label', 'account_id');
        $conversations = Conversation::where('workspace_id', $workspaceId)
            ->with(['contact', 'channelAccount', 'lastMessage', 'labels'])
            ->when(($filters['folder'] ?? null) === 'mine', fn ($q) => $q->where('assigned_user_id', $userId))
            ->when(($filters['folder'] ?? null) === 'unassigned', fn ($q) => $q->whereNull('assigned_user_id'))
            ->when($filters['channel'] ?? null, fn ($q, $ch) => $q->whereHas('channelAccount', fn ($q) => $q->where('channel', $ch)))
            ->when($filters['account_id'] ?? null, fn ($q, $aid) => $q->where('channel_account_id', $aid))
            ->when(! in_array($filters['folder'] ?? null, ['resolved', 'snoozed'], true), fn ($q) => $q->where('status', 'open'))
            ->when(($filters['folder'] ?? null) === 'resolved', fn ($q) => $q->where('status', 'resolved'))
            ->when(($filters['folder'] ?? null) === 'snoozed', fn ($q) => $q->where('status', 'snoozed'))
            ->when($filters['label'] ?? null, fn ($q, $lid) => $q->whereHas('labels', fn ($q) => $q->where('inbox_labels.id', $lid)))
            ->orderByDesc('last_message_at')
            ->paginate(30)
            ->withQueryString();

        $channelAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->orderBy('channel')
            ->orderBy('display_name')
            ->get(['id', 'channel', 'display_name', 'phone_number_id']);

        // Whether to show the Orders tab (Ecommerce module). Queried directly to
        // avoid a cross-module model import; table may not exist if module removed.
        $hasEcommerceStore = Schema::hasTable('ecommerce_stores')
            && DB::table('ecommerce_stores')
                ->where('workspace_id', $workspaceId)
                ->where('status', 'connected')
                ->exists();

        return Inertia::render('Inbox/Show', [
            'conversation' => $conversation,
            'messages' => $messages,
            'allLabels' => $allLabels,
            'conversations' => $conversations,
            'filters' => $filters,
            'teamMembers' => $teamMembers,
            'whatsappTemplates' => $whatsappTemplates,
            'channelAccounts' => $channelAccounts,
            'hasEcommerceStore' => $hasEcommerceStore,
        ]);
    }

    public function reply(Request $request, Conversation $conversation): JsonResponse|RedirectResponse
    {
        $this->authorise($request, $conversation);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4096'],
            'type' => ['nullable', 'in:text,template,image,document,video,audio'],
            'payload' => ['nullable', 'array'],
            // Allow-list of messaging media types (no HTML/SVG/executables).
            'attachment' => [
                'nullable', 'file', 'max:20480',
                'mimes:jpg,jpeg,png,webp,mp4,3gp,mov,mp3,aac,m4a,amr,ogg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt',
            ],
        ]);

        $msgType = $validated['type'] ?? 'text';
        $msgPayload = $validated['payload'] ?? null;

        // Handle direct file attachment (image / document sent from compose bar)
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $mimeType = $file->getMimeType() ?? 'application/octet-stream';

            // Derive type from MIME if not explicitly set
            if ($msgType === 'text') {
                $msgType = str_starts_with($mimeType, 'image/') ? 'image'
                    : (str_starts_with($mimeType, 'video/') ? 'video' : 'document');
            }

            $storedPath = $this->storageManager->prefixedPath('message-media/'.$file->hashName());
            $this->storageManager->disk()->putFileAs(dirname($storedPath), $file, basename($storedPath));
            $previewUrl = $this->storageManager->disk()->url($storedPath);

            $msgPayload = array_merge($msgPayload ?? [], [
                'preview_url' => $previewUrl,
                'caption' => $validated['body'] ?? null,
                'filename' => $file->getClientOriginalName(),
            ]);

            // Cloud API needs an uploaded media_id; WhatsApp Web (personal number)
            // sends by public URL, so skip the Meta upload for that provider.
            if ($conversation->channelAccount?->provider !== 'whatsapp_web') {
                $client = CloudApiClient::forWorkspace($conversation->workspace_id);
                if (! $client) {
                    return response()->json(['error' => 'No active WhatsApp account.'], 422);
                }
                $msgPayload['media_id'] = $client->uploadMedia($file->getRealPath(), $mimeType);
            } else {
                $msgPayload['link'] = $previewUrl;
            }

            // For image/document the 'body' shown in the chat is the caption or filename
            $validated['body'] = $validated['body'] ?? $file->getClientOriginalName();
        }

        // Require body for plain text messages
        if ($msgType === 'text' && empty($validated['body'])) {
            return back()->withErrors(['body' => 'Message body is required.']);
        }

        // Enforce 24h window for the WhatsApp Cloud API — templates bypass it, and
        // WhatsApp Web personal numbers are not subject to it at all.
        if ($conversation->requiresWhatsappTemplateWindow()
            && ! $conversation->isWhatsappWindowOpen()
            && $msgType !== 'template') {
            return back()->with('error', 'WhatsApp 24-hour session is closed. Use an approved template to re-engage this contact.');
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => $conversation->channelAccount?->channel ?? 'whatsapp',
            'type' => $msgType,
            'body' => $validated['body'],
            'payload' => $msgPayload,
            'status' => 'queued',
            'sent_by' => 'human',
            'user_id' => $request->user()->id,
            'sent_at' => now(),
        ]);

        // Send via the channel driver
        $channel = $conversation->channelAccount?->channel ?? 'whatsapp';
        $sendError = null;
        try {
            $driver = $this->channelManager->driver($channel);
            $messageId = $driver->send($message);
            $message->update(['status' => 'sent', 'provider_message_id' => $messageId]);
        } catch (\Throwable $e) {
            $sendError = $e->getMessage();
            Log::error('Inbox reply send failed', [
                'conversation_id' => $conversation->id,
                'channel' => $channel,
                'error' => $sendError,
            ]);
            $message->update(['status' => 'failed', 'error_json' => ['message' => $sendError]]);
        }

        $conversation->update(['last_message_at' => now()]);

        // SLA: set first_response_at on first outbound after inbound
        if ($conversation->last_inbound_at && ! $conversation->first_response_at) {
            $conversation->update(['first_response_at' => now()]);
        }

        // Re-load the relation so the broadcast event can resolve workspace_id
        $message->load('conversation');

        MessageSent::dispatch($message);

        if ($request->wantsJson()) {
            // Always return 200 so the UI can display the queued/failed bubble
            // immediately; the message status conveys delivery state.
            return response()->json([
                'message' => $message,
                'error' => $sendError,
            ]);
        }

        if ($sendError) {
            return back()->with('error', 'Message saved but failed to send: '.$sendError);
        }

        return back()->with('success', 'Message sent.');
    }

    /**
     * Share a connected-store product into the conversation as a rich image card
     * (product photo + caption) — WhatsApp sends one captioned image, Messenger /
     * Instagram send the photo as an attachment followed by the caption. Products
     * without a photo fall back to a plain text card. The product is looked up via
     * the query builder rather than the Ecommerce model so the Inbox stays
     * decoupled from that module (mirrors the hasEcommerceStore probe in show()).
     */
    public function shareProduct(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);

        $validated = $request->validate(['product_id' => ['required', 'integer']]);
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        // Join the store for its currency (external_meta) and domain (URL building),
        // still without importing the Ecommerce model so the Inbox stays decoupled.
        $product = Schema::hasTable('ecommerce_products')
            ? DB::table('ecommerce_products as p')
                ->leftJoin('ecommerce_stores as s', 's.id', '=', 'p.store_id')
                ->where('p.workspace_id', $workspaceId)
                ->where('p.id', $validated['product_id'])
                ->select('p.*', 's.external_meta as store_meta', 's.domain as store_domain')
                ->first()
            : null;

        abort_unless($product, 404, 'Product not found.');

        $channel = $conversation->channelAccount?->channel ?? 'whatsapp';

        // Free-form messages need an open 24h session on the WhatsApp Cloud API
        // (not applicable to WhatsApp Web personal numbers).
        if ($conversation->requiresWhatsappTemplateWindow() && ! $conversation->isWhatsappWindowOpen()) {
            return response()->json([
                'error' => 'WhatsApp 24-hour session is closed. Use an approved template to re-engage this contact.',
            ], 422);
        }

        $storeMeta = json_decode($product->store_meta ?? '', true) ?: [];
        $currency = (string) ($storeMeta['currency'] ?? '');
        $url = $this->productShareUrl($product);

        // WhatsApp renders bold (*…*); other channels show it literally, so only bold there.
        $caption = $this->formatProductMessage($product, currency: $currency, url: $url, bold: $channel === 'whatsapp');
        $image = $product->image_url ?: null;

        // Send the product photo as a real image on every channel (drivers handle the
        // per-channel rendering); fall back to text only when there is no photo.
        $useImage = (bool) $image;
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => $channel,
            'type' => $useImage ? 'image' : 'text',
            'body' => $caption,
            'payload' => $useImage ? ['link' => $image, 'preview_url' => $image, 'caption' => $caption] : null,
            'status' => 'queued',
            'sent_by' => 'human',
            'user_id' => $request->user()->id,
            'sent_at' => now(),
        ]);

        $sendError = null;
        try {
            $messageId = $this->channelManager->driver($channel)->send($message);
            $message->update(['status' => 'sent', 'provider_message_id' => $messageId]);
        } catch (\Throwable $e) {
            $sendError = $e->getMessage();
            Log::error('Inbox shareProduct send failed', [
                'conversation_id' => $conversation->id,
                'channel' => $channel,
                'error' => $sendError,
            ]);
            $message->update(['status' => 'failed', 'error_json' => ['message' => $sendError]]);
        }

        $conversation->update(['last_message_at' => now()]);
        if ($conversation->last_inbound_at && ! $conversation->first_response_at) {
            $conversation->update(['first_response_at' => now()]);
        }

        $message->load('conversation');
        MessageSent::dispatch($message);

        return response()->json(['message' => $message, 'error' => $sendError]);
    }

    /**
     * Format a product row into a message caption. The photo is sent as an image,
     * so the caption never includes the raw image URL.
     */
    private function formatProductMessage(object $product, string $currency = '', ?string $url = null, bool $bold = false): string
    {
        $name = trim((string) $product->name);
        $lines = [$bold ? '🛍️ *'.$name.'*' : '🛍️ '.$name];

        if ($product->price !== null && $product->price !== '') {
            // Trim trailing zeros so "9.99" stays but "10.00" shows as "10".
            $price = rtrim(rtrim(number_format((float) $product->price, 2, '.', ''), '0'), '.');
            $lines[] = 'Price: '.$this->currencyPrefix($currency).$price;
        }
        if (! empty($product->sku)) {
            $lines[] = 'SKU: '.$product->sku;
        }
        if (! empty($url)) {
            $lines[] = $url;
        }

        return implode("\n", $lines);
    }

    /**
     * Render a currency as a symbol when known (e.g. "USD" → "$"), otherwise the
     * ISO code with a trailing space, or "" when no currency is set.
     */
    private function currencyPrefix(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return '';
        }

        $symbols = [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'INR' => '₹',
            'AUD' => 'A$', 'CAD' => 'C$', 'NZD' => 'NZ$', 'BRL' => 'R$',
        ];

        return $symbols[$currency] ?? $currency.' ';
    }

    /**
     * Best-effort public storefront URL for a shared product, derived from the raw
     * platform payload. Shopify uses its published URL (or domain + handle); Woo
     * uses the product permalink. Returns null when none can be built.
     */
    private function productShareUrl(object $product): ?string
    {
        $raw = json_decode($product->raw ?? '', true);
        if (! is_array($raw)) {
            return null;
        }
        $domain = $product->store_domain ?? null;

        return match ($product->platform) {
            'shopify' => $raw['online_store_url']
                ?? (! empty($raw['handle']) && $domain ? "https://{$domain}/products/{$raw['handle']}" : null),
            'woocommerce' => ! empty($raw['permalink']) && filter_var($raw['permalink'], FILTER_VALIDATE_URL)
                ? $raw['permalink']
                : null,
            default => null,
        };
    }

    public function assign(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorise($request, $conversation);
        $request->validate(['user_id' => ['nullable', 'integer']]);

        $assignedTo = null;
        if ($request->user_id) {
            $assignedTo = User::where('workspace_id', $conversation->workspace_id)
                ->find($request->user_id);
            abort_unless($assignedTo, 422);
        }

        $conversation->update(['assigned_user_id' => $request->user_id]);
        ConversationAssigned::dispatch($conversation, $assignedTo);

        return back()->with('success', 'Conversation assigned.');
    }

    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);
        $request->validate(['is_typing' => ['required', 'boolean']]);

        broadcast(new TypingChanged($conversation, $request->user(), (bool) $request->is_typing))->toOthers();

        return response()->json(['ok' => true]);
    }

    public function updateStatus(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorise($request, $conversation);
        $request->validate(['status' => ['required', 'in:open,pending,resolved,snoozed']]);

        $updates = ['status' => $request->status];
        if ($request->status === 'resolved' && ! $conversation->resolved_at) {
            $updates['resolved_at'] = now();
        }
        $conversation->update($updates);

        return back()->with('success', 'Status updated.');
    }

    public function handover(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);
        $mode = $request->input('mode', 'human'); // 'human' or 'bot'

        $updates = ['assigned_to' => $mode];
        if ($mode === 'human' && ! $conversation->handover_at) {
            $updates['handover_at'] = now();
        }
        $conversation->update($updates);

        if ($mode === 'human') {
            $members = User::where('workspace_id', $conversation->workspace_id)->get();
            foreach ($members as $member) {
                $member->notify(new ConversationHandoverNotification($conversation, 'manual'));
            }
        }

        return response()->json(['ok' => true, 'assigned_to' => $mode]);
    }

    /**
     * Proxy / lazy-download inbound WhatsApp media.
     * Checks payload.preview_url first, then downloads from WhatsApp Graph API,
     * caches to local storage, updates the message, and redirects.
     */
    public function serveMedia(Request $request, Conversation $conversation, Message $message): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorise($request, $conversation);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);

        $payload = $message->payload ?? [];

        // Already cached locally — verify the file still exists before redirecting
        if (! empty($payload['preview_url'])) {
            $storagePath = "message-media/{$message->id}";
            $disk = $this->storageManager->disk();
            $files = $disk->files($this->storageManager->prefixedPath('message-media'));
            $cached = collect($files)->first(fn ($f) => str_starts_with($f, $this->storageManager->prefixedPath($storagePath)));

            if ($cached && $disk->exists($cached)) {
                return redirect($disk->url($cached));
            }

            // File missing — clear stale preview_url and fall through to re-download
            $payload = array_merge($payload, ['preview_url' => null]);
            $message->update(['payload' => $payload]);
        }

        // Resolve media ID from raw WhatsApp webhook payload
        $type = $message->type ?? 'image';
        $mediaId = $payload[$type]['id'] ?? $payload['media_id'] ?? null;

        if (! $mediaId) {
            abort(404, 'No media available.');
        }

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $client = CloudApiClient::forWorkspace($workspaceId);

        if (! $client) {
            abort(503, 'WhatsApp account not configured.');
        }

        try {
            ['url' => $downloadUrl, 'mime_type' => $mimeType] = $client->getMediaUrl($mediaId);
            $bytes = $client->downloadMedia($downloadUrl);
            $ext = explode('/', $mimeType)[1] ?? 'bin';
            $ext = str_replace(['jpeg'], ['jpg'], $ext);
            $filename = "message-media/{$message->id}.{$ext}";

            $filename = $this->storageManager->prefixedPath($filename);
            $this->storageManager->disk()->put($filename, $bytes);
            $previewUrl = $this->storageManager->disk()->url($filename);

            // Cache for next request
            $message->update(['payload' => array_merge($payload, ['preview_url' => $previewUrl, 'mime_type' => $mimeType])]);

            return redirect($previewUrl);
        } catch (\Throwable $e) {
            abort(502, 'Could not fetch media: '.$e->getMessage());
        }
    }

    /** Upload a media file to WhatsApp and return the media_id */
    public function uploadMedia(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);

        $request->validate(['file' => ['required', 'file', 'max:16384']]);

        $file = $request->file('file');
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $client = CloudApiClient::forWorkspace($workspaceId);
        if (! $client) {
            return response()->json(['error' => 'No active WhatsApp account.'], 422);
        }

        try {
            $mediaId = $client->uploadMedia($file->getRealPath(), $mimeType);

            // Store a local copy so the UI can display a preview (WhatsApp media IDs are not URLs)
            $path = $this->storageManager->prefixedPath('template-media/'.$file->hashName());
            $this->storageManager->disk()->putFileAs(dirname($path), $file, basename($path));
            $previewUrl = $this->storageManager->disk()->url($path);

            return response()->json(['media_id' => $mediaId, 'mime_type' => $mimeType, 'preview_url' => $previewUrl]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Return approved WhatsApp templates for the workspace (JSON) */
    public function templates(Request $request): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $templates = WhatsappTemplate::where('workspace_id', $workspaceId)
            ->where('status', 'APPROVED')
            ->orderBy('name')
            ->get(['id', 'name', 'language', 'category', 'components']);

        return response()->json($templates);
    }

    /** Search contacts for the new-conversation modal (JSON) */
    public function contactSearch(Request $request): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $q = $request->input('q', '');

        $contacts = Contact::where('workspace_id', $workspaceId)
            ->with('tags')
            ->when($q, fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('phone_e164', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->latest()
            ->limit(30)
            ->get(['id', 'first_name', 'last_name', 'phone_e164', 'email', 'country', 'avatar']);

        return response()->json($contacts->map(fn ($c) => array_merge($c->toArray(), [
            'avatar_url' => Demo::active() ? null : $c->avatar_url,
        ])));
    }

    /** Return active channel accounts for the workspace (JSON) */
    public function channelAccounts(Request $request): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $accounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->get(['id', 'channel', 'display_name', 'phone_number_id']);

        return response()->json($accounts);
    }

    /** Find or create a conversation, then redirect to it */
    public function startConversation(Request $request): RedirectResponse|JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'channel_account_id' => ['required', 'integer'],
            'body' => ['nullable', 'string', 'max:4096'],
        ]);

        $contact = Contact::where('workspace_id', $workspaceId)->findOrFail($validated['contact_id']);
        $channelAccount = ChannelAccount::where('workspace_id', $workspaceId)->findOrFail($validated['channel_account_id']);

        // Reuse the most recent open conversation for this contact + channel, or create a new one
        $conversation = Conversation::where('workspace_id', $workspaceId)
            ->where('contact_id', $contact->id)
            ->where('channel_account_id', $channelAccount->id)
            ->where('status', 'open')
            ->latest()
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'workspace_id' => $workspaceId,
                'contact_id' => $contact->id,
                'channel_account_id' => $channelAccount->id,
                'status' => 'open',
                'assigned_to' => 'human',
                'assigned_user_id' => $request->user()->id,
                'last_message_at' => now(),
            ]);
        }

        // Send the opening message if provided
        if (! empty($validated['body'])) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'channel' => $channelAccount->channel,
                'type' => 'text',
                'body' => $validated['body'],
                'status' => 'queued',
                'sent_by' => 'human',
                'user_id' => $request->user()->id,
                'sent_at' => now(),
            ]);

            try {
                $driver = $this->channelManager->driver($channelAccount->channel);
                $messageId = $driver->send($message);
                $message->update(['status' => 'sent', 'provider_message_id' => $messageId]);
            } catch (\Throwable $e) {
                Log::error('startConversation send failed', [
                    'conversation_id' => $conversation->id,
                    'channel' => $channelAccount->channel,
                    'error' => $e->getMessage(),
                ]);
                $message->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);
            }

            $conversation->update(['last_message_at' => now()]);
            $message->load('conversation');
            MessageSent::dispatch($message);
        }

        return redirect()->route('client.inbox.show', $conversation);
    }

    public function react(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $this->authorise($request, $conversation);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);

        $validated = $request->validate(['emoji' => ['required', 'string', 'max:16']]);

        $out = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => $conversation->channelAccount?->channel ?? 'whatsapp',
            'type' => 'reaction',
            'body' => $validated['emoji'],
            'payload' => ['target_message_id' => $message->id, 'emoji' => $validated['emoji']],
            'status' => 'queued',
            'sent_by' => 'human',
            'user_id' => $request->user()->id,
            'sent_at' => now(),
        ]);

        try {
            $id = $this->channelManager->driver($out->channel)->send($out);
            $out->update(['status' => 'sent', 'provider_message_id' => $id ?: null]);
        } catch (\Throwable $e) {
            $out->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    private function authorise(Request $request, Conversation $conversation): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $conversation->workspace_id === (int) $workspaceId, 403);
    }
}
