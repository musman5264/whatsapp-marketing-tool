# WAHA API coverage audit

_Branch `main` — checked against `WahaAdapter.php`, `EngineAdapter.php`, `WhatsappDriver.php`,
`AutomationEngine.php`, `WahaEventProcessor.php`, `InboundNormalizer.php`, `CloudApiClient.php`._

Two engines run under one `whatsapp` channel:

- **Cloud API** — Meta WhatsApp Business Platform (templates, interactive buttons/lists, flows, catalogs).
- **WAHA** — a personal number linked by QR code. `ChannelAccount.provider === 'whatsapp_web'`.

The WAHA adapter exposes exactly **three send verbs**: `sendText`, `sendMedia`, `sendLocation`.
Everything richer is authored for the Cloud API and, on a personal number, is flattened to a
numbered plain-text message by `interactiveAsPlainText()` / `templateAsPlainText()` in
`WhatsappDriver`. It sends *something* — the tappable UI is gone.

Legend: **wired** = called today · **partial** = degrades / one path only · **missing** = not
called anywhere · **n/a** = not applicable to this product.

---

## Coverage summary

| Group | Wired | Partial | Missing | Note |
|---|---|---|---|---|
| Send / messaging | 3 | 4 | 8 | only text/media/location are native |
| Status / stories | 0 | 0 | 6 | no concept of Status anywhere in the codebase |
| Chats | 2 | 3 | 12 | app uses its own tables; WA write actions unused |
| Contacts | 3 | 1 | 9 | `resolveContact` (LID→phone) is load-bearing; `PUT contacts/{chatId}` saves new senders to the phone (soft-fail, once per contact) |
| Calls | 0 | 0 | 2 | inbound calls to a QR number ring out unrecorded |
| Groups | — | — | — | deliberately excluded (`InboundNormalizer` drops them) |
| Labels | 3 | 0 | 8 | auto-label rules apply `inbox_labels`; mirrored to WA labels (Business numbers only, soft-fail) |
| Webhook events | 8 | 1 | ~12 | `message.any` (both directions), `session.status`, `message.ack`, reactions, poll votes, calls; list in `WahaAdapter::WEBHOOK_EVENTS` |
| Sessions / auth | 7 | 1 | 1 | the solid part — full lifecycle covered |

---

## Send & messaging (15 endpoints)

| WAHA endpoint | Cloud API | Personal / WAHA | Automation node | Status |
|---|---|---|---|---|
| `POST /api/sendText` | ✓ `sendText` | ✓ native | Send WhatsApp, AI Reply, Chatbot, Ask a Question | **wired** |
| `POST /api/sendImage · sendFile · sendVoice · sendVideo` | ✓ `sendMedia` | ✓ native, by public URL | Send Media, sequence media steps | **wired** |
| `POST /api/sendLocation` | ✓ `sendLocation` | ✓ native | Send Location | **wired** |
| `POST /api/sendButtons · sendList · send/buttons/reply` | ✓ interactive | flattened to "1. … 2. …" text | Quick Replies, List Message, Call to Action | **partial** |
| `POST /api/sendPoll` | emulated as buttons/list | flattened to text — **WAHA has a native poll this app never calls** | Send Poll | **partial** |
| `POST /api/sendContactVcard` | — (inbound `contacts` type parsed, never sent) | not implemented | none | **missing** |
| `PUT /api/reaction` | inbound reactions stored; no outbound | not implemented | none — no "React" node or inbox reaction button | **missing** |
| `POST /api/forwardMessage` | — | not implemented | none | **missing** |
| `POST /api/sendSeen` | Cloud `markRead` exists but not called on WAHA inbound | not implemented — customer never sees blue ticks from the bot | none | **missing** |
| `POST /api/startTyping · stopTyping` | — | not implemented — inbox "typing" is agent-to-agent broadcast only, never sent to WA | none | **missing** |
| `PUT /api/star` | — | not implemented | none | **missing** |
| `POST /api/sendPollVote` | — | n/a — bot doesn't vote in others' polls | none | **n/a** |
| `POST /api/{session}/events` | Cloud API flows / event messages | not implemented on WAHA | Book Appointment / Google Meet send a link as text | **missing** |
| `GET /api/{session}/new-message-id` | — | not needed — send responses already return the id | n/a | **n/a** |

