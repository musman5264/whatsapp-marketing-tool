# WAHA Expansion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add native polls, outbound reactions, call handling, and read-receipts/typing to the WhatsApp-Web (WAHA / personal-number) integration, plus the automation-builder and inbox surfaces that drive them.

**Architecture:** One interface (`EngineAdapter`) gains five methods, implemented only in `WahaAdapter` against WAHA REST endpoints. `WhatsappDriver` grows two new outbound message types (`poll`, `reaction`). `WahaEventProcessor` grows four inbound event handlers (`message.reaction`, `poll.vote`, `call.received`, plus `call.accepted`/`call.rejected`). `AutomationEngine` gets a new `react_message` node, a provider-aware branch in `executeSendPoll`, three new trigger entry points in `AutomationTriggerListener`, and typing/seen calls on the WAHA send path. A migration adds three per-number settings columns.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit (`#[Test]` attributes), Laravel HTTP client + `Http::fake()`, React 18 + Inertia (automation builder & inbox are `.jsx`), Vite build committed to `public/build/`.

**Spec:** `WAHA-EXPANSION-SPEC.md` (repo root). The plan argues from the spec; read both.

## Global Constraints

- **Deploy model:** commit → `git push origin main` → `curl "https://wa2.dermavalue.pk/deploy.php?key=esystematicTechnologies&action=deploy"`. Backend-only changes need no frontend rebuild. **Any change to a file under `resources/js/` requires `npm run build` and committing `public/build/` in the same commit.**
- **Migrations:** the deploy script runs `php artisan migrate --force` automatically. Write reversible `down()`.
- **Config cache:** production does NOT cache config (`php artisan about` → "Config ... NOT CACHED"); `env()` inside config files is fine, `env()` outside config is not.
- **Personal-number detection:** a WAHA conversation is one whose `conversation.channelAccount->provider === 'whatsapp_web'`. The engine session name is `ChannelAccount.phone_number_id` and equals `WhatsappWebSession.session_name` (`ws-{workspaceId}`).
- **chatId format:** WAHA wants `<digits>@c.us` (no `+`). `WahaAdapter::chatId()` already does this conversion — reuse it.
- **WAHA send responses:** return `{id: {_serialized: "..."}}` or `{id: "..."}` depending on engine build. `WahaAdapter::send()` already normalises this — reuse the private `send()` helper where a message id is expected back.
- **Idempotency:** inbound webhook handlers dedupe via `app(WebhookIdempotencyService::class)`. Existing keys look like `"<event>:<id>"`. Follow that shape.
- **Tests:** one behaviour per test, `#[Test]` attribute, `Http::fake()` with URL-specific responses, `Http::assertSent(fn ($r) => ...)` to assert request shape. Never batch multiple features into one test.
- **No secrets in code.** The deploy key above is already user-authorised for this repo's deploy flow only.
- **Automation node badge:** a node that sends exactly as designed on a personal number carries `qr: true` in `NODE_DEFS` (renders a green `(QR)` tag). `react_message` and `send_poll` both qualify after this work.

---

## File Structure

### Backend — created

| File | Responsibility |
|---|---|
| `app/Modules/WhatsappWeb/database/migrations/2026_09_02_000100_add_call_and_receipt_settings_to_whatsapp_web_sessions.php` | Adds `auto_reject_calls`, `call_reject_message`, `send_receipts` columns |
| `tests/Feature/WhatsappWeb/AdapterActionsTest.php` | HTTP-shape tests for the 5 new `WahaAdapter` methods |
| `tests/Feature/WhatsappWeb/PollSendTest.php` | `executeSendPoll` provider branch + `poll` message type on the WAHA send path |
| `tests/Feature/WhatsappWeb/PollVoteInboundTest.php` | `poll.vote` webhook → automation context write-back / resume |
| `tests/Feature/WhatsappWeb/ReactionSendTest.php` | `react_message` node + `reaction` message type (WAHA + Cloud) |
| `tests/Feature/WhatsappWeb/ReactionInboundTest.php` | `message.reaction` webhook → `reaction.received` trigger + inbox storage |
| `tests/Feature/WhatsappWeb/CallHandlingTest.php` | `call.received` → reject-when-on, log-always, trigger-always, idempotency |
| `tests/Feature/WhatsappWeb/ReceiptsTest.php` | typing + sendSeen gated by `send_receipts`; no-op on Cloud |
| `tests/Feature/Inbox/InboxReactionEndpointTest.php` | `POST .../messages/{message}/react` |

### Backend — modified

| File | Change |
|---|---|
| `app/Modules/WhatsappWeb/Services/Waha/WahaClient.php` | Add `put()` method |
| `app/Modules/WhatsappWeb/Contracts/EngineAdapter.php` | Add 5 method signatures |
| `app/Modules/WhatsappWeb/Services/Waha/WahaAdapter.php` | Implement the 5 methods; add `message.reaction`, `poll.vote`, `call.received`, `call.accepted`, `call.rejected` to the `startSession()` webhook `events` array |
| `app/Modules/WhatsappWeb/Models/WhatsappWebSession.php` | Add 3 columns to `$fillable` + casts |
| `app/Modules/WhatsappWeb/Services/WahaEventProcessor.php` | Add `handleReaction`, `handlePollVote`, `handleCall`; extend `process()` match |
| `app/Modules/Whatsapp/Services/WhatsappDriver.php` | `sendViaWhatsappWeb()`: add `poll` + `reaction` arms + typing wrap; `send()` (Cloud): add `reaction` arm |
| `app/Modules/Whatsapp/Services/CloudApiClient.php` | Add `sendReaction()` |
| `app/Modules/Automation/Services/AutomationEngine.php` | `executeSendPoll` provider branch; new `executeReactMessage`; register `react_message` in `executeNode()` + `previewNode()`; typing/seen on WAHA sends; `poll_answer` context on `poll.vote` |
| `app/Modules/Automation/Services/WorkflowGenerator.php` | Add `reaction.received`, `call.received` to `TRIGGER_TYPES` |
| `app/Listeners/AutomationTriggerListener.php` | Add `handleReactionReceived`, `handleCallReceived` |
| `app/Providers/EventServiceProvider.php` (or wherever listeners bind) | Wire the two new events |
| `app/Modules/Inbox/Http/Controllers/InboxController.php` | Add `react()` action; call `sendSeen` on conversation open for WAHA convs |
| `app/Modules/Inbox/routes/web.php` (or module route file) | Register the react route |
| `app/Modules/WhatsappWeb/Http/Controllers/WhatsappWebSessionController.php` | Accept the 3 settings on the session-update endpoint |

### Frontend — modified (each needs `npm run build` + commit `public/build/`)

| File | Change |
|---|---|
| `resources/js/Pages/Automation/Builder.jsx` | New `react_message` node in `NODE_DEFS` + `FIELD_COMPONENTS` + a `ReactMessageFields` component; `send_poll` gets `qr: true`; `PollFields` gets "multiple answers" + "save answer to variable" |
| `resources/js/locales/en.json` | New keys for the above + call-settings UI + inbox react |
| Inbox thread component (path: found in step of Task 15) | Hover emoji-react button; render inbound reaction badge |
| WhatsApp-Web number setup page (path: found in step of Task 18) | "Calls" settings card |

---

## Phase 1 — Adapter foundation

### Task 1: `WahaClient::put()`

**Files:**
- Modify: `app/Modules/WhatsappWeb/Services/Waha/WahaClient.php`
- Test: covered indirectly by Task 2 (no standalone test — it is a one-line HTTP passthrough mirroring the existing `post`/`delete`)

**Interfaces:**
- Produces: `WahaClient::put(string $path, array $body = []): \Illuminate\Http\Client\Response`

- [ ] **Step 1: Add the method**

In `WahaClient.php`, after the `delete()` method (around line 54), add:

```php
    /** @param array<string,mixed> $body */
    public function put(string $path, array $body = []): Response
    {
        return $this->http()->put($this->url($path), $body);
    }
```

- [ ] **Step 2: Sanity check**

Run: `php -l app/Modules/WhatsappWeb/Services/Waha/WahaClient.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Modules/WhatsappWeb/Services/Waha/WahaClient.php
git commit -m "WahaClient: add put() for the reaction endpoint"
```

---

### Task 2: `EngineAdapter` + `WahaAdapter` — the five new methods

**Files:**
- Modify: `app/Modules/WhatsappWeb/Contracts/EngineAdapter.php`
- Modify: `app/Modules/WhatsappWeb/Services/Waha/WahaAdapter.php`
- Test: `tests/Feature/WhatsappWeb/AdapterActionsTest.php` (create)

