# Inbox speed, live updates and media Implementation Plan

> **For agentic workers:** Executed inline in the main session (no subagents, per the harness rule). Steps use checkbox (`- [ ]`) syntax for tracking. Tracker: PLAN-001 / phase-01. Tasks: T-0010, T-0011, T-0012, T-0013. T-0014 stays blocked on the user's live-server checks.

**Goal:** The inbox opens fast, shows the newest messages and conversations first, loads older ones on demand while keeping the reading position, pushes new WhatsApp Web media to open chats the moment it is stored, and reserves space for media so the chat does not jump.

**Architecture:** WAHA media is fetched and stored right after the inbound message is saved and announced (inside the same after-response step the webhook already uses). The message list and the conversation list are paged server-side with a stable cursor (`sent_at`, `id`), and the browser appends pages as the reader scrolls. A new `MessageMediaReady` event updates the bubble in an open chat. The old `serveMedia` path stays as a fallback for anything not yet stored.

**Tech Stack:** Laravel 12 (PHP 8.2+), Inertia + React (JSX), Pusher/Reverb broadcasting, MySQL, PHPUnit, PHPStan level 6, ESLint.

**Spec:** `tasks/plans/PLAN-001-inbox-speed-and-media/PLAN.md` (scope and approved designs), `TEMP/intake-2026-10-13-0049.md` (root causes).

## Global Constraints

- WAHA calls from the inbound pipeline are soft-fail: catch `Throwable`, log, never block or lose an inbound message.
- Historical (backfilled) messages never fire automations, AI replies or unread bumps. Backfill is untouched by this plan; it keeps the lazy fetch.
- The engine API key only ever goes to the configured engine origin. A webhook link is rebased onto that origin before fetching (existing rule, kept).
- New user-facing strings go in `resources/js/locales/en.json`. Check the inbox at 360px and 1920px, light and dark.
- Dates and times come from the workspace timezone through the existing formatter. Never show raw DB timestamps.
- Tests: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='<name>'`. Never two test processes at once.
- Known failing at baseline (do not count as regressions): `HandoverTest` (2), `LabelCrudTest` (3), `SlaTrackingTest` (2), `MetaInboundWebhookTest::global_webhook_dedupes_duplicate_entry_ids`, `TypingEndpointTest` (2).
- Static analysis: `vendor/bin/phpstan analyse <files>`; frontend: `npx eslint <files>`.
- Git: `git add` by file name only (never `-A`). Build output (`public/build`) is its own commit. Never commit `WHATSMINE-CAPTURE-SPEC.md` or `public/deploy.php` (another session's changes).
- A live-site result is "unverified" until checked on wa.esystematics.com.

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` | Create | Fetch a WAHA media link through the engine, store it, record it on the message, find an existing stored copy by path, announce arrival |
| `app/Modules/WhatsappWeb/Services/WahaMediaException.php` | Create | User-safe media failure with an HTTP status (never carries credentials) |
| `app/Events/MessageMediaReady.php` | Create | Tells an open chat that a message's media is now stored |
| `app/Modules/WhatsappWeb/Services/WahaEventProcessor.php` | Modify `:25-33`, `:76-88` | Inject the store; fetch media right after the inbound message is saved |
| `app/Modules/Inbox/Http/Controllers/InboxController.php` | Modify | `index` JSON pages, shared list query, `show` latest message page, `messagesPage` (older pages), `react` returns the new row, `serveMedia` uses the store and a direct lookup; WAHA download and MIME helper move out |
| `app/Modules/Inbox/routes/web.php` | Modify | Add `GET /conversations/{conversation}/messages` (name `client.inbox.messages.index`) |
| `app/Modules/Whatsapp/Services/WhatsappDriver.php` | Read only | Returns the stored `Message` from `ingestNormalizedInbound` |
| `app/Modules/Cloud/...` | Not touched | Cloud API media keeps its path; it only gains `media_path` on store |
| `resources/js/Utils/inboxPaging.js` | Create | `mergeById`, `mergeMessages` (pure helpers, unit-tested by the build and reviewed) |
| `resources/js/hooks/useLoadWhenVisible.js` | Create | Calls a loader when a sentinel element scrolls into view |
| `resources/js/Pages/Inbox/Show.jsx` | Modify | Older-message loading, scroll rules, new-messages pill, `.MessageMediaReady` listener, reaction append, fixed media boxes, list paging |
| `resources/js/Pages/Inbox/Index.jsx` | Modify | List paging with the sentinel hook |
| `resources/js/locales/en.json` | Modify | Three new strings under `inbox` |
| `app/logic/inbox-paging.md` | Create | How paging, cursor and scroll rules work |
| `app/logic/waha-sync-and-labels.md`, `app/logic/realtime-config.md`, `app/logic/README.md` | Modify | Media at arrival, new event, index row |
| `tests/Feature/WhatsappWeb/WahaMediaArrivalTest.php` | Create | Arrival fetch, soft-fail, event |
| `tests/Feature/Inbox/WahaMediaServeTest.php` | Modify (append) | Stored-path lookup test |
| `tests/Feature/Inbox/ConversationListPagingTest.php` | Create | List pages |
| `tests/Feature/Inbox/MessagePagingTest.php` | Create | Message pages, cursor, tie-break, authorisation |
| `tests/Feature/Inbox/InboxReactionEndpointTest.php` | Modify (append) | Reaction response includes the new row |

---

## Task 1 (T-0010, T-0011): Store WAHA media in one service

**Files:**
- Create: `app/Modules/WhatsappWeb/Services/WahaMediaStore.php`
- Create: `app/Modules/WhatsappWeb/Services/WahaMediaException.php`
- Modify: `app/Modules/Inbox/Http/Controllers/InboxController.php` (`serveMedia` ~505-545, `cacheWahaMedia` ~582-639, `extensionForMime` ~646-658 move out; Cloud branch of `serveMedia` gains `media_path`)
- Test: `tests/Feature/Inbox/WahaMediaServeTest.php` (existing tests must still pass unchanged)

**Interfaces:**
- Consumes: `App\Modules\WhatsappWeb\Services\EngineManager::credentials()` (returns object with `baseUrl()`, `apiKey()` or null); `App\Services\StorageManager` (`disk()`, `prefixedPath(string)`, `diskName()`).
- Produces: `WahaMediaStore::store(Message $message, array $payload, string $link): array` returns `['preview_url' => string, 'mime_type' => string, 'media_path' => string]`, has already saved these into `$message->payload`; throws `WahaMediaException`. `WahaMediaStore::existingPath(Message $message, array $payload): ?string`. `WahaMediaStore::storeInbound(Message $message): void` (soft-fail, Task 3). `WahaMediaException` has `public readonly int $status`.

- [ ] **Step 1: Create the exception**

```php
<?php

namespace App\Modules\WhatsappWeb\Services;

use RuntimeException;

/** A user-safe media failure with the HTTP status to answer with. Never carries credentials. */
class WahaMediaException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
```

- [ ] **Step 2: Create the store (logic moved from `cacheWahaMedia`, unchanged behaviour)**