---

## Status / stories (6 endpoints)

Nothing here is touched. No model, no node, no composer, no UI. This is a whole feature
surface, not a gap in an existing one.

| WAHA endpoint | In the app | Where it would live | Status |
|---|---|---|---|
| `POST /api/{session}/status/text` | nothing | a "Post a Status" automation node + a Broadcasting composer tab | **missing** |
| `POST /api/{session}/status/image` | nothing | same | **missing** |
| `POST /api/{session}/status/voice` | nothing | same | **missing** |
| `POST /api/{session}/status/video` | nothing | same | **missing** |
| `POST /api/{session}/status/delete` | nothing | a "delete" action on a posted-status record | **missing** |
| `GET /api/{session}/status/new-message-id` | nothing | only if batch-targeting statuses to specific contacts | **missing** |

---

## Chats (17 endpoints)

The app keeps its own `conversations` / `messages` tables fed by the webhook, so it rarely
reads chat state back from WAHA. The write actions — mark read, archive, pin, delete, edit —
are real inbox features that currently do nothing on a personal number.

| WAHA endpoint | In the app | Notes | Status |
|---|---|---|---|
| `GET /chats · /chats/overview` | ✓ `listChats()` via `WahaHistorySync` (every 10 min, on connect, Sync now) | unread count of 0 on the phone clears ours; reads `_chat.unreadCount` defensively | **wired** |
| `GET /chats/{id}/messages · /messages/{mid}` | ✓ `chatMessages()` (limit, `downloadMedia=false`, newest first) via `WahaHistorySync` | backfills missed inbound + phone-sent messages as historical (no automations/AI, no unread bump when >5 min old); ticks catch up | **wired** |
| `POST /chats/{id}/messages/read` | not called on WAHA | agent opening a conversation doesn't send a read receipt | **missing** |
| `PUT /chats/{id}/messages/{mid}` (edit) | not called | no "edit sent message" in the inbox | **missing** |
| `DELETE /chats/{id}/messages/{mid}` | not called | no "delete for everyone" | **missing** |
| `POST /chats/{id}/messages/{mid}/pin · /unpin` | not called | — | **missing** |
| `POST /chats/{id}/archive · /unarchive` | not called | app has its own conversation status; not mirrored to WA | **missing** |
| `POST /chats/{id}/unread` | not called | — | **missing** |
| `DELETE /chats/{id} · /chats/{id}/messages` | not called | destructive; probably correct to leave out | **n/a** |
| `GET /chats/{id}/picture` | not called | contact avatars come from the inbound payload instead | **partial** |

---

## Contacts (~12 endpoints)

`GET /api/contacts` is load-bearing: `resolveContact()` turns a LID (`123@lid`, which hides
the phone number in webhook payloads) into a real number + name. The rest are unused.

| WAHA endpoint | In the app | Notes | Status |
|---|---|---|---|
| `GET /api/contacts` (by contactId) | ✓ `resolveContact()` | LID → phone + name resolution on inbound | **wired** |
| `GET /api/sessions/{s}/me` | ✓ `getMe()` | paired account phone + push name after QR scan | **wired** |
| `GET /api/contacts/check-exists` | not called | Cloud path validates numbers; WAHA path assumes valid. A pre-send check would cut failed sends | **missing** |
| `GET /api/contacts/all` | not called | no "import my WhatsApp contacts" on connect | **missing** |
| `GET /api/contacts/profile-picture · /about` | not called | could enrich the contact record | **missing** |
| `POST /api/contacts/block · /unblock` | not called | no block action in the inbox / no "Block" automation node | **missing** |
| `GET /api/{s}/lids · /lids/{lid} · /lids/pn/{phone}` | not called | `resolveContact` covers the one case that matters; bulk LID mapping unused | **partial** |
| `PUT /api/{s}/contacts/{chatId}` (update name in phone address book) | `WahaContactSaver::saveToPhone()` on the first inbound message of a new WA-Web contact; one retry after 2s; result stored in `contact.custom_fields` (`saved_to_phone_at` / `phone_save_error`) | WAHA says web can't add brand-new contacts, so failures are expected and logged, never thrown; toggle `auto_save_contacts` | **partial** |

