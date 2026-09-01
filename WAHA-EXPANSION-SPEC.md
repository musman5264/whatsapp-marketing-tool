# WAHA expansion — design spec

Status: approved by user 2026-09-01. Implements items #1–#4 from `docs/waha-api-coverage.md`'s
"Build next" list, at maximum scope (user approved all sections in one pass).

Branch point: `main` @ `604993a`.

---

## 1. Adapter interface & the send path

`EngineAdapter` (`app/Modules/WhatsappWeb/Contracts/EngineAdapter.php`) gains four methods.
Only `WahaAdapter` implements this interface, so adding methods is non-breaking.

```php
/** Send a native poll. Returns the engine message id. */
public function sendPoll(string $session, string $toE164, string $question, array $options, bool $multipleAnswers): string;

/** React to a message with an emoji, or remove a reaction when $emoji === ''. */
public function sendReaction(string $session, string $messageId, string $emoji): void;

/** Mark a chat's messages as read (blue ticks), optionally up to one message id. */
public function sendSeen(string $session, string $chatId, ?string $messageId = null): void;

/** Toggle the typing indicator for a chat. */
public function sendTyping(string $session, string $toE164, bool $on): void;

/** Reject an incoming call by its WAHA call id. */
public function rejectCall(string $session, string $callId): void;
```

`WahaAdapter` implementations call:
- `sendPoll` → `POST /api/sendPoll {session, chatId, poll: {name, options, multipleAnswers}}`
- `sendReaction` → `PUT /api/reaction {session, messageId, reaction}` (`reaction: ''` removes)
- `sendSeen` → `POST /api/sendSeen {session, chatId, messageId?}`
- `sendTyping` → `POST /api/startTyping` / `POST /api/stopTyping` `{session, chatId}`
- `rejectCall` → `POST /api/{session}/calls/reject {callId}`

### Send path (outbound)

`WhatsappDriver::sendViaWhatsappWeb()` (personal-number branch) gets two new `match` arms:

- `'poll'` → `$adapter->sendPoll($session, $phone, $payload['poll']['question'], $payload['poll']['options'], (bool)($payload['poll']['multiple'] ?? false))`
- `'reaction'` → resolve `$targetMsg = Message::find($payload['target_message_id'])`, then
  `$adapter->sendReaction($session, $targetMsg->provider_message_id, $payload['emoji'])`.
  If the target has no `provider_message_id` yet (still sending), skip with a clear log — reactions
  need a delivered message id.

`WhatsappDriver::send()` (Cloud API branch) gets:
- `'reaction'` → new `CloudApiClient::sendReaction(string $to, string $messageId, string $emoji)`
  using Meta's native `type: reaction` message (`{message_id, emoji}`; `emoji: ''` removes).
- `'poll'` → **unchanged** — stays on the existing button/list emulation in
  `AutomationEngine::executeSendPoll` (Cloud has no native poll).

---

## 2. Automation: poll behaviour + context write-back

`AutomationEngine::executeSendPoll` branches on the resolved channel account's provider:

```php
if ($account?->provider === 'whatsapp_web') {
    return $this->sendWhatsappPayload($run, 'poll', $question, [
        'poll' => ['question' => $question, 'options' => $options, 'multiple' => (bool)($data['multiple'] ?? false)],
    ]);
}
// else: existing button/list emulation, unchanged
```

Poll node config (`Builder.jsx` `SendPollFields`) gains:
- a "multiple answers" checkbox (`d.multiple`)
- a "save answer to variable" text field, default `poll_answer` (`d.result_var`)

`send_poll` gets `qr: true` in `NODE_DEFS` (green badge) — poll is now native on WAHA.

### Inbound: `poll.vote`

New webhook event, subscribed in `startSession()`. `WahaEventProcessor::handlePollVote()`:
1. Extract `{pollMessageId, voter, selectedOptions}` from the payload.
2. Find the `Message` with `provider_message_id === pollMessageId` and its `AutomationRun`
   (via `automation_run_logs` linking node → run, same pattern used for `ask_question` resume).