```php
<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Modules\Shared\Models\Message;
use App\Services\StorageManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WahaMediaStore
{
    public function __construct(
        private readonly EngineManager $engines,
        private readonly StorageManager $storage,
    ) {}

    /**
     * Download a WAHA media file through the configured engine, store it under
     * message-media/{id}.{ext} and record preview_url, mime_type and media_path
     * on the message payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{preview_url: string, mime_type: string, media_path: string}
     *
     * @throws WahaMediaException
     */
    public function store(Message $message, array $payload, string $link): array
    {
        $creds = $this->engines->credentials();
        $base = $creds?->baseUrl();
        $baseParts = $base ? parse_url($base) : false;
        if (! is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            throw new WahaMediaException(503, 'WhatsApp Web engine is not configured.');
        }

        $linkParts = parse_url($link);
        $path = is_array($linkParts) ? (string) ($linkParts['path'] ?? '') : '';
        if ($path === '') {
            throw new WahaMediaException(404, 'No media available.');
        }

        // Only the engine origin is ever contacted; the link's path and query are kept.
        $origin = $baseParts['scheme'].'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');
        $url = $origin.'/'.ltrim($path, '/').(isset($linkParts['query']) ? '?'.$linkParts['query'] : '');

        $apiKey = $creds->apiKey();
        try {
            $req = Http::timeout(30)->withOptions(['allow_redirects' => ['max' => 3]]);
            if ($apiKey) {
                $req = $req->withHeaders(['X-Api-Key' => $apiKey]);
            }
            $response = $req->get($url);
        } catch (\Throwable $e) {
            Log::warning('WAHA media fetch failed (connection)', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            throw new WahaMediaException(502, 'Could not reach the WhatsApp Web engine for this media.');
        }

        if ($response->status() === 404) {
            throw new WahaMediaException(404, 'This media is no longer available on the WhatsApp Web engine.');
        }
        if (! $response->successful()) {
            Log::warning('WAHA media fetch failed (status)', ['message_id' => $message->id, 'status' => $response->status()]);
            throw new WahaMediaException(502, 'The WhatsApp Web engine could not provide this media (HTTP '.$response->status().').');
        }

        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) > 64 * 1024 * 1024) {
            throw new WahaMediaException(502, 'The WhatsApp Web engine returned an empty or oversized media file.');
        }

        $rawType = trim(explode(';', (string) $response->header('Content-Type'))[0]);
        $mimeType = ($rawType === '' || $rawType === 'application/octet-stream')
            ? (string) ($payload['mime_type'] ?? 'application/octet-stream')
            : $rawType;

        $mediaPath = $this->storage->prefixedPath('message-media/'.$message->id.'.'.$this->extensionForMime($mimeType));
        try {
            $this->storage->disk()->put($mediaPath, $bytes);
        } catch (\Throwable $e) {
            Log::warning('WAHA media store failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            throw new WahaMediaException(502, 'Could not store media.');
        }

        $stored = [
            'preview_url' => $this->storage->disk()->url($mediaPath),
            'mime_type' => $mimeType,
            'media_path' => $mediaPath,
        ];
        $message->update(['payload' => array_merge($payload, $stored)]);

        return $stored;
    }

    /**
     * The stored copy of a message's media, found by its recorded path (or, for
     * copies made before media_path existed, by the name derived from mime_type).
     * Never lists the folder. Null when nothing is stored.
     *
     * @param  array<string, mixed>  $payload
     */
    public function existingPath(Message $message, array $payload): ?string
    {
        $candidate = $payload['media_path'] ?? null;
        if (! is_string($candidate) || $candidate === '') {
            $mime = $payload['mime_type'] ?? null;
            if (! is_string($mime) || $mime === '') {
                return null;
            }
            $candidate = $this->storage->prefixedPath('message-media/'.$message->id.'.'.$this->extensionForMime($mime));
        }

        return $this->storage->disk()->exists($candidate) ? $candidate : null;
    }

    private function extensionForMime(string $mimeType): string
    {
        [$major, $minor] = array_pad(explode('/', strtolower($mimeType), 2), 2, 'bin');
        $sub = preg_replace('/[^a-z0-9]/', '', explode('+', $minor)[0]) ?: 'bin';

        return match (true) {
            $sub === 'jpeg' => 'jpg',
            $sub === 'octetstream' => 'bin',
            $sub === 'plain' => 'txt',
            $sub === 'quicktime' => 'mov',
            $sub === 'mpeg' && $major === 'audio' => 'mp3',
            default => $sub,
        };
    }
}
```

- [ ] **Step 3: Replace the controller's WAHA branch and cached lookup**

In `InboxController.php`:
- Add `use App\Modules\WhatsappWeb\Services\WahaMediaException;` and `use App\Modules\WhatsappWeb\Services\WahaMediaStore;`. Inject `private WahaMediaStore $wahaMedia` into the constructor (next to `StorageManager $storageManager`).
- Replace the cached block at the top of `serveMedia` with:

```php
        // Already stored: find that one known path (no folder listing) and redirect.
        if (! empty($payload['preview_url'])) {
            $path = $this->wahaMedia->existingPath($message, $payload);
            if ($path) {
                return redirect($this->storageManager->disk()->url($path));
            }

            // File missing — clear stale preview_url and fall through to re-download
            $payload = array_merge($payload, ['preview_url' => null]);
            $message->update(['payload' => $payload]);
        }
```

- Replace the WAHA line `return $this->cacheWahaMedia($message, $payload, $wahaLink);` with:

```php
            try {
                $stored = $this->wahaMedia->store($message, $payload, $wahaLink);
            } catch (WahaMediaException $e) {
                abort($e->status, $e->getMessage());
            }

            return redirect($stored['preview_url']);
```

- In the Cloud branch, change the update line to also record the path:

```php
            $message->update(['payload' => array_merge($payload, ['preview_url' => $previewUrl, 'mime_type' => $mimeType, 'media_path' => $filename])]);
```

- Delete the private methods `cacheWahaMedia` and `extensionForMime` from the controller (their only caller was `cacheWahaMedia`; grep confirms `extensionForMime` is not used elsewhere).

- [ ] **Step 4: Run the existing WAHA media tests (they must pass unchanged)**

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=WahaMediaServeTest`
Expected: all tests in `WahaMediaServeTest` PASS (the 404, 502, 503 and key-leak tests included).

- [ ] **Step 5: Append a test for the recorded path (T-0011)**

Append to `tests/Feature/Inbox/WahaMediaServeTest.php` (before the final `}`):

```php
    public function test_cached_copy_is_found_by_its_recorded_path_without_listing_the_folder(): void
    {
        Storage::disk('public')->put('message-media/other-1.jpg', 'NOT THIS ONE');
        Storage::disk('public')->put('message-media/other-2.jpg', 'NOT THIS ONE EITHER');
        Storage::disk('public')->put('message-media/777.jpg', 'JPEG');
        $message = $this->mediaMessage([
            'id' => 'wa-777',
            'image' => ['link' => self::BASE.'/api/files/z.jpeg'],
            'preview_url' => '/storage/message-media/777.jpg',
            'mime_type' => 'image/jpeg',
            'media_path' => 'message-media/777.jpg',
        ]);
        Http::fake(function () {
            $this->fail('A stored copy must not contact the engine.');
        });

        $response = $this->actingAs($this->user)->get($this->mediaUrl($message));

        $response->assertRedirect();
        $this->assertStringContainsString('message-media/777.jpg', (string) $response->headers->get('Location'));
    }
