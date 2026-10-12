# Webhook deliveries (outbound webhooks to client endpoints)

Status: built and tested (T-0008). Not deployed. Commit history: see `git log -- app/Jobs/DispatchWebhookJob.php`.

## What it does

When an event is sent to a client's webhook endpoint, one delivery row is written per attempt. Each row keeps the exact body and headers that were sent, and the response. The client sees them on the Deliveries page (`/client/webhooks/{endpoint}/deliveries`), under "Show body" for each row.

## Where the code is

| File | Role |
|---|---|
| `app/Jobs/DispatchWebhookJob.php` | Builds the body, signs it, stores the delivery row, sends it, schedules retries |
| `app/Models/WebhookDelivery.php` | Fillable fields and casts for the delivery row |
| `app/Models/WebhookEndpoint.php` | `signature()` builds the `X-Webhook-Signature` value |
| `app/Http/Controllers/Client/WebhookEndpointController.php` | `deliveries()` passes the paginated rows to the page |
| `resources/js/Pages/client/Webhooks/Deliveries.jsx` | Table with an expandable details row per delivery |
| `database/migrations/2026_10_13_000001_add_request_fields_to_webhook_deliveries_table.php` | Adds `request_body` and `request_headers` |

## What a delivery row stores

| Column | Content |
|---|---|
| `payload` | The caller's data only (no `event`, no `timestamp`) |
| `request_body` | The exact bytes sent, which are also the bytes that were signed |
| `request_headers` | `Content-Type`, `X-Webhook-Event`, `X-Webhook-Signature`, `User-Agent`. Never the endpoint secret |
| `response_status`, `response_body` | The receiver's reply; the body is cut at 2000 bytes |
| `attempts` | The job's attempt number for this row |

Rows written before 2026-10-13 have no `request_body`. The page says so.

## Signature (what receivers verify)

Header `X-Webhook-Signature`, value `t=<unix time>,v1=<hex>`, where `<hex>` is HMAC-SHA256 over `<t>.<raw body>` with the endpoint secret. A receiver recomputes the HMAC over the raw request body it received and compares with `v1`. It should also reject `t` values that are too old.

## Retries

Five tries in total, with waits of 1 minute, 5 minutes, 1 hour, then 1 day. A failed attempt is retried by the queue. The code writes a new delivery row at the start of each attempt, so one event can have several rows (one per attempt).

## Fixed on 2026-10-13: the body was sent twice-encoded

Before this change, the job passed the JSON string to `Http::post($url, $string)`. Laravel's default JSON mode encoded that string a second time, so the body on the wire was a JSON string containing the JSON, for example `"{\"event\":...}"`. The signature was computed over the plain JSON, so no receiver could verify it.

The job now sends the exact string with `withBody($json, 'application/json')`. The bytes sent, the bytes stored and the bytes signed are now the same.

**Effect on receivers:** a receiver that parsed the old body as a JSON string will now receive a JSON object. This must be checked on each receiving site before it relies on the change.

## Known receiver problem (not fixed here)

The isinternet endpoint (`rana7070.isinternet.com.pk/api/whatsmine/webhook/...`) returned **401** on every `message.received` delivery. Its check reads `X-Signature-256` and expects `sha256=` + HMAC of the raw body. WhatsMine sends `X-Webhook-Signature` in the `t=,v1=` format above. The receiver needs to be changed to verify this format; that change is in the isinternet code, not in this repo.

## How to test

```
DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='WebhookDeliveryBodyTest|WebhookEndpointTest'
```

Result on 2026-10-13: `OK (8 tests, 32 assertions)`. The first test caught the double-encoding bug before the fix.

Static checks: `npx eslint resources/js/Pages/client/Webhooks/Deliveries.jsx` (no output), PHPStan on the job and model (no errors).

## Deploy notes

Run `php artisan migrate --force` after deploy, then `php artisan config:clear`. Until the migration runs, the job fails on the new columns.
