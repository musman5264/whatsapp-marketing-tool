# Webhook deliveries (outbound webhooks to client endpoints)

Status: built and tested (T-0008). Not deployed. Commit history: see `git log -- app/Jobs/DispatchWebhookJob.php`.

## What it does

When an event is sent to a client's webhook endpoint, one delivery row is written per attempt. Each row keeps the exact body and headers that were sent, and the response. The client sees them on the Deliveries page (`/client/webhooks/{endpoint}/deliveries`), under "Show body" for each row.

## Where the code is

| File | Role |
|---|---|
| `app/Jobs/DispatchWebhookJob.php` | Builds the body, signs it, stores the delivery row, sends it, schedules retries |
| `app/Services/WebhookMediaLinks.php` | Adds the signed media link to a `message.received` body, once per attempt (T-0030) |
| `app/Models/WebhookDelivery.php` | Fillable fields and casts for the delivery row |
| `app/Models/WebhookEndpoint.php` | `signature()` builds the `X-Webhook-Signature` value |
| `app/Http/Controllers/Client/WebhookEndpointController.php` | `deliveries()` passes the paginated rows to the page |
| `resources/js/Pages/client/Webhooks/Deliveries.jsx` | Table with an expandable details row per delivery |
| `database/migrations/2026_10_13_000001_add_request_fields_to_webhook_deliveries_table.php` | Adds `request_body` and `request_headers` |

## What a delivery row stores

| Column | Content |
|---|---|
| `payload` | The caller's data only (no `event`, no `timestamp`). For `message.received` this is the unresolved payload: a media link is never stored here |
| `request_body` | The exact bytes sent, which are also the bytes that were signed. For a WhatsApp Web file this includes the signed link |
| `request_headers` | `Content-Type`, `X-Webhook-Event`, `X-Webhook-Signature`, `User-Agent`. Never the endpoint secret |
| `response_status`, `response_body` | The receiver's reply; the body is cut at 2000 bytes |
| `attempts` | The job's attempt number for this row |

Rows written before 2026-10-13 have no `request_body`. The page says so.

## Signature (what receivers verify)

Header `X-Webhook-Signature`, value `t=<unix time>,v1=<hex>`, where `<hex>` is HMAC-SHA256 over `<t>.<raw body>` with the endpoint secret. A receiver recomputes the HMAC over the raw request body it received and compares with `v1`. It should also reject `t` values that are too old.

## Retries

Five tries in total, with waits of 1 minute, 5 minutes, 1 hour, then 1 day. A failed attempt is retried by the queue. The code writes a new delivery row at the start of each attempt, so one event can have several rows (one per attempt). Each attempt builds its body again, so a `message.received` retry gets a new media link (see below).

## Fixed on 2026-10-13: the body was sent twice-encoded

Before this change, the job passed the JSON string to `Http::post($url, $string)`. Laravel's default JSON mode encoded that string a second time, so the body on the wire was a JSON string containing the JSON, for example `"{\"event\":...}"`. The signature was computed over the plain JSON, so no receiver could verify it.

The job now sends the exact string with `withBody($json, 'application/json')`. The bytes sent, the bytes stored and the bytes signed are now the same.

**Effect on receivers:** a receiver that parsed the old body as a JSON string will now receive a JSON object. This must be checked on each receiving site before it relies on the change.

## message.received payload

Sent for every inbound message, to each endpoint subscribed to `message.received` (all channels):

```json
{
  "event": "message.received",
  "message": {
    "id": 123,
    "conversation_id": 45,
    "direction": "inbound",
    "channel": "whatsapp",
    "body": "REPLY-TK-0004\nHI this is the reply",
    "sent_at": "2026-10-12T23:44:00+00:00",
    "from": "+923001234567",
    "media": null
  },
  "timestamp": "2026-10-12T23:44:01+00:00"
}
```

- `message.id` is the WhatsMine database id. It is stable for a message, so receivers can use it to ignore repeats.
- `from` is the sender's number as stored on the contact. It is `null` when the conversation has no contact.
- `media` is `null` for text. For an attachment it is an object with four keys:
  - `id`: the id in the `media` table. `null` for a WhatsApp Web photo or file, which is stored as a file and has no media row.
  - `mime_type`: the MIME type of the file, or `null` when it is not known.
  - `filename`: the file's name when the message stores one. WhatsApp Web documents keep it under the type key (`document.filename`), and it is used from there. `null` when no name is stored.
  - `url`: a signed link to the file, or `null`. Explained below.