```

- [ ] **Step 6: Run the new test**

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=WahaMediaServeTest`
Expected: PASS, including `test_cached_copy_is_found_by_its_recorded_path_without_listing_the_folder`.

- [ ] **Step 7: Static analysis**

Run: `vendor/bin/phpstan analyse app/Modules/WhatsappWeb/Services/WahaMediaStore.php app/Modules/WhatsappWeb/Services/WahaMediaException.php app/Modules/Inbox/Http/Controllers/InboxController.php`
Expected: no new errors versus `phpstan-baseline.neon`.

---

## Task 2 (T-0010): Fetch media at arrival and announce it

**Files:**
- Create: `app/Events/MessageMediaReady.php`
- Modify: `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` (add `storeInbound`)
- Modify: `app/Modules/WhatsappWeb/Services/WahaEventProcessor.php:25-33` (constructor), `:76-88` (`handleInbound`)
- Create: `tests/Feature/WhatsappWeb/WahaMediaArrivalTest.php`

**Interfaces:**
- Consumes: `WahaMediaStore::store()` from Task 1; `WhatsappDriver::ingestNormalizedInbound(array $value, array $msg): Message`.
- Produces: `WahaMediaStore::storeInbound(Message $message): void`; event `App\Events\MessageMediaReady` (broadcast name `MessageMediaReady`, private channel `conversation.{conversation_id}`, data `id`, `conversation_id`, `preview_url`, `mime_type`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WhatsappWeb/WahaMediaArrivalTest.php`:

```php
<?php

namespace Tests\Feature\WhatsappWeb;

use App\Events\MessageMediaReady;
use App\Events\MessageReceived;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WahaMediaArrivalTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://waha.test';

    private WhatsappWebSession $session;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        $ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => self::BASE],
            'webhook_secret' => 'secret',
        ]);

        $this->token = Str::random(48);
        $name = 'ws-'.$ctx['workspace']->id;
        $this->session = WhatsappWebSession::create([
            'workspace_id' => $ctx['workspace']->id,
            'session_name' => $name,
            'engine' => 'waha',
            'status' => 'active',
            'webhook_token' => $this->token,
        ]);
        ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => $name,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);
    }

    private function imagePayload(string $id = 'false_555@c.us_IMG'): array
    {
        return [
            'event' => 'message',
            'session' => $this->session->session_name,
            'payload' => [
                'id' => $id,
                'timestamp' => now()->timestamp,
                'from' => '15551230000@c.us',
                'fromMe' => false,
                'body' => '',
                'type' => 'image',
                'notifyName' => 'Sam',
                'media' => ['url' => self::BASE.'/api/files/photo.jpeg', 'mimetype' => 'image/jpeg'],
            ],
        ];
    }

    private function post(array $payload)
    {
        $body = json_encode($payload);

        return $this->postJson("/webhooks/whatsapp-web/{$this->token}", $payload, [
            'X-Webhook-Hmac' => hash_hmac('sha256', $body, 'secret'),
        ]);
    }

    #[Test]
    public function inbound_image_is_stored_at_arrival_and_announced(): void
    {
        Event::fake([MessageReceived::class, MessageMediaReady::class]);
        Http::fake([self::BASE.'/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg'])]);

        $this->post($this->imagePayload())->assertOk();

        $message = Message::where('provider_message_id', 'false_555@c.us_IMG')->firstOrFail();
        $this->assertNotEmpty($message->payload['preview_url']);
        $this->assertSame('image/jpeg', $message->payload['mime_type']);
        $this->assertSame("message-media/{$message->id}.jpg", $message->payload['media_path']);
        Storage::disk('public')->assertExists("message-media/{$message->id}.jpg");
        Event::assertDispatched(MessageReceived::class);
        Event::assertDispatched(MessageMediaReady::class, fn ($e) => $e->message->id === $message->id);
    }

    #[Test]
    public function an_engine_failure_keeps_the_message_and_skips_the_announcement(): void
    {
        Event::fake([MessageReceived::class, MessageMediaReady::class]);
        Http::fake([self::BASE.'/*' => Http::response('boom', 500)]);

        $this->post($this->imagePayload('false_556@c.us_IMG'))->assertOk();

        $message = Message::where('provider_message_id', 'false_556@c.us_IMG')->firstOrFail();
        $this->assertArrayNotHasKey('preview_url', $message->payload);
        Event::assertDispatched(MessageReceived::class);
        Event::assertNotDispatched(MessageMediaReady::class);
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=WahaMediaArrivalTest`
Expected: FAIL (`MessageMediaReady` class not found, and no `preview_url` after the webhook).

- [ ] **Step 3: Create the event (same style as `MessageReceived`)**

```php
<?php

namespace App\Events;

use App\Modules\Shared\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once a WhatsApp Web media file has been stored, so an open chat can show
 * it without a reload. Synchronous, like MessageReceived.
 */
class MessageMediaReady implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->message->conversation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'MessageMediaReady';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'preview_url' => $this->message->payload['preview_url'] ?? null,
            'mime_type' => $this->message->payload['mime_type'] ?? null,
        ];
    }
}
```

- [ ] **Step 4: Add `storeInbound` to the store**

Add to `WahaMediaStore` (and add `use App\Events\MessageMediaReady;` and `use Throwable;`):

```php
    /** Message types whose payload carries a downloadable WAHA link. */
    private const MEDIA_TYPES = ['image', 'video', 'audio', 'document'];

    /**
     * Called right after an inbound WhatsApp Web message is saved and announced.
     * Stores the file so the bubble has it straight away. Soft-fail: a failure is
     * logged and the message stays as it is (the lazy fetch still works on view).
     */
    public function storeInbound(Message $message): void
    {
        $type = (string) $message->type;
        if (! in_array($type, self::MEDIA_TYPES, true) || ! empty($message->payload['preview_url'])) {
            return;
        }

        $link = $message->payload[$type]['link'] ?? null;
        if (! is_string($link) || $link === '') {
            return;
        }

        try {
            $this->store($message, $message->payload, $link);
            MessageMediaReady::dispatch($message->fresh());
        } catch (Throwable $e) {
            Log::warning('whatsapp_web.media.arrival_failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
        }
    }
```

Note: `store()` already writes `payload` and `preview_url`; `$message->fresh()` reloads it for the event.

- [ ] **Step 5: Wire it into the inbound handler**

In `WahaEventProcessor.php`:
- Add `use App\Modules\WhatsappWeb\Services\WahaMediaStore;` and inject `private readonly WahaMediaStore $media,` as the last constructor argument.
- Replace the body of `handleInbound`'s last two lines:

```php
        [$value, $msg] = $normalized;
        $message = $this->driver->ingestNormalizedInbound($value, $msg);
        $this->media->storeInbound($message);
```

- [ ] **Step 6: Run the arrival tests**

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=WahaMediaArrivalTest`
Expected: both tests PASS.

- [ ] **Step 7: Run the existing WhatsApp Web inbound tests**

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=InboundWebhookTest`
Expected: PASS (no change in text-message behaviour).

- [ ] **Step 8: Static analysis**

Run: `vendor/bin/phpstan analyse app/Events/MessageMediaReady.php app/Modules/WhatsappWeb/Services/WahaMediaStore.php app/Modules/WhatsappWeb/Services/WahaEventProcessor.php`
Expected: no new errors.

---

## Task 3 (T-0012, server): Page the conversation list

**Files:**
- Modify: `app/Modules/Inbox/Http/Controllers/InboxController.php` (`index` lines 40-72; `show` lines 117-129)
- Create: `tests/Feature/Inbox/ConversationListPagingTest.php`

**Interfaces:**
- Consumes: existing `Conversation` model and `authorise()` helper.
- Produces: `InboxController::conversationPage(array $filters, int $workspaceId, int $userId): LengthAwarePaginator` (private). `GET /app/inbox/?page=N` with `Accept: application/json` returns the paginator JSON (`data`, `current_page`, `last_page`, `total`, `next_page_url`, ...). Order: `last_message_at` desc, then `id` desc.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Inbox/ConversationListPagingTest.php`:

```php
<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationListPagingTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    private int $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = $this->createWorkspaceContext();
        $this->user = $ctx['user'];
        $this->workspaceId = $ctx['workspace']->id;

        $account = ChannelAccount::create([
            'workspace_id' => $this->workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'waha',
            'display_name' => 'WA Web',
            'status' => 'active',
        ]);

        // 45 open conversations; $i = 0 is the newest.
        for ($i = 0; $i < 45; $i++) {
            Conversation::create([
                'workspace_id' => $this->workspaceId,
                'channel_account_id' => $account->id,
                'contact_id' => Contact::factory()->create(['workspace_id' => $this->workspaceId])->id,
                'status' => 'open',
                'last_message_at' => now()->subMinutes($i),
            ]);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function first_page_is_the_newest_thirty(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('client.inbox.index'))
            ->assertOk();

        $response->assertJsonCount(30, 'data');
        $response->assertJsonPath('total', 45);
        $this->assertNotNull($response->json('next_page_url'));

        $times = collect($response->json('data'))->pluck('last_message_at');
        $this->assertSame($times->sortDesc()->values()->all(), $times->values()->all());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function second_page_holds_the_rest_and_has_no_next_page(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('client.inbox.index', ['page' => 2]))
            ->assertOk();

        $response->assertJsonCount(15, 'data');
        $this->assertNull($response->json('next_page_url'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function inertia_page_load_still_renders_the_inbox(): void
    {
        $this->actingAs($this->user)
            ->get(route('client.inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inbox/Index')->has('conversations.data', 30));
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=ConversationListPagingTest`
Expected: FAIL (the JSON request returns the Inertia HTML response, so `assertJsonCount` fails).

- [ ] **Step 3: Implement the shared query and JSON pages**

In `InboxController.php`, replace `index` with:

```php
    public function index(Request $request): Response|JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $userId = $request->user()->id;

        $conversations = $this->conversationPage($request->only('folder', 'channel', 'label', 'account_id'), $workspaceId, $userId);

        // The list's "load more" asks for JSON; a normal visit renders the page.
        if ($request->wantsJson()) {
            return response()->json($conversations);
        }

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

    /**
     * One page of the conversation list, newest activity first. The same query
     * serves the inbox page, the chat page's left list and the JSON pages.
     *
     * @param  array<string, mixed>  $filters
     */
    private function conversationPage(array $filters, int $workspaceId, int $userId): LengthAwarePaginator
    {
        return Conversation::where('workspace_id', $workspaceId)
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
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();
    }
```

Add imports: `use Illuminate\Contracts\Pagination\LengthAwarePaginator;` (or `Illuminate\Pagination\LengthAwarePaginator`), `use Illuminate\Http\JsonResponse;` if not already present.

In `show`, replace the block from `// Pass conversation list so the left panel stays populated on the show page` through the `->withQueryString();` line (lines 117-129) with:

```php
        // Pass conversation list so the left panel stays populated on the show page
        $filters = $request->only('folder', 'channel', 'label', 'account_id');
        $conversations = $this->conversationPage($filters, $workspaceId, $userId);
```

- [ ] **Step 4: Run the test**

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=ConversationListPagingTest`
Expected: all three tests PASS.

- [ ] **Step 5: Run the existing inbox tests that touch the list**

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=Inbox`
Expected: no new failures. Known baseline failures listed in Global Constraints are excluded from the count.

---

## Task 4 (T-0012, server): Page the chat messages and return the new reaction row

**Files:**
- Modify: `app/Modules/Inbox/Http/Controllers/InboxController.php` (`show` line 79 and its return; new `messagesPage`; `react` return)
- Modify: `app/Modules/Inbox/routes/web.php` (one new route)
- Create: `tests/Feature/Inbox/MessagePagingTest.php`
- Modify (append): `tests/Feature/Inbox/InboxReactionEndpointTest.php`

**Interfaces:**
- Consumes: `Conversation::messages()` relation; `authorise()`.
- Produces: `InboxController::MESSAGE_PAGE = 50`; `show()` passes `messages` (latest 50, oldest first) and `hasMoreMessages` (bool). Route `client.inbox.messages.index` at `GET /app/inbox/conversations/{conversation}/messages` with query `before_sent_at` (ISO 8601) and `before_id` (int) returns `{ "messages": [...] (oldest first), "has_more": bool }` with the 50 messages immediately older than the cursor. `react()` returns `{ "ok": true, "message": <new row> }`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Inbox/MessagePagingTest.php`:

```php
<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MessagePagingTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    private Conversation $conversation;

    private int $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = $this->createWorkspaceContext();
        $this->user = $ctx['user'];
        $this->workspaceId = $ctx['workspace']->id;

        $account = ChannelAccount::create([
            'workspace_id' => $this->workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'waha',
            'display_name' => 'WA Web',
            'status' => 'active',
        ]);
        $this->conversation = Conversation::create([
            'workspace_id' => $this->workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $this->workspaceId])->id,
            'status' => 'open',
        ]);
    }

    /** Creates $count messages, one minute apart, #1 oldest. Returns the ids oldest first. */
    private function thread(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = Message::create([
                'conversation_id' => $this->conversation->id,
                'direction' => $i % 2 ? 'in' : 'out',
                'channel' => 'whatsapp',
                'type' => 'text',
                'body' => "message {$i}",
                'payload' => ['text' => ['body' => "message {$i}"]],
                'status' => 'delivered',
                'sent_at' => now()->startOfSecond()->subMinutes($count - $i),
            ])->id;
        }

        return $ids;
    }

    #[Test]
    public function opening_a_chat_sends_only_the_newest_page(): void
    {
        $this->thread(120);

        $this->actingAs($this->user)
            ->get(route('client.inbox.show', $this->conversation->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('messages', 50)
                ->where('hasMoreMessages', true)
                ->where('messages.0.body', 'message 71')
                ->where('messages.49.body', 'message 120'));
    }

    #[Test]
    public function older_messages_come_in_pages_with_no_gaps_or_repeats(): void
    {
        $this->thread(120);
        $url = route('client.inbox.messages.index', $this->conversation->uuid);

        $cursor = Message::where('body', 'message 71')->firstOrFail();
        $first = $this->actingAs($this->user)->getJson($url.'?'.http_build_query([
            'before_sent_at' => $cursor->sent_at->toIso8601String(),
            'before_id' => $cursor->id,
        ]))->assertOk();

        $first->assertJsonCount(50, 'messages');
        $first->assertJsonPath('has_more', true);
        $this->assertSame('message 21', $first->json('messages.0.body'));
        $this->assertSame('message 70', $first->json('messages.49.body'));

        $cursor = Message::where('body', 'message 21')->firstOrFail();
        $second = $this->actingAs($this->user)->getJson($url.'?'.http_build_query([
            'before_sent_at' => $cursor->sent_at->toIso8601String(),
            'before_id' => $cursor->id,
        ]))->assertOk();

        $second->assertJsonCount(20, 'messages');
        $second->assertJsonPath('has_more', false);
        $this->assertSame('message 1', $second->json('messages.0.body'));
    }

    #[Test]
    public function messages_with_the_same_time_are_paged_by_id_without_repeats(): void
    {
        // 52 messages in the same second: the page boundary must fall back to id.
        $same = now()->startOfSecond()->subHour();
        $ids = [];
        for ($i = 1; $i <= 52; $i++) {
            $ids[] = Message::create([
                'conversation_id' => $this->conversation->id,
                'direction' => 'in',
                'channel' => 'whatsapp',
                'type' => 'text',
                'body' => "same {$i}",
                'payload' => ['text' => ['body' => "same {$i}"]],
                'status' => 'delivered',
                'sent_at' => $same,
            ])->id;
        }
        $url = route('client.inbox.messages.index', $this->conversation->uuid);

        $page = $this->actingAs($this->user)->getJson($url.'?'.http_build_query([
            'before_sent_at' => $same->toIso8601String(),
            'before_id' => $ids[2], // the 3rd message: older ids are 1 and 2
        ]))->assertOk();

        $page->assertJsonCount(2, 'messages');
        $page->assertJsonPath('has_more', false);
        $this->assertSame([$ids[0], $ids[1]], collect($page->json('messages'))->pluck('id')->all());
    }

    #[Test]
    public function another_workspaces_user_cannot_read_older_messages(): void
    {
        $this->thread(60);
        $other = $this->createWorkspaceContext()['user'];

        $this->actingAs($other)->getJson(route('client.inbox.messages.index', $this->conversation->uuid).'?'.http_build_query([
            'before_sent_at' => now()->toIso8601String(),
            'before_id' => 999999,
        ]))->assertForbidden();
    }
}
```

Append to `tests/Feature/Inbox/InboxReactionEndpointTest.php` (before the final `}`), reusing that file's own setup and route name:

```php
    #[Test]
    public function reaction_response_returns_the_new_row_so_the_chat_can_show_it_without_a_reload(): void
    {
        // Same arrangement as the success test above.
        $response = $this->actingAs($this->user)
            ->postJson(route('client.inbox.messages.react', [$this->conversation, $this->inbound]), ['emoji' => '👍'])
            ->assertOk();

        $response->assertJsonPath('message.type', 'reaction');
        $response->assertJsonPath('message.body', '👍');
    }
