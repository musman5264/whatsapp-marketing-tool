# Public API: send a message (async WhatsApp, result reported back)

## What it does
`POST /api/v1/messages/send` (token with `messages:write`). For `channel=whatsapp` the request
records the message, queues the provider call (WAHA or Cloud API) and then **waits up to `wait`
seconds for the result** so the caller learns "sent or failed" in the same reply.

## Where the code is
- Controller: `app/Http/Controllers/Api/V1/MessageApiController.php`
  - `send()` — validation, contact lookup, channel branch.
  - `queueWhatsapp()` — 24-hour window check, creates the `messages` row as `queued`, dispatches the job.
  - `outcomeResponse()` — polls the message row (every 250 ms, up to `wait` seconds) and builds the reply.
  - `show()` — `GET /api/v1/messages/{id}`.
  - `buildMediaMessage()` — still synchronous (see Known limits).
- Job: `app/Modules/Whatsapp/Jobs/SendQueuedWhatsappMessageJob.php` — `whatsapp` queue, `tries = 1`.
- Routes: `routes/api.php` (`POST /messages/send` throttle 60/min, `GET /messages/{id}`, both `messages:write`).
- Webhook events list: `OutboundWebhookApiController::ALLOWED_EVENTS` (`message.sent`, `message.failed`).

## Data flow
1. Validate input (`wait` is an integer 0–30), find contact (404 if missing), resolve WhatsApp account (422 if none).
2. Media: upload the file to storage; Cloud API uploads the media id (sync, can fail with 422).
3. Window check (Cloud API, non-template): closed → **422** with `window_closed: true`.
4. Insert `messages` row, `status = queued`, `sent_by = automation`, `user_id` = the API key's user.
5. Dispatch job on `whatsapp` queue.
6. Job: call the WhatsApp driver.
   - Success → `status = sent`, `provider_message_id` set, `last_message_at` bumped, `MessageSent` broadcast, webhook `message.sent`.
   - Failure → `status = failed`, `error_json.message` holds the provider error, webhook `message.failed`. No retry.
   - Logs `api.message.sent` / `api.message.send_failed` with `duration_ms` (json log channel).
7. Controller: after dispatch it re-reads the row until it is `sent` or `failed`, or `wait` seconds pass.

## What the caller gets back
| Situation | HTTP | Body |
|---|---|---|
| Sent within the wait | 200 | `{status:"sent", message_id, provider_message_id}` |
| Failed within the wait | 422 | `{status:"failed", message_id, error}` |
| Still queued after the wait, or `wait=0` | 202 | `{status:"queued", message_id}` |
| Template on a WhatsApp Web account | any of the above | plus `degraded_to_text: true` |

`wait` defaults to **15** seconds, maximum 30. `wait=0` skips the wait and always answers 202.
SMS is unchanged (synchronous, `200`, `provider_message_id`).

## Looking the result up later
`GET /api/v1/messages/{id}` (same token) returns
`{message_id, conversation_id, status, provider_message_id, error, sent_at}`.
`status` is the message row's status: `queued`, `sent` or `failed`, and on a WhatsApp Web (QR) number it later becomes
`delivered` or `read` when WAHA reports the ticks (`WahaEventProcessor::handleAck()` → `applyStatusUpdate()`; Cloud API path unverified).
No webhook fires for `delivered` / `read`. Only outbound messages of the token's own workspace are found (404 otherwise).

## Webhooks
Endpoints registered with `POST /api/v1/webhooks` by the same user as the API key get `message.sent`
or `message.failed` (an endpoint with no event filter gets all events). Payload:
`{event, message: {id, conversation_id, channel, to, status, provider_message_id, error}}` plus the usual
`timestamp`. Only API sends fire these (not inbox composer or campaign sends). Dispatching is soft-fail: a webhook
problem is logged (`api.message.webhook_failed`) and never changes the send outcome.

## Why no retry
A retry after a timeout can deliver the same message twice, so the job runs once.

## Known limits
- Cloud API media upload still happens inside the request (up to 120 s on the upload client).
- The job needs a running `whatsapp` queue worker (`docker/supervisor/whatsmine.conf`). With no worker, every
  send waits the full `wait` and answers 202 (the message stays `queued`).
- The wait holds a PHP worker for up to `wait` seconds; callers with a short HTTP timeout should send `wait` below it.

## Tests
`tests/Feature/Api/V1/MessageSendApiTest.php`, `MessageSendMediaApiTest.php`, `MessageSendOutcomeApiTest.php` (13: reply
outcome, `wait`, status endpoint, webhooks).
Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=MessageSend`.
The test queue is `sync`, so the job runs inline and the first check already sees `sent`/`failed`.

## Unverified
- Behaviour against the live queue worker, live WAHA and the client's real HTTP timeout is not yet checked
  (needs a deploy and a test send).
