# Public API: send a message (async WhatsApp)

## What it does
`POST /api/v1/messages/send` (token with `messages:write`). For `channel=whatsapp` the request
no longer waits for WAHA or the Cloud API. It records the message and returns **202**.

## Where the code is
- Controller: `app/Http/Controllers/Api/V1/MessageApiController.php`
  - `send()` — validation, contact lookup, channel branch.
  - `queueWhatsapp()` — 24-hour window check, creates the `messages` row as `queued`, dispatches the job.
  - `buildMediaMessage()` — still synchronous (see Known limits).
- Job: `app/Modules/Whatsapp/Jobs/SendQueuedWhatsappMessageJob.php` — `whatsapp` queue, `tries = 1`.
- Route: `routes/api.php` (`/messages/send`, `throttle:60,1`).

## Data flow
1. Validate input, find contact (404 if missing), resolve WhatsApp account (422 if none).
2. Media: upload the file to storage; Cloud API uploads the media id (sync, can fail with 422).
3. Window check (Cloud API, non-template): closed → **422** with `window_closed: true`.
4. Insert `messages` row, `status = queued`, `sent_by = automation`.
5. Dispatch job on `whatsapp` queue → **202** `{status: "queued", message_id}`.
   Template on a WhatsApp Web account also returns `degraded_to_text: true`.
6. Job: load the message, call the WhatsApp driver (WAHA or Cloud API).
   - Success → `status = sent`, `provider_message_id` set, `last_message_at` bumped, `MessageSent` broadcast.
   - Failure → `status = failed`, `error_json.message` holds the provider error. No retry.
   - Logs `api.message.sent` / `api.message.send_failed` with `duration_ms` (json log channel).

## Why no retry
A retry after a timeout can deliver the same message twice, so the job runs once.

## Caller behaviour
- The response no longer carries `provider_message_id`. Check the outcome with
  `GET /api/v1/conversations/{id}/messages` (the message `status` is `sent` or `failed`).
- Provider errors (for example WAHA not connected) no longer come back as `422`; they show on the message.
- SMS is unchanged (synchronous, `200`, `provider_message_id`).

## Known limits
- Cloud API media upload still happens inside the request (up to 120 s on the upload client).
- The job needs a running `whatsapp` queue worker (`docker/supervisor/whatsmine.conf`).

## Tests
`tests/Feature/Api/V1/MessageSendApiTest.php` (16) and `MessageSendMediaApiTest.php` (6).
Run: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=MessageSend`.
The test queue is `sync`, so the job runs inline and the DB already shows `sent`/`failed` after the request.

## Unverified
- Behaviour against the live queue worker and live WAHA is not yet checked (needs a deploy and a test send).