```

NOTE for the executor: this test reuses the names `$this->user`, `$this->conversation`, `$this->inbound` and the WAHA `Http::fake` setup that the existing success test in the same file uses. Copy that setup into this test body (the success test's first lines) if the names differ, before running.

- [ ] **Step 2: Run them to see them fail**

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=MessagePagingTest`
Expected: FAIL (the route `client.inbox.messages.index` does not exist, and `show` sends every message).

- [ ] **Step 3: Add the route**

In `app/Modules/Inbox/routes/web.php`, after the `conversations/{conversation}` show route, add:

```php
    Route::get('/conversations/{conversation}/messages', [InboxController::class, 'messagesPage'])->name('messages.index');
```

- [ ] **Step 4: Controller changes**

In `InboxController.php`:
- Add `private const MESSAGE_PAGE = 50;` at the top of the class.
- In `show`, replace line 79 (`$messages = $conversation->messages()->with('conversation')->orderBy('sent_at')->get();`) with:

```php
        [$messages, $hasMoreMessages] = $this->latestMessagePage($conversation);
```

- In the `Inertia::render('Inbox/Show', [...])` array add `'hasMoreMessages' => $hasMoreMessages,`.
- Add these methods:

```php
    /**
     * The newest page of a thread, oldest first, and whether older messages exist.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: bool}
     */
    private function latestMessagePage(Conversation $conversation): array
    {
        $rows = $conversation->messages()->with('conversation')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGE_PAGE + 1)
            ->get();

        return [
            $rows->take(self::MESSAGE_PAGE)->reverse()->values(),
            $rows->count() > self::MESSAGE_PAGE,
        ];
    }

    /**
     * Older messages before a cursor (sent_at, id), oldest first, for the chat's scroll-up loading.
     */
    public function messagesPage(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);

        $validated = $request->validate([
            'before_sent_at' => ['required', 'date'],
            'before_id' => ['required', 'integer', 'min:1'],
        ]);

        // Stored times are in the app timezone; compare in that zone.
        $sentAt = Carbon::parse($validated['before_sent_at'])->setTimezone(config('app.timezone'));
        $beforeId = (int) $validated['before_id'];

        $rows = $conversation->messages()->with('conversation')
            ->where(fn ($q) => $q->where('sent_at', '<', $sentAt)
                ->orWhere(fn ($q) => $q->where('sent_at', $sentAt)->where('id', '<', $beforeId)))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGE_PAGE + 1)
            ->get();

        return response()->json([
            'messages' => $rows->take(self::MESSAGE_PAGE)->reverse()->values(),
            'has_more' => $rows->count() > self::MESSAGE_PAGE,
        ]);
    }
```