**Interfaces:**
- Consumes: `WahaClient::put()` (Task 1), existing `WahaAdapter::chatId()`, `WahaAdapter::send()`
- Produces (add to `EngineAdapter` and implement in `WahaAdapter`):
  - `sendPoll(string $session, string $toE164, string $question, array $options, bool $multipleAnswers): string`
  - `sendReaction(string $session, string $messageId, string $emoji): void`
  - `sendSeen(string $session, string $chatId, ?string $messageId = null): void`
  - `sendTyping(string $session, string $toE164, bool $on): void`
  - `rejectCall(string $session, string $callId): void`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WhatsappWeb/AdapterActionsTest.php`:

```php
<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\WhatsappWeb\Services\Waha\WahaAdapter;
use App\Modules\WhatsappWeb\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdapterActionsTest extends TestCase
{
    private function adapter(): WahaAdapter
    {
        return new WahaAdapter(new WahaClient('http://waha.test', 'k'));
    }

    #[Test]
    public function send_poll_posts_the_poll_shape_and_returns_the_id(): void
    {
        Http::fake(['waha.test/api/sendPoll' => Http::response(['id' => ['_serialized' => 'poll_1']], 201)]);

        $id = $this->adapter()->sendPoll('ws-1', '+15551230000', 'Pick one', ['A', 'B', 'C'], false);

        $this->assertSame('poll_1', $id);
        Http::assertSent(function ($r) {
            return str_ends_with($r->url(), '/api/sendPoll')
                && $r['session'] === 'ws-1'
                && $r['chatId'] === '15551230000@c.us'
                && $r['poll']['name'] === 'Pick one'
                && $r['poll']['options'] === ['A', 'B', 'C']
                && $r['poll']['multipleAnswers'] === false;
        });
    }

    #[Test]
    public function send_reaction_puts_to_the_reaction_endpoint(): void
    {
        Http::fake(['waha.test/api/reaction' => Http::response([], 200)]);

        $this->adapter()->sendReaction('ws-1', 'true_15551230000@c.us_ABC', '👍');

        Http::assertSent(function ($r) {
            return $r->method() === 'PUT'
                && str_ends_with($r->url(), '/api/reaction')
                && $r['session'] === 'ws-1'
                && $r['messageId'] === 'true_15551230000@c.us_ABC'
                && $r['reaction'] === '👍';
        });
    }

    #[Test]
    public function send_seen_posts_chat_id_and_optional_message_id(): void
    {
        Http::fake(['waha.test/api/sendSeen' => Http::response([], 200)]);

        $this->adapter()->sendSeen('ws-1', '15551230000@c.us', 'msg_9');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendSeen')
            && $r['session'] === 'ws-1'
            && $r['chatId'] === '15551230000@c.us'
            && $r['messageId'] === 'msg_9');
    }

    #[Test]
    public function send_typing_on_hits_start_typing_and_off_hits_stop_typing(): void
    {
        Http::fake([
            'waha.test/api/startTyping' => Http::response([], 200),
            'waha.test/api/stopTyping' => Http::response([], 200),
        ]);

        $this->adapter()->sendTyping('ws-1', '+15551230000', true);
        $this->adapter()->sendTyping('ws-1', '+15551230000', false);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/startTyping') && $r['chatId'] === '15551230000@c.us');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/stopTyping') && $r['chatId'] === '15551230000@c.us');
    }

    #[Test]
    public function reject_call_posts_the_call_id_to_the_session_scoped_endpoint(): void
    {
        Http::fake(['waha.test/api/ws-1/calls/reject' => Http::response([], 200)]);

        $this->adapter()->rejectCall('ws-1', 'call_42');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/ws-1/calls/reject') && $r['callId'] === 'call_42');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/WhatsappWeb/AdapterActionsTest.php`
Expected: FAIL — `Call to undefined method ...::sendPoll()` (and the rest)

- [ ] **Step 3: Add signatures to the interface**

In `app/Modules/WhatsappWeb/Contracts/EngineAdapter.php`, before the closing brace, add:

```php
    /** Send a native poll. Returns the engine message id. */
    public function sendPoll(string $session, string $toE164, string $question, array $options, bool $multipleAnswers): string;

    /** React to a message with an emoji. Pass an empty string to remove the reaction. */
    public function sendReaction(string $session, string $messageId, string $emoji): void;

    /** Mark a chat's messages read (blue ticks), optionally up to one message id. */
    public function sendSeen(string $session, string $chatId, ?string $messageId = null): void;

    /** Toggle the typing indicator for a chat. */
    public function sendTyping(string $session, string $toE164, bool $on): void;

    /** Reject an incoming call by its engine call id. */
    public function rejectCall(string $session, string $callId): void;
```

- [ ] **Step 4: Implement in `WahaAdapter`**

In `app/Modules/WhatsappWeb/Services/Waha/WahaAdapter.php`, after `sendLocation()` (around line 216), add:

```php
    public function sendPoll(string $session, string $toE164, string $question, array $options, bool $multipleAnswers): string
    {
        return $this->send('/api/sendPoll', [
            'session' => $session,
            'chatId' => $this->chatId($toE164),
            'poll' => [
                'name' => $question,
                'options' => array_values($options),
                'multipleAnswers' => $multipleAnswers,
            ],
        ]);
    }

    public function sendReaction(string $session, string $messageId, string $emoji): void
    {
        $resp = $this->client->put('/api/reaction', [
            'session' => $session,
            'messageId' => $messageId,
            'reaction' => $emoji,
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('WAHA reaction failed ('.$resp->status().'): '.$resp->body());
        }
    }

    public function sendSeen(string $session, string $chatId, ?string $messageId = null): void
    {
        $payload = ['session' => $session, 'chatId' => $chatId];
        if ($messageId !== null && $messageId !== '') {
            $payload['messageId'] = $messageId;
        }
        $this->client->post('/api/sendSeen', $payload);
    }

    public function sendTyping(string $session, string $toE164, bool $on): void
    {
        $this->client->post($on ? '/api/startTyping' : '/api/stopTyping', [
            'session' => $session,
            'chatId' => $this->chatId($toE164),
        ]);
    }

    public function rejectCall(string $session, string $callId): void
    {
        $this->client->post("/api/{$session}/calls/reject", ['callId' => $callId]);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/WhatsappWeb/AdapterActionsTest.php`
Expected: PASS (5 tests)

- [ ] **Step 6: Run the full WhatsappWeb suite for regressions**

Run: `php artisan test tests/Feature/WhatsappWeb`
Expected: PASS (all existing + 5 new)

- [ ] **Step 7: Commit**

```bash
git add app/Modules/WhatsappWeb/Contracts/EngineAdapter.php app/Modules/WhatsappWeb/Services/Waha/WahaAdapter.php tests/Feature/WhatsappWeb/AdapterActionsTest.php
git commit -m "WahaAdapter: sendPoll, sendReaction, sendSeen, sendTyping, rejectCall"
```

---

### Task 3: Subscribe to the new webhook events

**Files:**
- Modify: `app/Modules/WhatsappWeb/Services/Waha/WahaAdapter.php:29-40` (the `startSession` `$webhook['events']` array)
- Test: `tests/Feature/WhatsappWeb/SessionConnectTest.php` (extend — check it exists; if a session-start test asserts the events array, add to it; otherwise add one test here)

**Interfaces:**
- Consumes: nothing new
- Produces: `startSession()` now subscribes `message.reaction`, `poll.vote`, `call.received`, `call.accepted`, `call.rejected` in addition to the existing three.

- [ ] **Step 1: Write / extend the failing test**

In `tests/Feature/WhatsappWeb/SessionConnectTest.php` add (adapt the fixture setup to match the file's existing style — it already fakes WAHA and calls the connect path):

```php
    #[Test]
    public function start_session_subscribes_to_the_new_event_types(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        // ... trigger the connect/start path the same way other tests in this file do ...

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/api/sessions') || $r->method() !== 'POST') {
                return false;
            }
            $events = data_get($r->data(), 'config.webhooks.0.events', []);
            foreach (['message', 'session.status', 'message.ack', 'message.reaction', 'poll.vote', 'call.received', 'call.accepted', 'call.rejected'] as $want) {
                if (! in_array($want, $events, true)) {
                    return false;
                }
            }
            return true;
        });
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/WhatsappWeb/SessionConnectTest.php`
Expected: FAIL — new events not in the array

- [ ] **Step 3: Add the events**

In `WahaAdapter::startSession()`, change:

```php
        $webhook = [
            'url' => $webhookUrl,
            'events' => ['message', 'session.status', 'message.ack'],
        ];
```

to:

```php
        $webhook = [
            'url' => $webhookUrl,
            'events' => [
                'message', 'session.status', 'message.ack',
                'message.reaction', 'poll.vote',
                'call.received', 'call.accepted', 'call.rejected',
            ],
        ];
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/WhatsappWeb/SessionConnectTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/WhatsappWeb/Services/Waha/WahaAdapter.php tests/Feature/WhatsappWeb/SessionConnectTest.php
git commit -m "WAHA: subscribe to reaction, poll.vote and call.* webhook events"
```

---

### Task 4: Settings migration + model

**Files:**
- Create: `app/Modules/WhatsappWeb/database/migrations/2026_09_02_000100_add_call_and_receipt_settings_to_whatsapp_web_sessions.php`
- Modify: `app/Modules/WhatsappWeb/Models/WhatsappWebSession.php`
- Test: `tests/Feature/WhatsappWeb/ReceiptsTest.php` (create — starts here with a defaults test; grows in Task 14)

**Interfaces:**
- Produces: `WhatsappWebSession` has `auto_reject_calls` (bool, default false), `call_reject_message` (string|null), `send_receipts` (bool, default true), all in `$fillable` and cast.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WhatsappWeb/ReceiptsTest.php`:

```php
<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiptsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_session_defaults_receipts_on_and_call_reject_off(): void
    {
        $s = WhatsappWebSession::create([
            'workspace_id' => 1,
            'session_name' => 'ws-1',
            'webhook_token' => str_repeat('a', 48),
        ]);

        $this->assertTrue($s->fresh()->send_receipts);
        $this->assertFalse($s->fresh()->auto_reject_calls);
        $this->assertNull($s->fresh()->call_reject_message);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/WhatsappWeb/ReceiptsTest.php`
Expected: FAIL — `Undefined property ... $send_receipts` / column missing

- [ ] **Step 3: Write the migration**

Create the migration file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_web_sessions', function (Blueprint $table) {
            $table->boolean('auto_reject_calls')->default(false)->after('meta_json');
            $table->text('call_reject_message')->nullable()->after('auto_reject_calls');
            $table->boolean('send_receipts')->default(true)->after('call_reject_message');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_web_sessions', function (Blueprint $table) {
            $table->dropColumn(['auto_reject_calls', 'call_reject_message', 'send_receipts']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `WhatsappWebSession.php`, extend `$fillable`:

```php
    protected $fillable = [
        'workspace_id', 'session_name', 'engine', 'phone_e164', 'push_name',
        'status', 'last_qr', 'webhook_token', 'webhook_token_hash',
        'last_seen_at', 'meta_json',
        'auto_reject_calls', 'call_reject_message', 'send_receipts',
    ];
```

and extend `casts()`:

```php
    protected function casts(): array
    {
        return [
            'meta_json' => 'array',
            'last_seen_at' => 'datetime',
            'auto_reject_calls' => 'boolean',
            'send_receipts' => 'boolean',
        ];
    }
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Feature/WhatsappWeb/ReceiptsTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Modules/WhatsappWeb/database/migrations/2026_09_02_000100_add_call_and_receipt_settings_to_whatsapp_web_sessions.php app/Modules/WhatsappWeb/Models/WhatsappWebSession.php tests/Feature/WhatsappWeb/ReceiptsTest.php
git commit -m "WhatsappWebSession: add auto_reject_calls, call_reject_message, send_receipts"
```

---

## Phase 2 — Native poll

### Task 5: `poll` outbound message type on the WAHA send path

**Files:**
- Modify: `app/Modules/Whatsapp/Services/WhatsappDriver.php` (`sendViaWhatsappWeb()` match, around line 98-128)
- Test: `tests/Feature/WhatsappWeb/PollSendTest.php` (create)

**Interfaces:**
- Consumes: `EngineAdapter::sendPoll()` (Task 2)
- Produces: a `Message` with `type: 'poll'` and `payload.poll = {question: string, options: string[], multiple: bool}` sent through `WhatsappDriver` on a `whatsapp_web` conversation calls `adapter->sendPoll(...)` and returns its id.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WhatsappWeb/PollSendTest.php` (model the fixture on `OutboundSendTest.php` — same `IntegrationConfig` + `ChannelAccount` (`provider: 'whatsapp_web'`) + `Conversation` setup):

```php
<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PollSendTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'],
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel' => 'whatsapp', 'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-'.$ctx['workspace']->id,
            'display_name' => 'WA', 'status' => 'active',
        ]);
        $contact = Contact::factory()->create([
            'workspace_id' => $ctx['workspace']->id, 'phone_e164' => '+15551230000',
        ]);
        $this->conversation = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    #[Test]
    public function a_poll_message_is_sent_natively_via_waha(): void
    {
        Http::fake(['waha.test/api/sendPoll' => Http::response(['id' => 'poll_x'], 201)]);

        $msg = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out', 'channel' => 'whatsapp', 'type' => 'poll',
            'body' => 'Favourite colour?', 'status' => 'queued',
            'sent_by' => 'automation', 'sent_at' => now(),
            'payload' => ['poll' => ['question' => 'Favourite colour?', 'options' => ['Red', 'Blue'], 'multiple' => false]],
        ]);

        $id = app(WhatsappDriver::class)->send($msg);

        $this->assertSame('poll_x', $id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendPoll')
            && $r['poll']['name'] === 'Favourite colour?'
            && $r['poll']['options'] === ['Red', 'Blue']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/WhatsappWeb/PollSendTest.php`
Expected: FAIL — `poll` falls through to the `default` arm (`sendText`), so no `/api/sendPoll` request

- [ ] **Step 3: Add the `poll` arm**

In `WhatsappDriver::sendViaWhatsappWeb()`, inside the `match ($message->type)` block, add before `'location' =>`:

```php
            'poll' => $adapter->sendPoll(
                $session,
                $phone,
                (string) ($payload['poll']['question'] ?? $message->body ?? ''),
                array_values((array) ($payload['poll']['options'] ?? [])),
                (bool) ($payload['poll']['multiple'] ?? false),
            ),
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/WhatsappWeb/PollSendTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Whatsapp/Services/WhatsappDriver.php tests/Feature/WhatsappWeb/PollSendTest.php
git commit -m "WhatsappDriver: send native polls on the WAHA path"
```

---

### Task 6: `executeSendPoll` — branch on provider

**Files:**
- Modify: `app/Modules/Automation/Services/AutomationEngine.php` (`executeSendPoll`, around line 1318-1336)
- Test: `tests/Feature/WhatsappWeb/PollSendTest.php` (add a case), plus a regression check that the Cloud emulation still works

**Interfaces:**
- Consumes: `Message` type `poll` send path (Task 5); existing `resolveChannelTarget()` returns `['account' => ChannelAccount, ...]`
- Produces: `executeSendPoll` emits a `poll`-type message when the resolved account is `whatsapp_web`, and keeps the existing button/list emulation otherwise.

- [ ] **Step 1: Write the failing test**

Add to `PollSendTest.php` — a test that runs the `send_poll` node through the engine against the `whatsapp_web` conversation and asserts `/api/sendPoll` was hit. Model the run setup on `tests/Feature/Automation/AutomationSendNodesTest.php` (it builds an `Automation` + `AutomationRun` and calls `(new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class))`). The automation's single node:

```php
['id' => 'n1', 'type' => 'send_poll', 'data' => [
    'question' => 'Tea or coffee?',
    'options' => "Tea\nCoffee",
    'result_var' => 'drink',
]]
```

Assert: `Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendPoll'))`.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/WhatsappWeb/PollSendTest.php`
Expected: FAIL — engine currently always emulates via `sendWhatsappPayload(..., 'interactive', ...)`, so `/api/sendText` is hit (interactive degrades), not `/api/sendPoll`

- [ ] **Step 3: Branch in `executeSendPoll`**

Replace the body of `executeSendPoll` after the `$question`/`$options` validation with:

```php
        $multiple = (bool) ($data['multiple'] ?? false);
        $resultVar = ($data['result_var'] ?? '') ?: 'poll_answer';

        // Remember which variable the vote lands in, and that this run may get a
        // poll.vote later (see WahaEventProcessor::handlePollVote()).
        $ctxUpdate = ['_poll_result_var' => $resultVar];

        $contactModel = Contact::find($run->contact_id);
        $target = $this->resolveChannelTarget($run->automation->workspace_id, $contactModel, 'whatsapp');
        $isPersonal = $target['account']?->provider === 'whatsapp_web';

        if ($isPersonal) {
            $res = $this->sendWhatsappPayload($run, 'poll', $question, [
                'poll' => ['question' => $question, 'options' => $options, 'multiple' => $multiple],
            ]);
        } else {
            // Cloud API has no native poll — emulate with reply buttons (≤3) or a list.
            $interactive = count($options) <= 3
                ? $this->buttonInteractive($question, $options)
                : $this->listInteractive($question, (string) ($data['button_label'] ?? 'Vote'), 'Options', array_map(fn ($o) => ['title' => $o, 'description' => ''], $options));
            $res = $this->sendWhatsappPayload($run, 'interactive', $question, ['interactive' => $interactive]);
        }

        if (($res['status'] ?? '') === 'ok') {
            $res['context_update'] = array_merge($res['context_update'] ?? [], $ctxUpdate);
        }

        return $res;
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/WhatsappWeb/PollSendTest.php`
Expected: PASS

- [ ] **Step 5: Regression — the Cloud emulation still works**

Run: `php artisan test --filter=Poll` then `php artisan test tests/Feature/Automation`
Expected: PASS (any existing poll-emulation test in the Automation suite still green)

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Automation/Services/AutomationEngine.php tests/Feature/WhatsappWeb/PollSendTest.php
git commit -m "Automation: send native poll on personal numbers, keep emulation on Cloud"
```

---

### Task 7: `poll.vote` inbound → context write-back + resume

**Files:**
- Modify: `app/Modules/WhatsappWeb/Services/WahaEventProcessor.php`
- Modify: `app/Modules/Automation/Services/AutomationEngine.php` (add a public `applyPollVote` helper)
- Test: `tests/Feature/WhatsappWeb/PollVoteInboundTest.php` (create)

**Interfaces:**
- Consumes: `WebhookIdempotencyService`, `AutomationRun`, `Message`
- Produces:
  - `WahaEventProcessor::process()` routes `poll.vote` to `handlePollVote()`
  - `AutomationEngine::applyPollVote(string $pollProviderMessageId, array $selectedOptions): void` — finds the run whose `poll` message matches, merges `{<_poll_result_var>: "A, B"}` into `context`, and resumes the run if `status === 'waiting'` and it is parked after the poll node.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WhatsappWeb/PollVoteInboundTest.php`. Setup: a `whatsapp_web` session + an `Automation` with nodes `[send_poll (n1) -> send_whatsapp (n2)]`, an `AutomationRun` in `status: 'waiting'`, `resume_node_id: 'n2'`, `context: ['_poll_result_var' => 'drink', '_awaiting_poll' => true]`, and an outbound `Message` `type: 'poll'`, `provider_message_id: 'poll_abc'` on that run's contact conversation. Fire:

```php
$payload = [
    'event' => 'poll.vote',
    'session' => 'ws-'.$ws->id,
    'payload' => [
        'id' => 'vote_1',
        'pollMessageId' => 'poll_abc',
        'vote' => ['selectedOptions' => ['Coffee']],
    ],
];
app(\App\Modules\WhatsappWeb\Services\WahaEventProcessor::class)->process($payload, $session);

$this->assertSame('Coffee', $run->fresh()->context['drink']);
$this->assertNotSame('waiting', $run->fresh()->status); // resumed
```

Also assert a second `process()` with the same payload is a no-op (idempotency).

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/WhatsappWeb/PollVoteInboundTest.php`
Expected: FAIL — `poll.vote` hits the `default` (log + ignore) arm

- [ ] **Step 3: Add `handlePollVote` to `WahaEventProcessor`**

Add to the `process()` match:

```php
            $event === 'poll.vote' => $this->handlePollVote($payload),
```

Add the method (and `use App\Modules\Automation\Services\AutomationEngine;` + inject it via the constructor — the constructor already takes 3 services, add a 4th):

```php
    /** @param array<string,mixed> $payload */
    private function handlePollVote(array $payload): void
    {
        $p = $payload['payload'] ?? [];
        $pollMessageId = (string) ($p['pollMessageId'] ?? $p['poll']['id'] ?? '');
        $voter = (string) ($p['from'] ?? $p['voter'] ?? '');
        $selected = (array) ($p['vote']['selectedOptions'] ?? $p['selectedOptions'] ?? []);

        if ($pollMessageId === '') {
            return;
        }

        $key = 'pollvote:'.$pollMessageId.':'.$voter.':'.implode(',', $selected);
        if (! app(\App\Modules\Whatsapp\Services\WebhookIdempotencyService::class)->isNewEvent('whatsapp_web', $key)) {
            return;
        }

        app(AutomationEngine::class)->applyPollVote($pollMessageId, $selected);
    }
```

> Confirm the idempotency service's FQCN and `isNewEvent` signature by grepping `WebhookIdempotencyService` in `WhatsappWebWebhookController.php` — match whatever that controller uses exactly.

- [ ] **Step 4: Add `applyPollVote` to `AutomationEngine`**

```php
    /**
     * A contact voted on a poll we sent from an automation. Write their choice
     * into the run context and resume the run if it is parked after the poll.
     *
     * @param  array<int,string>  $selectedOptions
     */
    public function applyPollVote(string $pollProviderMessageId, array $selectedOptions): void
    {
        $message = Message::where('provider_message_id', $pollProviderMessageId)
            ->where('type', 'poll')
            ->latest('id')
            ->first();
        if (! $message) {
            return;
        }

        $run = AutomationRun::where('contact_id', $message->conversation->contact_id)
            ->whereIn('status', ['waiting', 'running', 'completed'])
            ->latest('id')
            ->first();
        if (! $run) {
            return;
        }

        $context = $run->context ?? [];
        $var = $context['_poll_result_var'] ?? 'poll_answer';
        $context[$var] = implode(', ', $selectedOptions);
        unset($context['_awaiting_poll']);
        $run->update(['context' => $context]);

        if ($run->status === 'waiting') {
            $this->runNowOrQueue($run);
        }
    }
```

> `runNowOrQueue` is private but this method is in the same class. Confirm its name by grep (it is used by `resumeAwaitingReplies`).

- [ ] **Step 5: Make the poll node park the run (so there is something to resume)**

In `executeSendPoll` (Task 6's code), when `$isPersonal` and the node has a downstream edge, park like `executeAskQuestion` does — set `status: 'waiting'`, `resume_node_id`, and return `'status' => 'waiting'` with `context_update` including `_awaiting_poll => true`. If the design prefers the poll to NOT block the flow (fire-and-forget, vote lands later), skip parking and just persist `_poll_result_var`. **Decision for this plan: park only if the poll node's next node exists AND `data.wait_for_vote` is set; default is fire-and-forget.** Add a `wait_for_vote` checkbox in Task 9.

Adjust Task 6's returned array accordingly:

```php
        if ($isPersonal && ($data['wait_for_vote'] ?? false) && ($res['status'] ?? '') === 'ok') {
            $edges = collect($run->automation->edges ?? []);
            $resumeNodeId = $this->nextNodeId($edges, (string) $run->current_node_id, null);
            $run->update(['status' => 'waiting', 'resume_node_id' => $resumeNodeId]);
            return ['status' => 'waiting', 'message' => 'Poll sent — waiting for a vote.',
                'context_update' => array_merge($ctxUpdate, ['_awaiting_poll' => true])];
        }
```

- [ ] **Step 6: Run to verify it passes**

Run: `php artisan test tests/Feature/WhatsappWeb/PollVoteInboundTest.php`
Expected: PASS

- [ ] **Step 7: Full automation + whatsappweb regression**

Run: `php artisan test tests/Feature/Automation tests/Feature/WhatsappWeb`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Modules/WhatsappWeb/Services/WahaEventProcessor.php app/Modules/Automation/Services/AutomationEngine.php tests/Feature/WhatsappWeb/PollVoteInboundTest.php
git commit -m "Automation: poll.vote writes the choice into run context and resumes"
```

---

### Task 8: Poll node — frontend (QR badge + new fields)

**Files:**
- Modify: `resources/js/Pages/Automation/Builder.jsx` (`NODE_DEFS.send_poll`, `PollFields`)
- Modify: `resources/js/locales/en.json`
- Build: `npm run build`

**Interfaces:**
- Consumes: `d.multiple`, `d.result_var`, `d.wait_for_vote` are read by `executeSendPoll` (Tasks 6-7)
- Produces: builder writes those three keys onto the node's `data`.

- [ ] **Step 1: Add `qr: true` to `send_poll`**

In `NODE_DEFS`, change the `send_poll` line to end with `category: 'engage', qr: true },` (align with the others).

- [ ] **Step 2: Extend `PollFields`**

Replace `PollFields` with:

```jsx
function PollFields({ d, set }) {
    const { t } = useTranslation();
    return (
        <>
            <Field label={t('automation.field_question_required')}>
                <textarea className={textareaCls} rows={2} value={d.question ?? ''} onChange={e => set('question', e.target.value)} placeholder={t('automation.placeholder_poll_question')} />
            </Field>
            <Field label={t('automation.field_poll_options_required')}>
                <textarea className={textareaCls} rows={4} value={d.options ?? ''} onChange={e => set('options', e.target.value)} placeholder={t('automation.placeholder_poll_options')} />
            </Field>
            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: '#374151' }}>
                <input type="checkbox" checked={!!d.multiple} onChange={e => set('multiple', e.target.checked)} />
                {t('automation.poll_multiple')}
            </label>
            <Field label={t('automation.field_poll_result_var')}>
                <input className={inputCls} value={d.result_var ?? ''} onChange={e => set('result_var', e.target.value)} placeholder="poll_answer" />
            </Field>
            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: '#374151' }}>
                <input type="checkbox" checked={!!d.wait_for_vote} onChange={e => set('wait_for_vote', e.target.checked)} />
                {t('automation.poll_wait_for_vote')}
            </label>
            <p style={{ fontSize: 10, color: '#64748b' }}>{t('automation.poll_hint')}</p>
        </>
    );
}
```

- [ ] **Step 3: Add locale keys**

In `en.json`, in the `automation` block, add:

```json
        "poll_multiple": "Allow multiple answers",
        "field_poll_result_var": "Save the answer to variable",
        "poll_wait_for_vote": "Pause the flow until someone votes (personal numbers only)",