- Media row example: `{"id": 7, "mime_type": "image/jpeg", "filename": null, "url": null}`.
- WhatsApp Web photo or file example: `{"id": null, "mime_type": "image/jpeg", "filename": null, "url": "https://<APP_URL>/private-files/message-media/1567.jpg?expires=...&signature=..."}`.
- Source: `app/Listeners/DispatchOutboundWebhookListener.php` (`messageReceivedPayload()`, `mediaPayload()`). Test: `tests/Feature/Webhooks/MessageReceivedPayloadTest.php`.
- `from` and `media` appear on live endpoints only after this code is deployed.

### Signed media link (WhatsApp Web files, T-0030)

- The placeholder (`id`, `mime_type`, `filename`, `url` all `null`) is sent only for a photo, video, audio or document with no media row on a `whatsapp_web` channel account. The file is saved after `MessageReceived` fires, so `app/Services/WebhookMediaLinks.php` fills it in when the job builds the body.
- Other providers (Meta Cloud API, Messenger, Instagram) with no media row keep `media: null`, so their webhook bodies do not change.
- Each attempt makes its own link, valid for 1 hour from that attempt. A retry sends a new link. The link serves the file's bytes while it is valid, and returns 403 after it expires (outside production).
- The link is not stored in the delivery row's `payload`. It is in `request_body`, which is what the Deliveries page shows.
- If the file is not stored at attempt time, or the lookup fails, `url` is `null` and the webhook is still sent. A failed lookup logs `webhook.media_link_failed` with the message id and the error text only, never the link.
- Race (not verified live): the file is saved after the event fires. If a webhook attempt runs before the file is saved (possible with several queue workers), that attempt sends `url: null`. Only a failed attempt is retried, so a successful attempt with `url: null` is not sent again.
- Receiver check: the receiver must verify `X-Webhook-Signature` (see "Signature") and must download the file within one hour of the attempt that carried the link. The isinternet receiver returns 401 on every delivery (see below), so it cannot use the link until that is fixed.
- Test: `tests/Feature/Webhooks/WebhookMediaLinkTest.php`. The result is in "How to test" above.

## Known receiver problem (not fixed here)

The isinternet endpoint (`rana7070.isinternet.com.pk/api/whatsmine/webhook/...`) returned **401** on every `message.received` delivery. Its check reads `X-Signature-256` and expects `sha256=` + HMAC of the raw body. WhatsMine sends `X-Webhook-Signature` in the `t=,v1=` format above. The receiver needs to be changed to verify this format; that change is in the isinternet code, not in this repo.

## How to test

```
DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='WebhookDeliveryBodyTest|WebhookEndpointTest'
```

Result on 2026-10-13: `OK (8 tests, 32 assertions)`. The first test caught the double-encoding bug before the fix.

For the signed media link (T-0030):

```
DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='MakePrivateMediaCommandTest|WebhookMediaLinkTest|MessageReceivedPayloadTest|WebhookDeliveryBodyTest|WebhookEndpointTest'
```

Result, 2026-10-13 18:19: `OK (34 tests, 150 assertions)` (run by the main thread, after the fixes listed in the commit). Not verified live: the race, the link on the live server, and the receiver.

Static checks: `npx eslint resources/js/Pages/client/Webhooks/Deliveries.jsx` (no output), PHPStan on the job and model (no errors).

## Deploy notes

Run `php artisan migrate --force` after deploy, then `php artisan config:clear`. Until the migration runs, the job fails on the new columns.

## One endpoint per URL when registering through the API (fixed 2026-10-24)
`POST /api/v1/webhooks` used to create a new endpoint every time, even for a URL already registered by the same user. Each copy has its own secret and receives every event, so a receiver that only knows the newest secret (EPOS did) answered 401 "Bad signature" for the older copies while accepting the newest: its API log showed the same message once refused and once accepted.
Now (`OutboundWebhookApiController::store()`): the same `url` for the same user updates the existing endpoint (events replaced, re-enabled, description kept unless sent), issues a **new secret** returned once, answers `200` (a new URL still answers `201`), and deletes any older duplicate endpoints of that URL. Different URLs, and the same URL for a different user, stay separate. The caller must store the new secret each time it registers, because registering again replaces the old one.
Test: `tests/Feature/Api/V1/OutboundWebhookApiTest.php` (3 new tests, 11 in the file OK). Not run on the live site: existing duplicates there disappear the next time the receiver registers, or by deleting them on the Webhooks page.
