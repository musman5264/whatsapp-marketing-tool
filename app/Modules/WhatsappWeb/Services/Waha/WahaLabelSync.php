<?php

namespace App\Modules\WhatsappWeb\Services\Waha;

use App\Modules\Inbox\Models\ConversationActivity;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Shared\Models\Conversation;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\EngineManager;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mirrors the app's inbox labels onto WhatsApp's own chat labels via WAHA
 * (labels API, WhatsApp Business numbers only) and imports WhatsApp-side label
 * changes back from webhooks.
 *
 * Soft-fail everywhere: nothing here throws into the caller. When the engine
 * answers 4xx/501 (typically: not a Business number, or NOWEB without labels
 * support) the feature is parked for an hour per session.
 *
 * WAHA's PUT /labels/chats/{chatId} replaces the whole label set, so every push
 * is a UNION of the labels already on the chat (remote) and the locally mapped
 * labels, minus the label that was just removed locally.
 */
class WahaLabelSync
{
    /** Webhook events this class consumes. Wire them in WahaEventProcessor. */
    public const EVENTS = ['label.upsert', 'label.deleted', 'label.chat.added', 'label.chat.deleted'];

    private const UNSUPPORTED_TTL_SECONDS = 3600;

    private const DEFAULT_COLOR = '#6366f1';

    public function __construct(private readonly WahaClient $client) {}

    /** Builds a sync bound to the system WAHA engine, or null when none is configured. */
    public static function fromSystem(): ?self
    {
        $creds = app(EngineManager::class)->credentials();
        if (! $creds || ! $creds->baseUrl() || $creds->engine() !== 'waha') {
            return null;
        }

        return new self(new WahaClient($creds->baseUrl(), $creds->apiKey()));
    }

    /**
     * Makes sure the WhatsApp side has a label for this app label and stores its id
     * on inbox_labels.wa_label_id. Matches an existing remote label by name first.
     */
    public function ensureRemoteLabel(InboxLabel $label, string $session): ?string
    {
        try {
            if ($this->isUnsupported($session)) {
                return null;
            }

            if ($label->wa_label_id) {
                return (string) $label->wa_label_id;
            }

            $id = $this->findRemoteIdByName($session, $label->name);
            if ($id === null) {
                $resp = $this->client->post("/api/{$session}/labels", [
                    'name' => $label->name,
                    'colorHex' => $this->hexOrDefault($label->color),
                ]);
                if (! $resp->successful()) {
                    $this->handleFailure($session, $resp, 'create_label');

                    return null;
                }

                $id = $resp->json('id');
                $id = is_scalar($id) && (string) $id !== '' ? (string) $id : $this->findRemoteIdByName($session, $label->name);
            }

            if ($id === null) {
                return null;
            }

            $label->update(['wa_label_id' => $id]);
            $this->markSupported($session);

            return $id;
        } catch (Throwable $e) {
            $this->logFailure('ensure_label', $session, $e);

            return null;
        }
    }

    /**
     * Pushes the conversation's local labels to the WhatsApp chat.
     *
     * @param  InboxLabel|null  $removed  label that was just detached locally; it is
     *                                    dropped from the chat even if WhatsApp has it
     */
    public function syncConversationLabels(Conversation $conversation, ?InboxLabel $removed = null): bool
    {
        try {
            $session = $this->sessionFor($conversation);
            if (! $session || ! $session->mirror_wa_labels) {
                return false;
            }

            $name = (string) $session->session_name;
            if ($this->isUnsupported($name)) {
                return false;
            }

            $chatId = $this->chatIdFor($conversation);
            if ($chatId === null) {
                return false;
            }

            $get = $this->client->get("/api/{$name}/labels/chats/{$chatId}/");
            if (! $get->successful()) {
                $this->handleFailure($name, $get, 'get_chat_labels');

                return false;
            }
            $remoteIds = $this->idsFromLabelList($get->json());

            $removedId = $removed?->wa_label_id ? (string) $removed->wa_label_id : null;

            $localIds = [];
            foreach ($conversation->labels()->get() as $label) {
                $waId = $label->wa_label_id ?: $this->ensureRemoteLabel($label, $name);
                if ($waId !== null) {
                    $localIds[] = (string) $waId;
                }
            }

            $remoteKept = array_values(array_filter($remoteIds, fn ($id) => $id !== $removedId));
            $desired = array_values(array_unique(array_merge($remoteKept, $localIds)));

            $current = array_values(array_unique($remoteIds));
            sort($current);
            $target = $desired;
            sort($target);
            if ($current === $target) {
                return true;
            }

            $put = $this->client->put("/api/{$name}/labels/chats/{$chatId}/", [
                'labels' => array_map(fn ($id) => ['id' => $id], $desired),
            ]);
            if (! $put->successful()) {
                $this->handleFailure($name, $put, 'set_chat_labels');

                return false;
            }

            $this->markSupported($name);

            return true;
        } catch (Throwable $e) {
            $this->logFailure('sync_chat', $conversation->id ?? null, $e);

            return false;
        }
    }