```

- [ ] **Step 4: Build**

Run: `npm run build`
Expected: `✓ built`

- [ ] **Step 5: Lint the touched file**

Run: `npx eslint resources/js/Pages/Automation/Builder.jsx`
Expected: no NEW errors (pre-existing warnings at lines ~545, ~1749, ~1830, ~2043 are acceptable)

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Automation/Builder.jsx resources/js/locales/en.json public/build
git commit -m "Automation builder: poll node gets (QR) badge, multiple-answers, result var, wait-for-vote"
```

---

## Phase 3 — Reactions

### Task 9: `reaction` outbound message type (WAHA + Cloud)

**Files:**
- Modify: `app/Modules/Whatsapp/Services/WhatsappDriver.php` (`send()` Cloud match + `sendViaWhatsappWeb()` match)
- Modify: `app/Modules/Whatsapp/Services/CloudApiClient.php` (add `sendReaction`)
- Test: `tests/Feature/WhatsappWeb/ReactionSendTest.php` (create)

**Interfaces:**
- Consumes: `EngineAdapter::sendReaction()` (Task 2), `Message::provider_message_id`
- Produces: a `Message` `type: 'reaction'`, `payload: {target_message_id: int, emoji: string}` — the driver resolves `target_message_id` → that message's `provider_message_id` → `adapter->sendReaction()` (WAHA) or `CloudApiClient::sendReaction()` (Cloud). Returns `''` (reactions have no meaningful id to track) or the provider id if given.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WhatsappWeb/ReactionSendTest.php` — fixture like `PollSendTest.php`. Create an **inbound** `Message` (`direction: 'in'`, `provider_message_id: 'inbound_abc'`) then an **outbound** `Message` (`type: 'reaction'`, `payload: ['target_message_id' => $inbound->id, 'emoji' => '❤️']`). Assert:

```php
Http::fake(['waha.test/api/reaction' => Http::response([], 200)]);
app(WhatsappDriver::class)->send($reactionMsg);
Http::assertSent(fn ($r) => $r->method() === 'PUT'
    && str_ends_with($r->url(), '/api/reaction')
    && $r['messageId'] === 'inbound_abc'
    && $r['reaction'] === '❤️');
```

Add a second test with a **Cloud API** conversation (`provider` not `whatsapp_web`, an active `whatsapp` Cloud `ChannelAccount` + faked graph endpoint) asserting the Meta reaction shape:

```php
Http::assertSent(fn ($r) => str_contains($r->url(), '/messages')
    && data_get($r->data(), 'type') === 'reaction'
    && data_get($r->data(), 'reaction.message_id') === 'inbound_abc'
    && data_get($r->data(), 'reaction.emoji') === '❤️');
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/WhatsappWeb/ReactionSendTest.php`
Expected: FAIL — `reaction` falls through to `default` (`sendText`) on both paths

- [ ] **Step 3: Add `CloudApiClient::sendReaction`**

In `CloudApiClient.php`, after `sendInteractive()` (around line 377):

```php
    /** React to a message with an emoji (empty string removes the reaction). */
    public function sendReaction(string $to, string $messageId, string $emoji): Response
    {
        return $this->post("/{$this->phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'reaction',
            'reaction' => ['message_id' => $messageId, 'emoji' => $emoji],
        ]);
    }
```

- [ ] **Step 4: Add a shared resolver + both match arms in `WhatsappDriver`**

Add a private helper:

```php
    /** Resolve a local target-message id (from a reaction payload) to its provider id. */
    private function targetProviderId(?int $localId): string
    {
        if (! $localId) {
            return '';
        }
        return (string) (\App\Modules\Shared\Models\Message::whereKey($localId)->value('provider_message_id') ?? '');
    }
```

In `send()` (Cloud path `match`), add before `default =>`:

```php
            'reaction' => (function () use ($client, $phone, $payload) {
                $target = $this->targetProviderId($payload['target_message_id'] ?? null);
                if ($target === '') {
                    throw new \RuntimeException('Cannot react — the target message has no provider id yet.');
                }
                return $client->sendReaction($phone, $target, (string) ($payload['emoji'] ?? ''));
            })(),
```

In `sendViaWhatsappWeb()` (`match`), add before `default =>`:

```php
            'reaction' => (function () use ($adapter, $session, $payload) {
                $target = $this->targetProviderId($payload['target_message_id'] ?? null);
                if ($target === '') {
                    throw new \RuntimeException('Cannot react — the target message has no provider id yet.');
                }
                $adapter->sendReaction($session, $target, (string) ($payload['emoji'] ?? ''));
                return '';
            })(),
```

> Note: `send()`'s Cloud branch ends with `return $resp->json('messages.0.id', '')` — the `reaction` arm returns a `Response`, so it flows through the same `$resp->successful()` check. Good. `sendViaWhatsappWeb` returns a string directly, so the closure returning `''` is fine.

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Feature/WhatsappWeb/ReactionSendTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Whatsapp/Services/WhatsappDriver.php app/Modules/Whatsapp/Services/CloudApiClient.php tests/Feature/WhatsappWeb/ReactionSendTest.php
git commit -m "WhatsappDriver: outbound reactions on both Cloud API and WAHA"
```

---

### Task 10: `react_message` automation node

**Files:**
- Modify: `app/Modules/Automation/Services/AutomationEngine.php` (`executeNode()` match, `previewNode()` match, new `executeReactMessage`)
- Test: `tests/Feature/WhatsappWeb/ReactionSendTest.php` (add an engine-level case) or a new `tests/Feature/Automation/ReactMessageNodeTest.php`

**Interfaces:**
- Consumes: `context['message_id']` (local `Message` PK of the trigger message), the `reaction` send path (Task 9)
- Produces: node type `react_message`, `data: {emoji: string}`. `executeReactMessage` emits `Message {type: 'reaction', payload: {target_message_id: context.message_id, emoji}}` via `dispatchMessage`. Skips with a clear message when `context['message_id']` is absent.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Automation/ReactMessageNodeTest.php`. Setup like `PollVoteInboundTest` — a `whatsapp_web` conversation, an inbound `Message` (`provider_message_id: 'in_1'`), an `Automation` with one node `['id' => 'n1', 'type' => 'react_message', 'data' => ['emoji' => '👍']]`, an `AutomationRun` with `context: ['message_id' => $inbound->id]`. Run via `ExecuteAutomationRunJob`. Assert `/api/reaction` was PUT with `messageId: 'in_1'`, `reaction: '👍'`.