---

## Calls (1 action + call events)

A personal number receives voice/video calls. WAHA can auto-reject them and emit a
`call.received` event. The app subscribes to neither, so an inbound call to a business's QR
number just rings out with no record and no auto-response.

| WAHA endpoint / event | In the app | Notes | Status |
|---|---|---|---|
| `POST /api/{session}/calls/reject` | not called | no "auto-reject calls + send a message" setting | **missing** |
| event `call.received` | not subscribed | could trigger an automation ("missed call → WhatsApp them back") | **missing** |

---

## Groups (deliberately excluded)

`InboundNormalizer.php:39` explicitly drops group, broadcast, newsletter and status messages
— "Only 1:1 chats." A product decision, not an oversight. Leave it unless group broadcasting
becomes a goal.

---

## Labels (8 endpoints · WhatsApp Business only)

The app has a labels feature — an internal `inbox_labels` table, unrelated to WAHA.
WhatsApp's own chat labels (visible in the WhatsApp Business app) are never read or written,
so labels applied in-app and labels on the phone drift apart. Two-way sync is the opportunity.

| WAHA endpoint | In the app | Notes | Status |
|---|---|---|---|
| `GET/POST /api/{s}/labels` | `WahaLabelSync::ensureRemoteLabel()` (match by name, else create with `colorHex`); id stored in `inbox_labels.wa_label_id` | Business numbers only; 4xx/501 parks the feature for 1h per session | **wired** |
| `PUT/DELETE /api/{s}/labels/{id}` | not called | app label rename/delete does not propagate to WhatsApp yet | **missing** |
| `GET/PUT /api/{s}/labels/chats/{chatId}/` | `WahaLabelSync::syncConversationLabels()` on attach/detach and on rule-applied labels; PUT sends the UNION of remote + mapped local ids (PUT replaces the full set) | removed label is dropped explicitly; labels set on the phone are kept | **wired** |
| `GET /api/{s}/labels/{id}/chats` | not called | — | **missing** |

---

## Webhook events (3 subscribed of ~12)

`WahaAdapter::WEBHOOK_EVENTS` is the single list WAHA is asked for. It subscribes to `message.any`
(not `message`) so messages sent from the phone or another linked device arrive with
`payload.fromMe = true`. Our own API sends come back with `payload.source = 'api'` and are skipped
(already stored). Sessions created before this change keep `message`; the processor accepts both.
Re-register on existing sessions with `php artisan whatsapp-web:resubscribe` (or Sync now).

| WAHA event | Handled | What it would unlock | Status |
|---|---|---|---|
| `message.any` | ✓ `handleInbound` / `handleOwnMessage` (fromMe) | inbound pipeline; phone-sent replies stored as outbound, unread cleared | **wired** |
| `message` | ✓ same handler (legacy subscription) | old sessions until resubscribed | **wired** |
| `session.status` | ✓ `handleSessionStatus` | connect / disconnect / QR-expiry UI | **wired** |
| `message.ack` | ✓ `handleAck` (id matched in full or bare form) | sent / delivered / read ticks on outbound and phone-sent messages | **wired** |
| `message.reaction` | only via the plain `message` shape | a dedicated reaction feed / "customer reacted" trigger | **partial** |
| `message.revoked` | not subscribed | show "this message was deleted" in the inbox | **missing** |
| `call.received` · `call.accepted` · `call.rejected` | not subscribed | missed-call automations, call logging | **missing** |
| `poll.vote` | not subscribed | native poll results back into automation context | **missing** |
| `presence.update` | not subscribed | "contact is online / last seen" in the inbox | **missing** |
| `label.upsert` · `label.deleted` · `label.chat.added` · `label.chat.deleted` | handled by `WahaLabelSync::handleWebhook()`; needs subscription + hook in `WahaEventProcessor` | applied locally without echoing back to WhatsApp | **wired (pending hook)** |
| `group.*` · `chat.archive` | not subscribed | out of scope (see Groups) | **n/a** |

---

## Sessions & auth (the solid part)