Add `use Illuminate\Support\Carbon;` if not present.

- In `react`, change the final success return to:

```php
        return response()->json(['ok' => true, 'message' => $out->fresh()]);
```

- [ ] **Step 5: Run the tests**

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=MessagePagingTest`
Expected: all four PASS.

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=InboxReactionEndpointTest`
Expected: PASS, including the new reaction response test.

- [ ] **Step 6: Static analysis**

Run: `vendor/bin/phpstan analyse app/Modules/Inbox/Http/Controllers/InboxController.php app/Modules/Inbox/routes/web.php`
Expected: no new errors.

- [ ] **Step 7: Backend commit (after the full backend test run)**

Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='WahaMedia|Inbound|ConversationListPaging|MessagePaging|InboxReaction|Inbox'`
Expected: no failures outside the known baseline list.

Then:
```bash
git add app/Events/MessageMediaReady.php app/Modules/WhatsappWeb/Services/WahaMediaStore.php app/Modules/WhatsappWeb/Services/WahaMediaException.php app/Modules/WhatsappWeb/Services/WahaEventProcessor.php app/Modules/Inbox/Http/Controllers/InboxController.php app/Modules/Inbox/routes/web.php tests/Feature/WhatsappWeb/WahaMediaArrivalTest.php tests/Feature/Inbox/WahaMediaServeTest.php tests/Feature/Inbox/ConversationListPagingTest.php tests/Feature/Inbox/MessagePagingTest.php tests/Feature/Inbox/InboxReactionEndpointTest.php
git commit -m "feat: fetch WhatsApp Web media at arrival and page the inbox (T-0010, T-0011, T-0012)"
```

---

## Task 5 (T-0012, T-0013): Chat page, lazy older messages, scroll rules, media boxes

**Files:**
- Create: `resources/js/Utils/inboxPaging.js`
- Create: `resources/js/hooks/useLoadWhenVisible.js`
- Modify: `resources/js/Pages/Inbox/Show.jsx` (state at ~1523, effect at ~1537-1544, `scrollToBottom` at ~1570-1572, auto-scroll effect at ~1580, listeners at ~1583-1648, `MediaImage` ~174-207, `MediaVideo` ~210-213, `MessageBubble` ~709 and `sendReaction` ~717-724, messages container ~1975-1985, list panel ~1877-1886, import line 15)
- Modify: `resources/js/locales/en.json` (under `inbox`)

**Interfaces:**
- Consumes: `GET client.inbox.messages.index` (Task 4) returns `{ messages, has_more }`; `POST client.inbox.messages.react` returns `{ ok, message }`; broadcast `.MessageMediaReady` (Task 2) with `{ id, conversation_id, preview_url, mime_type }`; `initialConversations` paginator from Task 3 with `data`, `next_page_url`, `total`.
- Produces: `mergeById(base, extra)` and `mergeMessages(older, current)` from `Utils/inboxPaging.js`; `useLoadWhenVisible(ref, enabled, onVisible)` from `hooks/useLoadWhenVisible.js`.

- [ ] **Step 1: Create the pure helpers**

`resources/js/Utils/inboxPaging.js`:

```js
import axios from 'axios';