Add a second test: run with `context: []` (no `message_id`) — assert no HTTP call and the run's log for `n1` has `result: 'skipped'`.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Automation/ReactMessageNodeTest.php`
Expected: FAIL — `react_message` hits `default => ['status' => 'skipped', 'message' => "Unknown node type: react_message"]`

- [ ] **Step 3: Register the node + implement**

In `executeNode()`'s match, add under the ENGAGE group:

```php
                'react_message' => $this->executeReactMessage($data, $run, $context),
```

In `previewNode()`'s match, add:

```php
            'react_message' => ($data['emoji'] ?? '') === ''
                ? $err('Pick an emoji to react with.')
                : $ok('Would react '.$data['emoji'].' to the trigger message.'),
```

Add the method near `executeSendPoll`:

```php
    private function executeReactMessage(array $data, AutomationRun $run, array $context): array
    {
        $emoji = (string) ($data['emoji'] ?? '');
        if ($emoji === '') {
            return ['status' => 'error', 'message' => 'No emoji configured.'];
        }

        $targetId = $context['message_id'] ?? null;
        if (! $targetId) {
            return ['status' => 'skipped', 'message' => 'No trigger message to react to (this automation was not started by an inbound message).'];
        }

        return $this->sendWhatsappPayload($run, 'reaction', null, [
            'target_message_id' => (int) $targetId,
            'emoji' => $emoji,
        ]);
    }
```

> `sendWhatsappPayload` → `dispatchMessage` will early-return "Nothing to send" for empty-body non-text types unless `payload.interactive`/`payload.template` is set. Check `dispatchMessage`'s guard (around line 1768): it only blocks `type` in `['text', 'template']`. `reaction` is neither, so it passes. Good — no change needed. Verify during implementation.

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Automation/ReactMessageNodeTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Automation/Services/AutomationEngine.php tests/Feature/Automation/ReactMessageNodeTest.php
git commit -m "Automation: react_message node reacts to the trigger message"
```

---

### Task 11: `react_message` node — frontend

**Files:**
- Modify: `resources/js/Pages/Automation/Builder.jsx` (NODE_DEFS, FIELD_COMPONENTS, new `ReactMessageFields`, an icon import)
- Modify: `resources/js/locales/en.json`
- Build: `npm run build`

**Interfaces:**
- Produces: builder writes `data.emoji` (string) on `react_message` nodes.

- [ ] **Step 1: Add the node def**

Import an icon near the other lucide imports at the top of the file — use `SmilePlus` (add to the existing `lucide-react` import list).

In `NODE_DEFS`, in the ENGAGE section after `cta_button`:

```jsx
    react_message:       { labelKey: 'automation.node_react_message',       color: '#f59e0b', bg: '#fffbeb', icon: SmilePlus,         category: 'engage',       qr: true },
```

- [ ] **Step 2: Add the fields component + register it**

Near `PollFields`, add:

```jsx
const REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏', '🔥', '🎉', '✅'];

function ReactMessageFields({ d, set }) {
    const { t } = useTranslation();
    return (
        <>
            <Field label={t('automation.field_reaction_emoji')}>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                    {REACTION_EMOJIS.map(e => (
                        <button
                            key={e}
                            type="button"
                            onClick={() => set('emoji', e)}
                            style={{
                                fontSize: 18, lineHeight: 1, padding: '6px 8px', borderRadius: 8,
                                border: d.emoji === e ? '2px solid #f59e0b' : '1px solid #e5e7eb',
                                background: d.emoji === e ? '#fffbeb' : '#fff', cursor: 'pointer',
                            }}
                        >
                            {e}
                        </button>
                    ))}
                </div>
            </Field>
            <Field label={t('automation.field_reaction_custom')}>
                <input className={inputCls} maxLength={8} value={d.emoji ?? ''} onChange={e => set('emoji', e.target.value)} placeholder="👍" />
            </Field>
            <p style={{ fontSize: 10, color: '#64748b' }}>{t('automation.react_message_hint')}</p>
        </>
    );
}
```

In `FIELD_COMPONENTS`, after `cta_button: CtaButtonFields,`:

```jsx
    react_message: ReactMessageFields,
```

- [ ] **Step 3: Add locale keys**

```json
        "node_react_message": "React to Message",
        "field_reaction_emoji": "Reaction",
        "field_reaction_custom": "Or type an emoji",
        "react_message_hint": "Reacts to the message that triggered this automation. Only works when the trigger is an incoming message.",
```

- [ ] **Step 4: Build + lint + commit**

```bash
npm run build
npx eslint resources/js/Pages/Automation/Builder.jsx
git add resources/js/Pages/Automation/Builder.jsx resources/js/locales/en.json public/build
git commit -m "Automation builder: React to Message node"
```

---

### Task 12: `message.reaction` inbound → store + `reaction.received` trigger

**Files:**
- Modify: `app/Modules/WhatsappWeb/Services/WahaEventProcessor.php` (add `handleReaction`)
- Create: `app/Events/ReactionReceived.php`
- Modify: `app/Listeners/AutomationTriggerListener.php` (add `handleReactionReceived`)
- Modify: wherever listeners are registered (grep for `MessageReceived::class` in `app/Providers/` — mirror that registration)
- Modify: `app/Modules/Automation/Services/WorkflowGenerator.php` (`TRIGGER_TYPES`)
- Test: `tests/Feature/WhatsappWeb/ReactionInboundTest.php` (create)

**Interfaces:**
- Consumes: `WebhookIdempotencyService`, existing inbound `Message` rows
- Produces:
  - `ReactionReceived` event: `public function __construct(public Message $message, public string $emoji)` (the `$message` is the message that was reacted to)
  - `AutomationTriggerListener::handleReactionReceived(ReactionReceived $e)` fires trigger `reaction.received` with context `['reaction_emoji' => $e->emoji, 'message_id' => $e->message->id]`
  - `WahaEventProcessor::process()` routes `message.reaction` → `handleReaction()`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WhatsappWeb/ReactionInboundTest.php`. Setup: `whatsapp_web` session + an inbound `Message` (`provider_message_id: 'orig_1'`) + an `Automation` `trigger_type: 'reaction.received'`, `status: 'active'`, one `send_whatsapp` node. Fire:

```php
$payload = [
    'event' => 'message.reaction',
    'session' => $session->session_name,
    'payload' => [
        'id' => 'rx_1',
        'reaction' => ['text' => '❤️', 'messageId' => 'orig_1'],
        'from' => '15551230000@c.us',
    ],
];
app(WahaEventProcessor::class)->process($payload, $session);
```

Assert: an `AutomationRun` was created for that automation + contact; and (second test) `process()` twice = one run (idempotency).

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/WhatsappWeb/ReactionInboundTest.php`
Expected: FAIL — `message.reaction` hits `default`

- [ ] **Step 3: Create the event**

`app/Events/ReactionReceived.php`:

```php
<?php

namespace App\Events;

use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReactionReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message, public string $emoji) {}
}
```

- [ ] **Step 4: `handleReaction` in `WahaEventProcessor`**

Match arm:

```php
            $event === 'message.reaction' => $this->handleReaction($payload),
```

Method:

```php
    /** @param array<string,mixed> $payload */
    private function handleReaction(array $payload): void
    {
        $p = $payload['payload'] ?? [];
        $emoji = (string) ($p['reaction']['text'] ?? $p['reaction']['emoji'] ?? $p['text'] ?? '');
        $targetProviderId = (string) ($p['reaction']['messageId'] ?? $p['reaction']['id'] ?? '');
        $rxId = (string) ($p['id'] ?? ($targetProviderId.':'.$emoji));

        if ($targetProviderId === '') {
            return;
        }

        $key = 'reaction:'.$rxId;
        if (! app(\App\Modules\Whatsapp\Services\WebhookIdempotencyService::class)->isNewEvent('whatsapp_web', $key)) {
            return;
        }

        $message = \App\Modules\Shared\Models\Message::where('provider_message_id', $targetProviderId)->first();
        if (! $message) {
            return;
        }

        // Store the reaction on the message (column added below) and fire the trigger.
        $message->update(['reaction_emoji' => $emoji ?: null]);
        \App\Events\ReactionReceived::dispatch($message, $emoji);
    }
```

> **`reaction_emoji` column:** check whether `messages` already has a place for an inbound reaction (grep the `create_conversations_messages_table` migration + any later `add_*_to_messages` migrations for `reaction`). If NOT present, add a tiny migration in this task: `$table->string('reaction_emoji', 16)->nullable()->after('body');` with a reversible `down()`. Add `reaction_emoji` to `Message::$fillable`.

- [ ] **Step 5: `handleReactionReceived` in `AutomationTriggerListener`**

Mirror `handleMessageReceived`:

```php
    public function handleReactionReceived(\App\Events\ReactionReceived $event): void
    {
        $contactId = $event->message->conversation?->contact_id;
        $workspaceId = $event->message->conversation?->workspace_id;
        if (! $contactId || ! $workspaceId) {
            return;
        }

        $this->fireWithConfig('reaction.received', $workspaceId, $contactId, [
            'message_id' => $event->message->id,
            'reaction_emoji' => $event->emoji,
        ], $event->emoji);
    }
```

Register it wherever `handleMessageReceived` is wired (grep `handleMessageReceived` in `app/Providers/`).

- [ ] **Step 6: `TRIGGER_TYPES`**

In `WorkflowGenerator.php`, add `'reaction.received'` to the `TRIGGER_TYPES` array.

- [ ] **Step 7: Run to verify it passes**

Run: `php artisan test tests/Feature/WhatsappWeb/ReactionInboundTest.php`
Expected: PASS

- [ ] **Step 8: Regression**

Run: `php artisan test tests/Feature/WhatsappWeb tests/Feature/Automation`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "WAHA: inbound reactions stored + reaction.received automation trigger"
```

---

### Task 13: Inbox — hover-react + inbound reaction badge + endpoint

**Files:**
- Modify: `app/Modules/Inbox/Http/Controllers/InboxController.php` (`react()` action)
- Modify: the inbox route file (grep `InboxController` in `app/Modules/Inbox/` `routes` + `bootstrap/app.php` — the client inbox routes are under `routes/client.php` prefix `app`, or a module route file; find and match)
- Modify: inbox thread component (grep `resources/js/Pages/Inbox` for the message-row / bubble component)
- Modify: `resources/js/locales/en.json`
- Test: `tests/Feature/Inbox/InboxReactionEndpointTest.php` (create)
- Build: `npm run build`