    /**
     * Handles the four label webhooks. Changes coming from WhatsApp are applied
     * locally WITHOUT mirroring them back, so the two sides cannot echo forever.
     *
     * @param  array<string,mixed>  $payload  the full WAHA webhook body ({event, payload, ...})
     */
    public function handleWebhook(array $payload, string $session): void
    {
        try {
            $event = (string) ($payload['event'] ?? '');
            if (! in_array($event, self::EVENTS, true)) {
                return;
            }

            $ws = WhatsappWebSession::where('session_name', $session)->first();
            if (! $ws || ! $ws->mirror_wa_labels) {
                return;
            }

            $data = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

            match ($event) {
                'label.upsert' => $this->importLabel($ws, $data),
                'label.deleted' => $this->forgetRemoteLabel($ws, (string) ($data['id'] ?? '')),
                'label.chat.added', 'label.chat.deleted' => $this->applyChatLabel($ws, $event, $data),
            };
        } catch (Throwable $e) {
            $this->logFailure('webhook', $session, $e, ['event' => $payload['event'] ?? null]);
        }
    }

    /** @param array<string,mixed> $data */
    private function importLabel(WhatsappWebSession $ws, array $data): ?InboxLabel
    {
        $waId = trim((string) ($data['id'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($waId === '') {
            return null;
        }

        $labels = InboxLabel::where('workspace_id', $ws->workspace_id)->get();

        $local = $labels->firstWhere('wa_label_id', $waId);
        if ($local) {
            return $local;
        }

        if ($name === '') {
            return null;
        }

        $byName = $labels->first(fn (InboxLabel $l) => mb_strtolower(trim($l->name)) === mb_strtolower($name));
        if ($byName) {
            if (! $byName->wa_label_id) {
                $byName->update(['wa_label_id' => $waId]);
            }

            return $byName;
        }

        return InboxLabel::create([
            'workspace_id' => $ws->workspace_id,
            'name' => mb_substr($name, 0, 64),
            'color' => $this->hexOrDefault($data['colorHex'] ?? null),
            'wa_label_id' => $waId,
        ]);
    }

    private function forgetRemoteLabel(WhatsappWebSession $ws, string $waId): void
    {
        if ($waId === '') {
            return;
        }

        InboxLabel::where('workspace_id', $ws->workspace_id)
            ->where('wa_label_id', $waId)
            ->update(['wa_label_id' => null]);
    }

    /** @param array<string,mixed> $data */
    private function applyChatLabel(WhatsappWebSession $ws, string $event, array $data): void
    {
        $chatId = (string) ($data['chatId'] ?? '');
        $waLabelId = trim((string) ($data['labelId'] ?? ''));
        $digits = $this->digitsFromChatId($chatId);
        if ($digits === null || $waLabelId === '') {
            return;
        }

        $label = InboxLabel::where('workspace_id', $ws->workspace_id)->where('wa_label_id', $waLabelId)->first();
        if (! $label && is_array($data['label'] ?? null)) {
            $label = $this->importLabel($ws, $data['label']);
        }
        if (! $label) {
            return;
        }

        $conversations = Conversation::where('workspace_id', $ws->workspace_id)
            ->whereHas('contact', fn ($q) => $q->where('phone_e164', '+'.$digits))
            ->whereHas('channelAccount', fn ($q) => $q->where('phone_number_id', $ws->session_name))
            ->get();

        foreach ($conversations as $conversation) {
            if ($event === 'label.chat.added') {
                $changes = $conversation->labels()->syncWithoutDetaching([$label->id]);
                if (! empty($changes['attached'])) {
                    ConversationActivity::log($conversation, 'label_added', ['label' => $label->name, 'source' => 'whatsapp']);
                }
            } else {
                if ($conversation->labels()->detach($label->id)) {
                    ConversationActivity::log($conversation, 'label_removed', ['label' => $label->name, 'source' => 'whatsapp']);
                }
            }
        }
    }

    private function findRemoteIdByName(string $session, string $name): ?string
    {
        $resp = $this->client->get("/api/{$session}/labels");
        if (! $resp->successful()) {
            $this->handleFailure($session, $resp, 'list_labels');

            return null;
        }

        $wanted = mb_strtolower(trim($name));
        foreach (($resp->json() ?? []) as $remote) {
            if (is_array($remote) && mb_strtolower(trim((string) ($remote['name'] ?? ''))) === $wanted) {
                return isset($remote['id']) ? (string) $remote['id'] : null;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function idsFromLabelList(mixed $json): array
    {
        $ids = [];
        foreach (is_array($json) ? $json : [] as $row) {
            if (is_array($row) && isset($row['id']) && (string) $row['id'] !== '') {
                $ids[] = (string) $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function sessionFor(Conversation $conversation): ?WhatsappWebSession
    {
        $phoneId = $conversation->channelAccount?->phone_number_id;
        if (! $phoneId) {
            return null;
        }

        return WhatsappWebSession::where('workspace_id', $conversation->workspace_id)
            ->where('session_name', $phoneId)
            ->first();
    }

    private function chatIdFor(Conversation $conversation): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $conversation->contact?->phone_e164);

        return $digits !== '' ? $digits.'@c.us' : null;
    }

    private function digitsFromChatId(string $chatId): ?string
    {
        if (! str_ends_with($chatId, '@c.us')) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', explode('@', $chatId)[0]);

        return $digits !== '' ? $digits : null;
    }

    private function hexOrDefault(mixed $color): string
    {
        return is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? $color : self::DEFAULT_COLOR;
    }

    private function isUnsupported(string $session): bool
    {
        return Cache::get($this->unsupportedKey($session), false) === true;
    }

    private function unsupportedKey(string $session): string
    {
        return 'waha_labels_unsupported:'.$session;
    }

    private function markSupported(string $session): void
    {
        $ws = WhatsappWebSession::where('session_name', $session)->first();
        if ($ws && (($ws->meta_json['labels_supported'] ?? null) === false)) {
            $meta = $ws->meta_json ?? [];
            unset($meta['labels_supported'], $meta['labels_error_status']);
            $ws->update(['meta_json' => $meta]);
        }
    }

    /** 4xx or 501: the engine/account cannot do labels. Park the feature for an hour. */
    private function handleFailure(string $session, Response $resp, string $op): void
    {
        $status = $resp->status();
        $unsupported = ($status >= 400 && $status < 500) || $status === 501;

        if ($unsupported) {
            Cache::put($this->unsupportedKey($session), true, self::UNSUPPORTED_TTL_SECONDS);

            $ws = WhatsappWebSession::where('session_name', $session)->first();
            if ($ws) {
                $meta = $ws->meta_json ?? [];
                $meta['labels_supported'] = false;
                $meta['labels_error_status'] = $status;
                $ws->update(['meta_json' => $meta]);
            }
        }

        Log::warning('whatsapp_web.labels.failed', [
            'op' => $op,
            'session' => $session,
            'status' => $status,
            'body' => mb_substr((string) $resp->body(), 0, 300),
            'parked_for_seconds' => $unsupported ? self::UNSUPPORTED_TTL_SECONDS : 0,
        ]);
    }

    /** @param array<string,mixed> $context */
    private function logFailure(string $op, mixed $ref, Throwable $e, array $context = []): void
    {
        Log::warning('whatsapp_web.labels.exception', $context + [
            'op' => $op,
            'ref' => $ref,
            'error' => $e->getMessage(),
        ]);
    }
}