The lifecycle is fully covered: idempotent create/start, QR fetch (JSON-vs-PNG handling for
different WAHA builds), status polling, paired-account lookup, logout+delete, HMAC-signed
webhooks, and Core-edition single-session detection with a helpful error.

| WAHA endpoint | In the app | Status |
|---|---|---|
| `POST /api/sessions · /start` | ✓ `startSession()` — idempotent create | **wired** |
| `PUT /api/sessions/{s}` (update config) | ✓ `resubscribe()` — re-applies webhook URL/events/HMAC; WAHA restarts a running session, device stays linked (no logout). Was `POST`, which WAHA does not route, so existing sessions never got the new config | **wired** |
| `GET /api/sessions/{s}` (status) | ✓ `getStatus()` with normalised states | **wired** |
| `GET /api/{s}/auth/qr` | ✓ `getQr()` → data URI | **wired** |
| `GET /api/sessions/{s}/me` | ✓ `getMe()` | **wired** |
| `POST /api/sessions/{s}/logout` · `DELETE /api/sessions/{s}` | ✓ `logout()` — best-effort both | **wired** |
| webhook HMAC (`X-Webhook-Hmac`) | ✓ verified in `WhatsappWebWebhookController` | **wired** |
| `GET /api/sessions` (list) | ✓ used only inside the Core "already linked" error path | **partial** |
| proactive session health / auto-restart | relies on `session.status` webhook + manual reconnect; no scheduled healthcheck ping | **missing** |

---

## Build next (ranked by value ÷ effort)

Ordered for a QR-first product. Each is small and self-contained — a new adapter method plus
a wiring point.

### 1. Native poll on personal numbers
Add `sendPoll()` to `EngineAdapter` / `WahaAdapter` and route the `send_poll` node to it when
the channel is `whatsapp_web`. Also subscribe to `poll.vote` and feed the result into
automation context. Turns the app's weakest degrade into a first-class feature — the one node
users will expect to "just work".
_WahaAdapter.php · AutomationEngine::executeSendPoll · startSession events[]_

### 2. Read receipts & typing on the WAHA path
Call `POST /api/sendSeen` when an agent opens a conversation, and `startTyping`/`stopTyping`
around automation and AI-reply sends. Cheap, and it makes the bot feel human — customers
currently never get a blue tick from a QR-linked business.
_WhatsappDriver::sendViaWhatsappWeb · InboxController::show · AutomationEngine send helpers_

### 3. Outbound reactions + a "React" node
`PUT /api/reaction` is one call. Add an inbox emoji-react button and an automation node
("react 👍 to the trigger message"). Inbound reactions are already stored, so the feed is
half-built.
_WahaAdapter::sendReaction · CloudApiClient::sendReaction · new node in Builder.jsx_

### 4. Call handling
Subscribe to `call.received`; offer an "auto-reject incoming calls and reply with a message"
toggle per connected number (`POST /api/{session}/calls/reject`), and a `call.received`
automation trigger for "missed call → WhatsApp follow-up".
_startSession events[] · new WahaEventProcessor arm · new trigger type_

### 5. Pre-send number check
On the WAHA path, call `GET /api/contacts/check-exists` before the first send to a new number
and mark the contact invalid on a negative — mirroring what the Cloud API already gives you
for free. Cuts silent delivery failures.
_WahaAdapter::numberExists · WhatsappDriver::sendViaWhatsappWeb_

### 6. WhatsApp Status posting
Bigger lift — a new feature, not a gap. `status/text|image|video` + a model to track posted
statuses + a Broadcasting composer tab and/or a "Post a Status" automation node. Only if
"broadcast to all my contacts' status feed" is a roadmap goal.
_new StatusComposer · new adapter methods · new automation node_

### 7. WhatsApp-native label sync
When an `inbox_labels` label is applied to a WAHA conversation, mirror it with
`PUT /api/{s}/labels/chats/{chatId}`; subscribe to `label.chat.added` for the reverse. Keeps
the WhatsApp Business app and this tool in agreement.
_Inbox label action · new WahaEventProcessor arm_

### 8. `message.revoked` → "deleted message" in the inbox
Subscribe and mark the local message row deleted so the agent sees the customer retracted
something, instead of replying to a message that's gone on the customer's side.
_startSession events[] · WahaEventProcessor_