**Interfaces:**
- Consumes: the `reaction` send path (Task 9), `Message` model
- Produces: `POST /app/inbox/conversations/{conversation}/messages/{message}/react` body `{emoji: string}` → creates an outbound `Message {type: 'reaction', payload: {target_message_id: {message}->id, emoji}}`, sends it, returns `{ok: true}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Inbox/InboxReactionEndpointTest.php`. Auth as a client user in a workspace with a `whatsapp_web` conversation + an inbound `Message` (`provider_message_id: 'in_9'`). `Http::fake` WAHA. POST the react route with `{emoji: '👍'}`. Assert 200, a `reaction` Message row exists, and `/api/reaction` was PUT.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Inbox/InboxReactionEndpointTest.php`
Expected: FAIL — route not defined (404)

- [ ] **Step 3: Add the route**

Find where inbox conversation routes live (grep `conversations/{conversation}` in route files). Add alongside them:

```php
Route::post('inbox/conversations/{conversation}/messages/{message}/react', [InboxController::class, 'react'])
    ->name('inbox.messages.react');
```

Match the existing inbox route group's middleware and prefix exactly.

- [ ] **Step 4: Add `InboxController::react`**

```php
    public function react(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $this->authorize('view', $conversation); // or match the guard the other inbox actions use

        $validated = $request->validate(['emoji' => ['required', 'string', 'max:8']]);

        $out = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => $conversation->channelAccount?->channel ?? 'whatsapp',
            'type' => 'reaction',
            'body' => null,
            'payload' => ['target_message_id' => $message->id, 'emoji' => $validated['emoji']],
            'status' => 'queued',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        try {
            $id = app(\App\Modules\Messaging\Services\ChannelManager::class)->driver($out->channel)->send($out);
            $out->update(['status' => 'sent', 'provider_message_id' => $id ?: null]);
        } catch (\Throwable $e) {
            $out->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }
```

> Confirm: the ChannelManager FQCN (grep `channelManager->driver` in `AutomationEngine` — it is injected there; use the same class), the authorization pattern used by sibling inbox actions, and the `Message` import.

- [ ] **Step 5: Frontend — hover react button + badge**

In the inbox message-row component:
- On hover, show a small "🙂+" button that opens a 9-emoji popover (reuse `REACTION_EMOJIS` list — or inline it). On pick: `axios.post(route('...inbox.messages.react', [conversationId, messageId]), { emoji })`, optimistic.
- Render `message.reaction_emoji` (when present) as a small badge overlapping the bubble's bottom corner.

> The exact component and how it receives `conversationId` are found by reading the inbox page during this task. Follow the file's existing axios + optimistic-update conventions.

- [ ] **Step 6: Locale keys**

```json
        "react": "React",
        "reaction_sent": "Reaction sent",
```

- [ ] **Step 7: Run test + build + lint**

```bash
php artisan test tests/Feature/Inbox/InboxReactionEndpointTest.php
npm run build
```

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Inbox: hover-react on messages + inbound reaction badge"
```

---

### Task 14: AI Reply / Chatbot can react

**Files:**
- Modify: `app/Modules/Automation/Services/AutomationEngine.php` (`executeAiReply`, `executeRunChatbot` — the output handling)
- Test: `tests/Feature/Automation/AiReactTest.php` (create)

**Interfaces:**
- Consumes: the `reaction` send path, `context['message_id']`
- Produces: when the AI/chatbot returns a JSON object `{"action":"react","emoji":"..."}` (possibly wrapped in prose / fences), the node emits a `reaction` targeting `context['message_id']` instead of a text reply. Any other output → unchanged text-reply behaviour.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Automation/AiReactTest.php`. Fake the LLM gateway (grep how other AI tests fake it — likely `LlmGateway` bound in the container or `Http::fake` on the provider). Make it return `{"action":"react","emoji":"🙏"}`. Run an automation `[trigger -> ai_reply]` with `context: ['message_id' => $inbound->id, 'message_body' => 'thank you!']` on a `whatsapp_web` conversation. Assert `/api/reaction` PUT with `reaction: '🙏'` and NO `/api/sendText`.

Second test: LLM returns plain text `"You're welcome!"` → `/api/sendText` with that body, no `/api/reaction`.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Automation/AiReactTest.php`
Expected: FAIL — first test sends the raw JSON as text

- [ ] **Step 3: Parse the react form**

In `executeAiReply` (and `executeRunChatbot`), after obtaining the model's reply string `$reply` and before sending it as text, add:

```php
        $maybeReact = $this->parseAiReaction($reply);
        if ($maybeReact !== null && ! empty($context['message_id'])) {
            return $this->sendWhatsappPayload($run, 'reaction', null, [
                'target_message_id' => (int) $context['message_id'],
                'emoji' => $maybeReact,
            ]);
        }
```

Add the helper:

```php
    /** If the AI reply is a {"action":"react","emoji":"X"} object, return the emoji. */
    private function parseAiReaction(string $reply): ?string
    {
        if (! str_contains($reply, '"react"')) {
            return null;
        }
        if (preg_match('/\{[^{}]*"action"\s*:\s*"react"[^{}]*\}/', $reply, $m)) {
            $obj = json_decode($m[0], true);
            $emoji = is_array($obj) ? trim((string) ($obj['emoji'] ?? '')) : '';
            return $emoji !== '' ? $emoji : null;
        }
        return null;
    }
```

- [ ] **Step 4: Add one line to the AI system prompt**

Find where the AI-reply / chatbot system prompt is assembled (grep `systemPrompt` / `system_prompt` in the AI module + `executeAiReply`). Append:

```
If a short emoji reaction is a better response than words (e.g. the customer just said thanks), reply with exactly: {"action":"react","emoji":"👍"} and nothing else.
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Feature/Automation/AiReactTest.php`
Expected: PASS

- [ ] **Step 6: Regression**

Run: `php artisan test tests/Feature/Automation`
Expected: PASS (existing AI-reply tests unaffected — plain text still works)

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Automation/Services/AutomationEngine.php tests/Feature/Automation/AiReactTest.php
git commit -m "Automation: AI Reply / Chatbot may respond with an emoji reaction"
```

---

## Phase 4 — Calls

### Task 15: `call.*` inbound → log + reject + trigger

**Files:**
- Modify: `app/Modules/WhatsappWeb/Services/WahaEventProcessor.php` (add `handleCall`)
- Create: `app/Events/CallReceived.php`
- Modify: `app/Listeners/AutomationTriggerListener.php` (add `handleCallReceived`)
- Modify: listener registration provider
- Modify: `app/Modules/Automation/Services/WorkflowGenerator.php` (`TRIGGER_TYPES`)
- Test: `tests/Feature/WhatsappWeb/CallHandlingTest.php` (create)

**Interfaces:**
- Consumes: `EngineAdapter::rejectCall()` (Task 2), `WhatsappWebSession` settings (Task 4), `WebhookIdempotencyService`, `WhatsappDriver` (for the auto-reply text + the system log message), contact/conversation resolution
- Produces:
  - `CallReceived` event: `__construct(public int $workspaceId, public int $contactId, public string $callId, public string $callType)` (`callType`: `audio`|`video`)
  - `WahaEventProcessor::process()` routes `call.received`, `call.accepted`, `call.rejected` → `handleCall($payload, $session, $event)`
  - `handleCall`: always writes a `Message {type: 'system', body: '📞 ...'}` into the caller's WhatsApp conversation; on `call.received` with `session.auto_reject_calls` → `adapter->rejectCall()` + (if `call_reject_message`) a text send; always dispatches `CallReceived` on `call.received`.
  - `AutomationTriggerListener::handleCallReceived` fires trigger `call.received` with context `['call_id' => ..., 'call_type' => ..., 'caller_phone' => ...]`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WhatsappWeb/CallHandlingTest.php`. Setup: `whatsapp_web` session (start with defaults — `auto_reject_calls: false`). A helper to fire a call event:

```php
private function fireCall(string $event = 'call.received', array $extra = []): void
{
    app(WahaEventProcessor::class)->process(array_merge([
        'event' => $event,
        'session' => $this->session->session_name,
        'payload' => array_merge([
            'id' => 'call_1',
            'from' => '15551230000@c.us',
            'isVideo' => false,
        ], $extra),
    ], []), $this->session);
}
```

Tests:
1. `call.received` with `auto_reject_calls: false` → a `system` Message `"📞 Missed call"` exists in the contact's conversation; NO `/api/*/calls/reject` call; a `CallReceived` event was dispatched (use `Event::fake([CallReceived::class])` + assert, but then you can't also test the trigger — so split: one test fakes the event, another lets it run and asserts an `AutomationRun`).
2. `auto_reject_calls: true`, `call_reject_message: 'Please message us instead'` → `/api/ws-x/calls/reject` PUT/POST with `callId: 'call_1'` AND `/api/sendText` with that message.
3. Firing the same `call.received` twice → one system message (idempotency on `call:call_1:call.received`).
4. `call.received` with a `call.received` automation trigger present → an `AutomationRun` is created with `context['call_id'] === 'call_1'`.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/WhatsappWeb/CallHandlingTest.php`
Expected: FAIL — `call.*` hits `default`

- [ ] **Step 3: Create `CallReceived` event**

`app/Events/CallReceived.php`:

```php
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $workspaceId,
        public int $contactId,
        public string $callId,
        public string $callType,
        public string $callerPhone,
    ) {}
}
```

- [ ] **Step 4: `handleCall` in `WahaEventProcessor`**

Match arms:

```php
            $event === 'call.received' || $event === 'call.accepted' || $event === 'call.rejected'
                => $this->handleCall($payload, $session, $event),