3. Merge `{ '<result_var>' => implode(', ', $selectedOptions) }` into `run.context`.
4. If the run is parked (status `waiting`) on the poll node's next step, resume it — otherwise
   just persist the context update for later token use.

Idempotency key: `pollvote:<pollMessageId>:<voter>` via the existing `WebhookIdempotencyService`.

---

## 3. Reactions — four surfaces

### 3a. Automation node: `react_message`

New ENGAGE-category node (`qr: true`, also works on Cloud). Config: an emoji picker (small
curated set + free text fallback). Reacts to `context.message_id` (the trigger message).

`executeReactMessage(array $data, AutomationRun $run, array $context)`:
- Requires `context['message_id']` (skip with a clear message if absent — e.g. a non-message
  trigger).
- Emits `Message { type: 'reaction', payload: { target_message_id: context.message_id, emoji } }`
  through `dispatchMessage` → the send-path arms in Section 1.

### 3b. Inbox: hover-react on inbound messages

- New route `POST /app/inbox/conversations/{conversation}/messages/{message}/react`
  (`InboxController::react`), body `{ emoji }`.
- Creates an outbound `Message { type: 'reaction', ... }` the same way, sent through
  `WhatsappDriver`/`CloudApiClient`.
- Frontend: an emoji-picker button that appears on message-row hover in the conversation thread
  (`resources/js/Pages/Inbox/...` — exact component found during implementation), mirroring
  WhatsApp's own UI. Optimistic local render; reconciled on the message-sent broadcast.

### 3c. Inbound reaction display

Audit first whether `message.reaction`/Cloud reaction webhooks already render anything in the
thread. If not: show a small emoji badge pinned to the corner of the reacted message bubble,
sourced from the stored inbound reaction row/field.

### 3d. `reaction.received` automation trigger

- New `TRIGGER_TYPES` entry `reaction.received` (both `Builder.jsx` and
  `WorkflowGenerator::TRIGGER_TYPES`).
- `AutomationTriggerListener::handleReactionReceived()`: context `{ reaction_emoji, message_id }`.
- Fired from both engines: WAHA's `message.reaction` webhook and Meta's inbound reaction
  webhook (`MetaWebhookController`).

### 3e. AI Reply / Chatbot can react

- The AI/chatbot system prompt gains one documented output option:
  `{"action": "react", "emoji": "👍"}` alongside the normal text-reply JSON.
- `executeAiReply` / `executeRunChatbot` parse this form; when present, emit a `reaction`
  message (targeting `context.message_id`) instead of `text`.
- Falls back to a plain text reply if the model doesn't use the option — no behaviour change
  for existing bots/prompts.

---

## 4. Calls

### Settings (per WAHA number)

New columns on `whatsapp_web_sessions` (migration):
- `auto_reject_calls` boolean, default `false`
- `call_reject_message` text, nullable
- `send_receipts` boolean, default `true` (used by Section 5 too)

Settings UI: a "Calls" card on the WhatsApp Web number's setup/detail page — toggle +
conditional message textarea, saved via the existing session-update endpoint pattern.

### Webhook subscription & processing

`startSession()` subscribes `call.received`, `call.accepted`, `call.rejected` (previously
none). `WahaEventProcessor::handleCall()`:

1. Resolve/create the `Contact` from the caller's phone.
2. **Always**: log a timeline entry in the contact's WhatsApp conversation — a `Message`
   (`type: 'system'`, e.g. body `"📞 Missed call"` / `"📞 Call rejected"` / `"📞 Call answered elsewhere"`)
   so agents see call history inline with messages.
3. **If `auto_reject_calls` is on** and the event is `call.received`: `adapter->rejectCall()`,
   then if `call_reject_message` is set, send it as a normal text message to the caller.
4. Fire the `call.received` automation trigger (context: `{ call_id, call_type: audio|video,
   caller_phone }`) — always, regardless of the reject toggle, so users can build their own
   handling via automation too.

### Trigger

New `TRIGGER_TYPES` entry `call.received`; `AutomationTriggerListener::handleCallReceived()`.

Idempotency key: `call:<callId>:<event>` (received/accepted/rejected are distinct).

---

## 5. Read receipts & typing (per-number, default on)