/** Items of `extra` whose ids are not in `base`, appended after `base` (base order kept). */
export function mergeById(base = [], extra = []) {
    const seen = new Set(base.map((item) => item.id));
    return [...base, ...extra.filter((item) => !seen.has(item.id))];
}

/**
 * Merge an older page into the messages already on screen. Oldest first by sent
 * time, then id. When an id is in both, the one in `current` wins (it is fresher).
 */
export function mergeMessages(older = [], current = []) {
    const byId = new Map();
    for (const message of [...older, ...current]) {
        byId.set(message.id, message);
    }

    const at = (m) => (m.sent_at ? Date.parse(m.sent_at) : 0);
    return [...byId.values()].sort((a, b) => {
        const byTime = at(a) - at(b);
        return byTime !== 0 ? byTime : a.id - b.id;
    });
}

/** GET a JSON page (the list's next page, or older messages). Throws on HTTP errors. */
export function fetchPage(url, params) {
    return axios.get(url, { params, headers: { Accept: 'application/json' } }).then((response) => response.data);
}
```

`Date.parse(0)` returns NaN for number argument? `Date.parse(0)` → `Date.parse("0")` → year 2000 in V8 — non-NaN but arbitrary; messages always have sent_at, so fine. To be exact, use `(a.sent_at ? Date.parse(a.sent_at) : 0)`. Use that form:

```js
        const at = (m) => (m.sent_at ? Date.parse(m.sent_at) : 0);
        const byTime = at(a) - at(b);
```

- [ ] **Step 2: Create the visibility hook**

`resources/js/hooks/useLoadWhenVisible.js`:

```js
import { useEffect, useRef } from 'react';

/**
 * Calls `onVisible` when the element behind `ref` scrolls into view inside its
 * scroll container. `enabled` switches it off (no more pages, or a load running).
 */
export default function useLoadWhenVisible(ref, enabled, onVisible) {
    const callback = useRef(onVisible);
    callback.current = onVisible;

    useEffect(() => {
        const el = ref.current;
        if (!enabled || !el || typeof IntersectionObserver === 'undefined') return undefined;

        const observer = new IntersectionObserver(
            (entries) => { if (entries[0]?.isIntersecting) callback.current(); },
            { root: el.parentElement, rootMargin: '200px 0px' },
        );
        observer.observe(el);

        return () => observer.disconnect();
    }, [ref, enabled]);
}
```

- [ ] **Step 3: Add the English strings**

In `resources/js/locales/en.json`, under the `inbox` object, add:

```json
"new_messages": "{{count}} new message(s)",
"loading_older": "Loading older messages…",
"loading_more": "Loading more…"
```

(Keep valid JSON: add a comma after the previous key in `inbox`.)

- [ ] **Step 4: Chat page — props, state and refs**

In `Show.jsx`:
- Line 15: `import { useState, useEffect, useRef, useCallback, useLayoutEffect } from 'react';`
- Add imports: `import { mergeById, mergeMessages, fetchPage } from '@/Utils/inboxPaging';` and `import useLoadWhenVisible from '@/hooks/useLoadWhenVisible';`
- In the component props, add `hasMoreMessages = false,` after `messages: initialMessages,`.
- After `const [sendError, setSendError] = useState(null);` add:

```js
    const [hasMoreOlder, setHasMoreOlder]   = useState(hasMoreMessages);
    const [loadingOlder, setLoadingOlder]   = useState(false);
    const [newCount, setNewCount]           = useState(0);
    const [listHasMore, setListHasMore]     = useState(Boolean(conversations?.next_page_url));
    const [listMoreLoading, setListMoreLoading] = useState(false);
    const scrollRef       = useRef(null);
    const nearBottomRef   = useRef(true);
    const lastIdRef       = useRef(null);
    const restoreRef      = useRef(null);
    const loadingOlderRef = useRef(false);
    const listSentinelRef = useRef(null);
    const loadingListRef  = useRef(false);
```

Note: the new state is `listMoreLoading`/`setListMoreLoading`. The existing `listLoading`/`setListLoading` (the list-filter spinner, ~1520) stays untouched.

- Replace the existing conversation-change effect (`useEffect(() => { setMessages(initialMessages ?? []); ...}, [conversation.id]);`) by adding these lines inside it:

```js
        setHasMoreOlder(hasMoreMessages);
        setNewCount(0);
        lastIdRef.current = null;
```

- Replace `useEffect(() => { setConversations(initialConversations); setListLoading(false); }, [initialConversations]);` by:

```js
    useEffect(() => {
        setConversations(initialConversations);
        setListLoading(false);
        setListHasMore(Boolean(initialConversations?.next_page_url));
    }, [initialConversations]);
```

- [ ] **Step 5: Chat page — scroll helpers and layout effect (T-0013)**

Replace the `scrollToBottom` block (lines ~1570-1572) and the auto-scroll effect `useEffect(() => { scrollToBottom(); }, [messages]);` (~1580) with:

```js
    const scrollToBottom = useCallback(() => {
        const el = scrollRef.current;
        if (el) el.scrollTop = el.scrollHeight;
        nearBottomRef.current = true;
        setNewCount(0);
    }, []);

    // Keeps the reader's place: jumps to the bottom on open, follows new messages
    // only when the reader is already at the bottom (or sent the message), keeps the
    // position when older messages are added above, and counts what they missed.
    useLayoutEffect(() => {
        const last = messages[messages.length - 1];
        const lastId = last?.id ?? null;

        if (restoreRef.current !== null && scrollRef.current) {
            scrollRef.current.scrollTop = scrollRef.current.scrollHeight - restoreRef.current;
            restoreRef.current = null;
        } else if (lastId !== lastIdRef.current) {
            if (lastIdRef.current === null) {
                scrollToBottom();                    // first render of this conversation
            } else if (last?.direction === 'out' || nearBottomRef.current) {
                scrollToBottom();                    // own message, or reader is at the bottom
            } else {
                setNewCount((c) => c + 1);           // reader is up: count, do not move them
            }
        }
        lastIdRef.current = lastId;
    }, [messages, scrollToBottom]);