```

Method — resolve the contact via a small reuse of the normalizer's phone logic or a direct `Contact::firstOrCreate` on the workspace + phone; write the system message through a new `WhatsappDriver` helper or directly as a `Message` row; reject + reply only on `call.received` + toggle; dispatch `CallReceived` only on `call.received`. Use idempotency key `'call:'.$callId.':'.$event`.

```php
    /** @param array<string,mixed> $payload */
    private function handleCall(array $payload, WhatsappWebSession $session, string $event): void
    {
        $p = $payload['payload'] ?? [];
        $callId = (string) ($p['id'] ?? '');
        $fromJid = (string) ($p['from'] ?? $p['peerJid'] ?? '');
        $phone = preg_replace('/\D+/', '', explode('@', $fromJid)[0] ?? '');
        if ($callId === '' || $phone === '') {
            return;
        }

        $key = 'call:'.$callId.':'.$event;
        if (! app(\App\Modules\Whatsapp\Services\WebhookIdempotencyService::class)->isNewEvent('whatsapp_web', $key)) {
            return;
        }

        $callType = ($p['isVideo'] ?? false) ? 'video' : 'audio';

        $contact = \App\Modules\Shared\Models\Contact::firstOrCreate(
            ['workspace_id' => $session->workspace_id, 'phone_e164' => '+'.$phone],
        );
        $account = \App\Modules\Shared\Models\ChannelAccount::where('workspace_id', $session->workspace_id)
            ->where('channel', 'whatsapp')->where('phone_number_id', $session->session_name)->first();
        $conversation = \App\Modules\Shared\Models\Conversation::firstOrCreate(
            ['workspace_id' => $session->workspace_id, 'contact_id' => $contact->id, 'channel_account_id' => $account?->id],
            ['status' => 'open'],
        );

        $label = match ($event) {
            'call.received' => '📞 Missed call',
            'call.rejected' => '📞 Call rejected',
            'call.accepted' => '📞 Call answered',
            default => '📞 Call',
        };
        \App\Modules\Shared\Models\Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'system',
            'body' => $label.' ('.$callType.')',
            'status' => 'received',
            'sent_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);

        if ($event !== 'call.received') {
            return;
        }

        if ($session->auto_reject_calls) {
            try {
                $this->engines->adapter()->rejectCall($session->session_name, $callId);
            } catch (\Throwable $e) {
                Log::warning('whatsapp_web.call.reject_failed', ['call' => $callId, 'error' => $e->getMessage()]);
            }

            if (trim((string) $session->call_reject_message) !== '') {
                $out = \App\Modules\Shared\Models\Message::create([
                    'conversation_id' => $conversation->id,
                    'direction' => 'out', 'channel' => 'whatsapp', 'type' => 'text',
                    'body' => $session->call_reject_message, 'status' => 'queued',
                    'sent_by' => 'system', 'sent_at' => now(),
                ]);
                try {
                    $this->driver->send($out);
                    $out->update(['status' => 'sent']);
                } catch (\Throwable $e) {
                    $out->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);
                }
            }
        }

        \App\Events\CallReceived::dispatch($session->workspace_id, $contact->id, $callId, $callType, '+'.$phone);
    }
```

> `$this->engines` — check `WahaEventProcessor`'s constructor; it currently injects `InboundNormalizer`, `WhatsappDriver`, `SessionProvisioner`. Add `EngineManager $engines`. `InboundNormalizer` already has `EngineManager` — follow that.
> `type: 'system'` — verify `messages.type` allows it (grep `$allowedTypes` in `WhatsappDriver` — it lists `'unsupported'` etc.; if `system` isn't allowed anywhere it's fine for a directly-created row, but check any enum/validation on the column). If there's a DB enum, use an allowed value like `'unsupported'` with the label in `body`, or add `system` to the enum in the Task-12 migration.

- [ ] **Step 5: `handleCallReceived` + registration + `TRIGGER_TYPES`**

```php
    public function handleCallReceived(\App\Events\CallReceived $event): void
    {
        $this->fireWithConfig('call.received', $event->workspaceId, $event->contactId, [
            'call_id' => $event->callId,
            'call_type' => $event->callType,
            'caller_phone' => $event->callerPhone,
        ], null);
    }
```

Register it; add `'call.received'` to `WorkflowGenerator::TRIGGER_TYPES`.

- [ ] **Step 6: Run to verify it passes**

Run: `php artisan test tests/Feature/WhatsappWeb/CallHandlingTest.php`
Expected: PASS

- [ ] **Step 7: Regression**

Run: `php artisan test tests/Feature/WhatsappWeb tests/Feature/Automation`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "WAHA: call handling — log, auto-reject toggle, auto-reply, call.received trigger"
```

---

### Task 16: Call trigger — frontend

**Files:**
- Modify: `resources/js/Pages/Automation/Builder.jsx` (`TRIGGER_TYPES` array near line 40, plus any trigger config panel)
- Modify: `resources/js/locales/en.json`
- Build: `npm run build`

**Interfaces:**
- Produces: `call.received` and `reaction.received` selectable as automation triggers, with labels + icons.

- [ ] **Step 1: Add the trigger entries**

In `Builder.jsx`'s trigger list (the array around line 40 with `{ value, labelKey, Icon }`), add:

```jsx
    { value: 'message.reaction' /* keep in sync with backend 'reaction.received' */ , labelKey: 'automation.trigger_reaction_received', Icon: SmilePlus },
    { value: 'call.received',      labelKey: 'automation.trigger_call_received',     Icon: Phone },
```

> **Important:** the backend trigger string is `reaction.received` — use that exact value, not `message.reaction`. Double-check against `WorkflowGenerator::TRIGGER_TYPES` and `fireWithConfig` calls from Tasks 12 & 15.

Corrected:

```jsx
    { value: 'reaction.received', labelKey: 'automation.trigger_reaction_received', Icon: SmilePlus },
    { value: 'call.received',     labelKey: 'automation.trigger_call_received',     Icon: Phone },
```

- [ ] **Step 2: Locale keys**

```json
        "trigger_reaction_received": "Reaction Received",
        "trigger_call_received": "Call Received",
```

- [ ] **Step 3: Build + lint + commit**

```bash
npm run build
npx eslint resources/js/Pages/Automation/Builder.jsx
git add resources/js/Pages/Automation/Builder.jsx resources/js/locales/en.json public/build
git commit -m "Automation builder: Reaction Received + Call Received triggers"
```

---

### Task 17: Call settings — backend endpoint

**Files:**
- Modify: `app/Modules/WhatsappWeb/Http/Controllers/WhatsappWebSessionController.php`
- Test: `tests/Feature/WhatsappWeb/CallHandlingTest.php` (add an endpoint test) or a new `SettingsUpdateTest.php`

**Interfaces:**
- Consumes: `WhatsappWebSession` (Task 4 columns)
- Produces: an endpoint (extend the existing session controller — find its update/settings action or add one) accepting `{auto_reject_calls?: bool, call_reject_message?: string|null, send_receipts?: bool}` and persisting to the workspace's session.

- [ ] **Step 1: Write the failing test**

In a test, auth as the workspace's client user, POST/PUT the settings route with `{auto_reject_calls: true, call_reject_message: 'x', send_receipts: false}`, assert the `WhatsappWebSession` row updated.

- [ ] **Step 2: Run to verify it fails**

Expected: FAIL — route/handler missing

- [ ] **Step 3: Implement**

Read `WhatsappWebSessionController.php` fully. If it has a `show`/`status` pattern and route group, add a sibling:

```php
    public function updateSettings(Request $request): JsonResponse
    {
        $session = app(SessionProvisioner::class)->ensure($this->workspaceId($request));

        $validated = $request->validate([
            'auto_reject_calls' => ['sometimes', 'boolean'],
            'call_reject_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'send_receipts' => ['sometimes', 'boolean'],
        ]);

        $session->update($validated);

        return response()->json(['ok' => true, 'settings' => $session->only([
            'auto_reject_calls', 'call_reject_message', 'send_receipts',
        ])]);
    }
```

Register the route in the same group as the controller's other routes (grep its route registration — likely `app/Modules/WhatsappWeb/routes/web.php`).

- [ ] **Step 4: Run to verify it passes** — Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "WhatsApp Web: settings endpoint for call handling + receipts"
```

---

### Task 18: Call settings — frontend card

**Files:**
- Modify: the WhatsApp Web number setup/detail page (grep `client/Whatsapp` / `WhatsappWeb` / `qr` in `resources/js/Pages`)
- Modify: `resources/js/locales/en.json`
- Build: `npm run build`

**Interfaces:**
- Consumes: the settings endpoint (Task 17)
- Produces: a "Calls" card — a toggle `auto_reject_calls`, a conditional textarea `call_reject_message`, and a toggle `send_receipts` (labelled "Send read receipts & typing indicators"). Saves via the Task 17 route.

- [ ] **Step 1: Locate the page**

Run: `grep -rl "whatsapp-web\|WhatsApp Web\|QR code" resources/js/Pages` — open the setup/status page that shows the QR + connected number.

- [ ] **Step 2: Add the card**

Below the connection status, when the number is connected, render a settings card. Follow the page's existing form/save conventions (Inertia `useForm` or axios). Pass current settings in as props (extend the controller `show`/render in Task 17 or the page's existing controller to include `session.only([...])`).

- [ ] **Step 3: Locale keys**

```json
        "calls_card_title": "Calls & receipts",
        "auto_reject_calls_label": "Auto-reject incoming calls",
        "call_reject_message_label": "Reply after rejecting (optional)",
        "call_reject_message_placeholder": "Sorry, we can't take calls here — please send us a message and we'll help.",
        "send_receipts_label": "Send read receipts & typing indicators",
        "send_receipts_help": "Contacts see blue ticks and a \"typing…\" indicator when automations reply.",
```

- [ ] **Step 4: Build + lint + commit**

```bash
npm run build
git add -A
git commit -m "WhatsApp Web setup: Calls & receipts settings card"
```

---

## Phase 5 — Read receipts & typing

### Task 19: Typing indicator around WAHA sends

**Files:**
- Modify: `app/Modules/Whatsapp/Services/WhatsappDriver.php` (`sendViaWhatsappWeb`)
- Test: `tests/Feature/WhatsappWeb/ReceiptsTest.php` (extend)

**Interfaces:**
- Consumes: `EngineAdapter::sendTyping()`, `WhatsappWebSession.send_receipts`
- Produces: when `send_receipts` is on for the conversation's session, `sendViaWhatsappWeb` calls `sendTyping(on)` before the real send and `sendTyping(off)` after, best-effort (a typing failure never breaks the send). No artificial sleep in the test environment.

- [ ] **Step 1: Write the failing test**

In `ReceiptsTest.php` add: a `whatsapp_web` conversation whose session has `send_receipts: true`. `Http::fake` all WAHA. Send a `text` Message via `WhatsappDriver`. Assert `/api/startTyping` AND `/api/stopTyping` AND `/api/sendText` were all sent.

Second test: session `send_receipts: false` → only `/api/sendText`, no typing calls.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/WhatsappWeb/ReceiptsTest.php`
Expected: FAIL — no typing calls made

- [ ] **Step 3: Wrap the send**