Gated entirely by `send_receipts` (Section 4's migration). No-ops on Cloud API (no such
concept there).

- **Typing before automation/AI sends**: in `WhatsappDriver::sendViaWhatsappWeb`, before
  sending text/media/poll/etc. on a `whatsapp_web` conversation with `send_receipts` on:
  `sendTyping(..., true)`, sleep briefly (capped ~1.2s, skipped entirely in tests), send,
  `sendTyping(..., false)`. Implemented so a `sendTyping` failure never blocks the actual send
  (best-effort, caught and logged).
- **`sendSeen` on agent open**: when an agent opens a `whatsapp_web` conversation with unread
  inbound messages (existing "mark conversation read" path in `InboxController`), also call
  `sendSeen` for that chat if `send_receipts` is on.
- **`sendSeen` on automation/chatbot consume**: in `executeAiReply`, `executeRunChatbot`, and
  the `ask_question` resume path, when an inbound message is being read on a `whatsapp_web`
  conversation and `send_receipts` is on, call `sendSeen` before generating the reply.

---

## 6. Data, events, testing

### Migration
One migration adding `auto_reject_calls`, `call_reject_message`, `send_receipts` to
`whatsapp_web_sessions`.

### Webhook subscription (final list)
`message`, `session.status`, `message.ack`, `message.reaction`, `poll.vote`, `call.received`,
`call.accepted`, `call.rejected`.

### `WahaEventProcessor::process()`
`match (true)` gains one arm per new event; default arm (log + ignore) unchanged for anything
else.

### Idempotency
All new inbound handlers key through the existing `WebhookIdempotencyService`:
`reaction:<messageId>`, `pollvote:<pollMessageId>:<voter>`, `call:<callId>:<event>`.

### Testing (TDD, per feature — do not batch)
- `WahaAdapter`: one test per new method asserting the exact HTTP shape sent (mirrors existing
  `sendText`/`sendMedia` tests).
- `AutomationEngine::executeSendPoll`: branches correctly on provider; emulation path
  unaffected (regression-guard the existing Cloud test).
- `poll.vote` → context write-back + resume.
- `react_message` node: emits the right `Message`; skip-with-message when no `context.message_id`.
- Inbox react endpoint: creates message, calls driver, handles Cloud vs WAHA.
- `reaction.received` trigger fires with correct context, from both engines.
- `call.received`: reject-when-toggle-on, no-reject-when-off, message always logged, trigger
  always fires, idempotency holds across ack/duplicate webhook delivery.
- Typing/seen: only called when `send_receipts` true; never blocks/breaks the actual send on
  failure; no-op on Cloud API conversations.

### Explicitly out of scope (deferred, not silently dropped)
- WhatsApp Status/stories (item #6 in the audit) — separate, larger feature.
- WA-native label sync (item #7).
- `message.revoked` → deleted-message display (item #8).

---

## Files touched (expected)

- `app/Modules/WhatsappWeb/Contracts/EngineAdapter.php`
- `app/Modules/WhatsappWeb/Services/Waha/WahaAdapter.php`
- `app/Modules/WhatsappWeb/Services/WahaEventProcessor.php`
- `app/Modules/WhatsappWeb/Services/SessionProvisioner.php` (call/receipt settings helpers)
- `app/Modules/WhatsappWeb/database/migrations/` (new migration)
- `app/Modules/Whatsapp/Services/WhatsappDriver.php`
- `app/Modules/Whatsapp/Services/CloudApiClient.php`
- `app/Modules/Automation/Services/AutomationEngine.php`
- `app/Modules/Automation/Services/WorkflowGenerator.php` (TRIGGER_TYPES)
- `app/Listeners/AutomationTriggerListener.php`
- `app/Modules/Inbox/Http/Controllers/InboxController.php` (react endpoint, seen-on-open)
- `resources/js/Pages/Automation/Builder.jsx` (react_message node, poll fields, QR badge)
- `resources/js/locales/en.json`
- Inbox thread component(s) — exact path found during implementation
- Tests across `tests/Feature/WhatsappWeb/`, `tests/Feature/Automation/`