```

- [ ] **Step 6: Chat page — older-message loading**

Add (after `scrollToBottom`):

```js
    const onMessagesScroll = () => {
        const el = scrollRef.current;
        if (!el) return;
        nearBottomRef.current = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
        if (nearBottomRef.current && newCount > 0) setNewCount(0);
        if (el.scrollTop < 120 && hasMoreOlder && !loadingOlderRef.current) loadOlder();
    };

    const loadOlder = async () => {
        const first = messages[0];
        if (!first || loadingOlderRef.current) return;
        loadingOlderRef.current = true;
        setLoadingOlder(true);
        try {
            const page = await fetchPage(
                route('client.inbox.messages.index', conversation.uuid),
                { before_sent_at: first.sent_at, before_id: first.id },
            );
            const el = scrollRef.current;
            restoreRef.current = el ? el.scrollHeight - el.scrollTop : null;
            setMessages((prev) => mergeMessages(page.messages, prev));
            setHasMoreOlder(page.has_more);
        } catch {
            // Keep what is on screen; scrolling up again retries.
        } finally {
            loadingOlderRef.current = false;
            setLoadingOlder(false);
        }
    };
```

The `useLayoutEffect` restores the position after the prepend renders.

- [ ] **Step 7: Chat page — live listeners**

In the conversation-channel listener chain (around `.listen('.MessageStatusUpdated', ...)`), add:

```js
            .listen('.MessageMediaReady', (e) => {
                setMessages(prev => prev.map(m => m.id === e.id
                    ? { ...m, payload: { ...(m.payload ?? {}), preview_url: e.preview_url, mime_type: e.mime_type } }
                    : m));
            })
```

- [ ] **Step 8: Chat page — reactions append the new row (no reload)**

- Change `MessageBubble` signature to `function MessageBubble({ msg, conversationId, onAppend })`.
- In `sendReaction`, replace `.then(() => router.reload({ only: ['conversation', 'messages'] }))` with:

```js
            .then(({ data }) => { if (data?.message) onAppend?.(data.message); })
```

- Define, in `InboxShow` (near `scrollToBottom`):

```js
    const appendMessage = useCallback((m) => {
        setMessages(prev => (prev.some(x => x.id === m.id) ? prev : [...prev, m]));
    }, []);
```

- At the messages render, pass `onAppend={appendMessage}` to `<MessageBubble ... />`.

- [ ] **Step 9: Chat page — messages container, scroll wiring and pill**

Replace the messages tab container block (the `<div className="flex-1 overflow-y-auto p-4 space-y-1">` through its closing `</div>`, ~1975-1985) with:

```jsx
                        <div className="relative flex-1 min-h-0 flex flex-col">
                            <div ref={scrollRef} onScroll={onMessagesScroll}
                                className="flex-1 overflow-y-auto p-4 space-y-1">
                                {loadingOlder && (
                                    <p className="text-center text-xs text-neutral-400 py-2">{t('inbox.loading_older')}</p>
                                )}
                                {groupMessagesForRender(messages).map(item => (
                                    item.kind === 'album'
                                        ? <ImageGallery key={item.key} messages={item.messages} conversationId={conversation.uuid} />
                                        : <MessageBubble key={item.key} msg={item.msg} conversationId={conversation.uuid} onAppend={appendMessage} />
                                ))}
                                {messages.length === 0 && (
                                    <div className="py-8"><EmptyState icon={<MessageSquare className="h-8 w-8" />} title={t('inbox.no_messages_yet')} description={t('inbox.no_messages_desc')} /></div>
                                )}
                            </div>
                            {newCount > 0 && (
                                <button type="button" onClick={scrollToBottom}
                                    className="absolute bottom-4 left-1/2 -translate-x-1/2 rounded-full bg-brand-600 hover:bg-brand-700 text-white text-xs font-medium px-3 py-1.5 shadow-lg">
                                    {t('inbox.new_messages', { count: newCount })}
                                </button>
                            )}
                        </div>
```

The old `<div ref={bottomRef} />` is removed; `bottomRef` and its `useRef` line may be deleted if unused (grep to confirm).

- [ ] **Step 10: Media boxes that do not change size on load (T-0013)**

- `MediaImage` (~174-207): change the wrapper button's class to a fixed box and the `img` to fill it:

```jsx
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="relative block w-64 max-w-full h-48 rounded-xl overflow-hidden mb-1 bg-black/10 cursor-zoom-in"
                title={t('inbox.view_full_size')}
            >
                {!loaded && <div className="absolute inset-0 flex items-center justify-center"><Loader2 className="h-5 w-5 animate-spin opacity-40" /></div>}
                <img
                    src={proxyUrl}
                    alt={alt || 'image'}
                    onLoad={() => setLoaded(true)}
                    onError={() => setErrored(true)}
                    className={`w-full h-full object-contain transition-opacity ${loaded ? 'opacity-100' : 'opacity-0'}`}
                />
            </button>
```

- `MediaVideo` (~210-213): `return <video src={proxyUrl} controls className="w-64 max-w-full h-48 rounded-xl bg-black mb-1 object-contain" />;`

- [ ] **Step 11: Chat page — left list paging (T-0012)**

- Add the list sentinel inside the existing list container (`<div className="flex-1 overflow-y-auto">`, ~1877), after the `filteredList.map(...)` block and before that container's closing `</div>`. No ref is needed on the container; the hook uses the sentinel's parent:

```jsx
                        {listHasMore && (
                            <div ref={listSentinelRef} className="py-3 text-center text-xs text-neutral-400">
                                {listMoreLoading ? t('inbox.loading_more') : null}
                            </div>
                        )}
```

- Add the loader and hook (near `scrollToBottom`):

```js
    const loadMoreConversations = async () => {
        if (!conversations?.next_page_url || loadingListRef.current) return;
        loadingListRef.current = true;
        setListMoreLoading(true);
        try {
            const page = await fetchPage(conversations.next_page_url);
            setConversations(prev => ({ ...page, data: mergeById(prev?.data ?? [], page.data) }));
            setListHasMore(Boolean(page.next_page_url));
        } catch {
            // Scrolling to the end again retries.
        } finally {
            loadingListRef.current = false;
            setListMoreLoading(false);
        }
    };

    useLoadWhenVisible(listSentinelRef, listHasMore && !listMoreLoading, loadMoreConversations);