In `sendViaWhatsappWeb`, resolve the session and wrap:

```php
        $webSession = \App\Modules\WhatsappWeb\Models\WhatsappWebSession::where('session_name', $session)->first();
        $receipts = (bool) ($webSession?->send_receipts ?? false);

        if ($receipts) {
            try { $adapter->sendTyping($session, $phone, true); } catch (\Throwable $e) {}
            if (! app()->runningUnitTests()) {
                usleep(900_000); // ~0.9s so the indicator is visible
            }
        }

        try {
            $result = match ($message->type) {
                // ... existing arms ...
            };
        } finally {
            if ($receipts) {
                try { $adapter->sendTyping($session, $phone, false); } catch (\Throwable $e) {}
            }
        }

        return $result;
```

> Restructure the existing `return match (...)` into `$result = match (...)` + `return $result` so the `finally` can run. Keep every existing arm byte-for-byte.

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/WhatsappWeb/ReceiptsTest.php`
Expected: PASS

- [ ] **Step 5: Regression**

Run: `php artisan test tests/Feature/WhatsappWeb`
Expected: PASS (OutboundSendTest, TemplateDegradeTest, PollSendTest, ReactionSendTest all still green — they don't set `send_receipts`, which defaults... **note:** `WhatsappWebSession` default is `true`. Those tests may not create a `WhatsappWebSession` row at all — then `$webSession` is null and `$receipts` is false, so they stay green. Confirm each fixture; if any creates a session row, they'll now also emit typing calls — update those assertions to `assertSent` the real endpoint without asserting typing is absent.)

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Whatsapp/Services/WhatsappDriver.php tests/Feature/WhatsappWeb/ReceiptsTest.php
git commit -m "WhatsappDriver: typing indicator around WAHA sends when send_receipts is on"
```

---

### Task 20: `sendSeen` on agent open + automation/chatbot consume

**Files:**
- Modify: `app/Modules/Inbox/Http/Controllers/InboxController.php` (the "open conversation" / "mark read" action)
- Modify: `app/Modules/Automation/Services/AutomationEngine.php` (`executeAiReply`, `executeRunChatbot` — after reading the inbound)
- Test: `tests/Feature/WhatsappWeb/ReceiptsTest.php` (extend)

**Interfaces:**
- Consumes: `EngineAdapter::sendSeen()`, `send_receipts`
- Produces:
  - Opening a WAHA conversation with unread inbound (via the existing inbox mark-read path) calls `sendSeen(session, chatId, lastInboundProviderId)` when `send_receipts` on.
  - `executeAiReply`/`executeRunChatbot` on a WAHA conversation call `sendSeen` for the inbound they are responding to, when `send_receipts` on.

- [ ] **Step 1: Write the failing tests**

In `ReceiptsTest.php`:
- Test A: auth as client user, hit the "mark conversation read" endpoint for a WAHA conversation with an unread inbound (`provider_message_id: 'in_5'`), `send_receipts: true` → `/api/sendSeen` sent with `chatId` of the contact and `messageId: 'in_5'`.
- Test B: run an `ai_reply` automation on a WAHA conversation, `send_receipts: true`, faked LLM → `/api/sendSeen` sent before `/api/sendText`.
- Test C: same as B but `send_receipts: false` → no `/api/sendSeen`.

- [ ] **Step 2: Run to verify they fail** — Expected: FAIL

- [ ] **Step 3: Inbox — sendSeen on open**

Find the inbox action that clears `unread_count` / marks messages read (grep `unread_count` / `markRead` / `read` in `InboxController`). After it marks read, if the conversation's `channelAccount->provider === 'whatsapp_web'` and the session's `send_receipts`:

```php
        if ($conversation->channelAccount?->provider === 'whatsapp_web') {
            $ws = \App\Modules\WhatsappWeb\Models\WhatsappWebSession::where('session_name', $conversation->channelAccount->phone_number_id)->first();
            if ($ws?->send_receipts) {
                $lastIn = $conversation->messages()->where('direction', 'in')->latest('id')->value('provider_message_id');
                try {
                    app(\App\Modules\WhatsappWeb\Services\EngineManager::class)->adapter()->sendSeen(
                        $ws->session_name,
                        preg_replace('/\D+/', '', $conversation->contact->phone_e164).'@c.us',
                        $lastIn,
                    );
                } catch (\Throwable $e) {}
            }
        }
```

- [ ] **Step 4: Automation — sendSeen before AI/chatbot reply**

In `executeAiReply` and `executeRunChatbot`, after resolving the inbound message being answered (they already load `$inbound` when `message_id` is set) and before generating the reply, add a small private helper call:

```php
        $this->markInboundSeenIfPersonal($run, $context);
```

```php
    private function markInboundSeenIfPersonal(AutomationRun $run, array $context): void
    {
        $contact = Contact::find($run->contact_id);
        if (! $contact) {
            return;
        }
        $target = $this->resolveChannelTarget($run->automation->workspace_id, $contact, 'whatsapp');
        $account = $target['account'] ?? null;
        if ($account?->provider !== 'whatsapp_web') {
            return;
        }
        $ws = \App\Modules\WhatsappWeb\Models\WhatsappWebSession::where('session_name', $account->phone_number_id)->first();
        if (! $ws?->send_receipts) {
            return;
        }
        $providerId = ! empty($context['message_id'])
            ? (string) (Message::whereKey($context['message_id'])->value('provider_message_id') ?? '')
            : '';
        try {
            app(\App\Modules\WhatsappWeb\Services\EngineManager::class)->adapter()->sendSeen(
                $ws->session_name,
                preg_replace('/\D+/', '', (string) $contact->phone_e164).'@c.us',
                $providerId ?: null,
            );
        } catch (\Throwable $e) {
            Log::warning('automation.send_seen_failed', ['run' => $run->id, 'error' => $e->getMessage()]);
        }
    }
```

- [ ] **Step 5: Run to verify they pass** — Expected: PASS

- [ ] **Step 6: Full regression**

Run: `php artisan test tests/Feature/WhatsappWeb tests/Feature/Automation tests/Feature/Inbox`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "WAHA: send read receipts on agent open and before AI/chatbot replies"
```

---

## Phase 6 — Ship

### Task 21: Full suite + deploy

- [ ] **Step 1: Run the entire test suite**

Run: `php artisan test`
Expected: PASS (green). Fix any regression before proceeding.

- [ ] **Step 2: Final build**

Run: `npm run build`
Then: `git status` — if `public/build/` changed, `git add public/build && git commit -m "build: WAHA expansion assets"`

- [ ] **Step 3: Push**

```bash
git push origin main
```

- [ ] **Step 4: Deploy**

```bash
curl -sS "https://wa2.dermavalue.pk/deploy.php?key=esystematicTechnologies&action=deploy"
```

Expected: `"status": "SUCCESS"`, `migrate` shows the new migration ran.

- [ ] **Step 5: Verify on live**

```bash
curl -sS "https://wa2.dermavalue.pk/deploy.php?key=esystematicTechnologies&action=git-info"
```

Expected: `commits_behind: 0`.

- [ ] **Step 6: Update memory**

Append to `MEMORY.md` a line pointing at a new memory `waha-expansion.md` recording: what shipped (poll/reactions/calls/receipts), the `send_receipts` per-number default-on setting, and the new trigger types `reaction.received` / `call.received`.

---

## Self-Review

**1. Spec coverage**

| Spec section | Task(s) |
|---|---|
| §1 adapter methods | Task 2 |
| §1 send path (poll/reaction arms, Cloud reaction) | Tasks 5, 9 |
| §2 executeSendPoll branch | Task 6 |
| §2 poll node config (multiple, result var) | Task 8 |
| §2 poll.vote → context + resume | Task 7 |
| §2 send_poll QR badge | Task 8 |
| §3a react_message node | Tasks 10, 11 |
| §3b inbox hover-react + endpoint | Task 13 |
| §3c inbound reaction display | Tasks 12 (store), 13 (badge) |
| §3d reaction.received trigger | Tasks 12, 16 |
| §3e AI can react | Task 14 |
| §4 settings columns | Task 4 |
| §4 settings UI | Tasks 17, 18 |
| §4 call.* subscribe + process | Tasks 3, 15 |
| §4 call log always / reject on toggle / trigger always | Task 15 |
| §4 call.received trigger | Tasks 15, 16 |
| §5 typing before sends | Task 19 |
| §5 sendSeen on agent open | Task 20 |
| §5 sendSeen on automation/chatbot consume | Task 20 |
| §6 migration | Task 4 |
| §6 webhook subscription list | Task 3 |
| §6 process() match arms | Tasks 7, 12, 15 |
| §6 idempotency keys | Tasks 7, 12, 15 |
| §6 tests | every task |

No gaps.

**2. Placeholder scan**

Remaining "found during implementation" markers are all *file-location* discoveries in an
unfamiliar module (inbox thread component, listener-registration provider, WA-Web setup page,
inbox route group), each with an exact `grep` command to run. No logic is deferred. Acceptable
per the skill's "existing codebase — explore before proposing" guidance.

**3. Type consistency**

- `EngineAdapter` signatures in Task 2 == calls in Tasks 5, 9, 15, 19, 20. ✓
- `Message` payload shapes: `poll` = `{question, options, multiple}` (Tasks 5, 6, 8); `reaction`
  = `{target_message_id, emoji}` (Tasks 9, 10, 13, 14). Consistent. ✓
- `WhatsappWebSession` columns `auto_reject_calls` / `call_reject_message` / `send_receipts`
  identical across Tasks 4, 15, 17, 18, 19, 20. ✓
- Events: `ReactionReceived(Message $message, string $emoji)` (Task 12) used in Task 12's
  listener; `CallReceived(int $workspaceId, int $contactId, string $callId, string $callType,
  string $callerPhone)` (Task 15) used in Task 15's listener. ✓
- Trigger strings: `reaction.received` and `call.received` — Task 16 Step 1 explicitly corrects
  a wrong-value trap and points back to Tasks 12/15. ✓
- Context keys: `_poll_result_var` (Task 6) read in Task 7; `message_id` (existing) read in
  Tasks 10, 14, 20. ✓

**4. Scope**

Five phases, each independently testable and shippable. Phase boundaries match the spec's
feature boundaries. Phase 1 is a hard prerequisite for 2–5; 2/3/4/5 are mutually independent
after Phase 1 and could even be separate PRs.

---

## Execution Handoff

Plan complete and saved to `WAHA-EXPANSION-PLAN.md`. Two execution options:

1. **Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session with checkpoints for review.

Which approach?