```

The `setConversations` with `...page` takes the newest page's `next_page_url`, so the chain continues.

- [ ] **Step 12: Build-time checks**

Run: `npx eslint resources/js/Pages/Inbox/Show.jsx resources/js/Utils/inboxPaging.js resources/js/hooks/useLoadWhenVisible.js`
Expected: no errors (warnings only if they existed before; compare with `git stash`-free baseline by reading the output).

Run: `npm run build`
Expected: build succeeds; `public/build/manifest.json` updated.

---

## Task 6 (T-0012): Index page list paging

**Files:**
- Modify: `resources/js/Pages/Inbox/Index.jsx` (import line 10-13; state ~222-231; list body ~351-373)

**Interfaces:**
- Consumes: `fetchPage`, `mergeById` (Task 5); `useLoadWhenVisible` (Task 5); `conversations.next_page_url` from Task 3.

- [ ] **Step 1: Add the imports and state**

- Import: `import { mergeById, fetchPage } from '@/Utils/inboxPaging';` and `import useLoadWhenVisible from '@/hooks/useLoadWhenVisible';` and add `useRef` to the `react` import.
- After `const [showNewModal, setShowNewModal] = useState(false);` add:

```js
    const [listMoreLoading, setListMoreLoading] = useState(false);
    const listSentinelRef = useRef(null);
    const loadingListRef  = useRef(false);
```

- [ ] **Step 2: Add the loader**

```js
    const loadMoreConversations = async () => {
        if (!conversations?.next_page_url || loadingListRef.current) return;
        loadingListRef.current = true;
        setListMoreLoading(true);
        try {
            const page = await fetchPage(conversations.next_page_url);
            setConversations(prev => ({ ...page, data: mergeById(prev?.data ?? [], page.data) }));
        } catch {
            // Scrolling to the end again retries.
        } finally {
            loadingListRef.current = false;
            setListMoreLoading(false);
        }
    };

    useLoadWhenVisible(listSentinelRef, Boolean(conversations?.next_page_url) && !listMoreLoading, loadMoreConversations);
```

- [ ] **Step 3: Render the sentinel**

In the list body (`<div className="flex-1 overflow-y-auto">`, ~351-373), after the `filtered.map(...)` items and before the closing `</div>`, add:

```jsx
                        {conversations?.next_page_url && (
                            <div ref={listSentinelRef} className="py-3 text-center text-xs text-neutral-400">
                                {listMoreLoading ? t('inbox.loading_more') : null}
                            </div>
                        )}
```

Client search (`search`) still filters the conversations already loaded. Document this in the logic doc as a known limit.

- [ ] **Step 4: Checks**

Run: `npx eslint resources/js/Pages/Inbox/Index.jsx`
Expected: no new errors.

Run: `npm run build`
Expected: build succeeds.

---

## Task 7: Documentation, build commit, deploy

**Files:**
- Create: `app/logic/inbox-paging.md`
- Modify: `app/logic/waha-sync-and-labels.md` (media section), `app/logic/realtime-config.md` (new event), `app/logic/README.md` (row)
- Commit: `public/build/` (separate commit)

- [ ] **Step 1: Write the logic docs**

`app/logic/inbox-paging.md` must cover, in short sections: the conversation list page (30, newest activity first, tie-break by id, `next_page_url`, JSON via `Accept: application/json`, search over loaded items only); the chat page (latest 50, oldest first; `hasMoreMessages`; older pages via `client.inbox.messages.index` with cursor `before_sent_at` + `before_id`; ties resolved by id; scroll-up loading at 120 px; position restored after prepend); scroll rules (open at bottom; follow new messages when at bottom or own message; otherwise a "N new message(s)" button); reactions append the returned row; known limits (list resets to page 1 when the page's props refresh; search covers loaded items only).

Update `waha-sync-and-labels.md`: inbound WhatsApp Web media is stored right after the message is saved (`WahaMediaStore::storeInbound`), announced by `MessageMediaReady`; stored copies are found by `media_path` (or the mime-derived name for older copies), never by listing the folder; the lazy `serveMedia` path remains the fallback; failures are logged and the message stays.

Update `realtime-config.md`: add the `MessageMediaReady` event on `conversation.{id}`.

Add the row to `app/logic/README.md`: `| [inbox-paging.md](inbox-paging.md) | Conversation list and chat paging, scroll rules, reactions in the chat |`.

- [ ] **Step 2: Commit the source (frontend, docs, locale)**

```bash
git add resources/js/Utils/inboxPaging.js resources/js/hooks/useLoadWhenVisible.js resources/js/Pages/Inbox/Show.jsx resources/js/Pages/Inbox/Index.jsx resources/js/locales/en.json app/logic/inbox-paging.md app/logic/waha-sync-and-labels.md app/logic/realtime-config.md app/logic/README.md
git commit -m "feat: lazy-load older chat messages and inbox list pages, keep scroll place, show media when stored (T-0012, T-0013)"
```

- [ ] **Step 3: Build commit**

Run: `npm run build`
Then:
```bash
git add public/build
git commit -m "build: regenerate public/build for inbox paging and media updates (T-0012, T-0013)"
```

- [ ] **Step 4: Push and verify**

```bash
git pull --rebase origin main
git push origin main
```

Then fetch `https://wa.esystematics.com/login` and compare the `assets/app-*.js` hash with `public/build/manifest.json`. Report "deployed" only when they match. If they do not match within a reasonable time, report that as not yet deployed. Server commands (none required by this plan: no migration, no config change) are noted in the Result.

---

## Self-Review

**Spec coverage:**
- T-0010 (media at arrival): Task 1 (store), Task 2 (arrival, event, tests).
- T-0011 (lookup without folder scan): Task 1 Steps 3, 5, 6.
- T-0012 (paged list, paged chat, lazy older messages, reactions no reload): Tasks 3, 4, 5 (Steps 4-8, 11), 6.
- T-0013 (scroll rules, reserved media size): Task 5 Steps 5, 9, 10.
- T-0014: not part of this plan (blocked on user checks).
- Docs (`app/logic`): Task 7. Build commit: Task 7. Deploy verification: Task 7 Step 4.

**Placeholder scan:** code steps include the code. The one executor note (Task 4 reaction test reusing names) is explicit and bounded.

**Type consistency:**
- `WahaMediaStore::store(Message, array, string): array` — used by Task 1 controller branch and Task 2 `storeInbound`.
- `existingPath(Message, array): ?string` — used by controller in Task 1.
- `MessageMediaReady` constructor takes `Message` — `MessageMediaReady::dispatch($message->fresh())` in Task 2 and the test's `$e->message->id` matches the `public readonly Message $message` property.
- `messagesPage` route name `client.inbox.messages.index` — used in Task 4 tests and in Task 5 `route('client.inbox.messages.index', conversation.uuid)`.
- `hasMoreMessages` prop — set in Task 4 `show`, read in Task 5 Step 4.
- `fetchPage(url, params?)`, `mergeById(base, extra)`, `mergeMessages(older, current)` — defined in Task 5 Step 1, used in Task 5 and Task 6.
- `useLoadWhenVisible(ref, enabled, onVisible)` — default export, imported as `useLoadWhenVisible` in Tasks 5 and 6.
- State names in Show.jsx: `listMoreLoading`/`setListMoreLoading` (Task 5 Step 4 and Step 11 consistent; the Step 4 text had a rename note).

**Known risks recorded for the Result:**
- Arrival download runs after the webhook response inside PHP's process time limit; a slow engine (30 s timeout) could hit `max_execution_time` after the reply is sent. The message is still saved; the lazy fetch covers it.
- Live browser behaviour (scroll, pill, media arrival) is unverified until checked on the live site and at 360px/1920px, light/dark.
