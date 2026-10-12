# WhatsMine capture endpoint: spec and run plan

Status: built in esystematics (commit `2b143de`, `master`), off by default. Live capture not run yet. Use this file to build the same endpoint in any other repo that should receive WhatsMine webhooks.

## Purpose

WhatsMine's inbound webhook format is not confirmed (see [WHATSMINE-REQUIREMENTS.md](WHATSMINE-REQUIREMENTS.md), items W3 to W10). The capture endpoint records real deliveries exactly as sent, so the real field names, the signature header and the image fields can be read from the files. It does not verify signatures, change data or send messages.

## Contract (what any capture endpoint must do)

1. **Route:** `POST /webhooks/whatsmine-capture/{token}`. The token is a long random secret. Any other token gets `404`.
2. **Off by default.** Nothing is recorded unless the capture is switched on by configuration. With it off, every call gets `404`.
3. **Record the delivery exactly as received:**
   - the raw body, base64-encoded (so bytes are never altered), and as text when it is valid UTF-8;
   - the headers, lower-cased, with `authorization`, `cookie` and `x-csrf-token` removed;
   - the time received and the HTTP method.
4. **Answer `200` with `{"ok": true}`** straight away. No other processing.
5. **Store one file per delivery** in a folder that is not web-accessible and is git-ignored.
6. **Never** write the token, the signing secret, or any credential to the file.

### Record format (one JSON file per delivery)

```json
{
  "received_at": "2026-10-12T20:00:00+00:00",
  "method": "POST",
  "headers": { "x-signature-256": ["sha256=..."], "content-type": ["application/json"] },
  "raw_body_base64": "...",
  "raw_body_text": "... or null if the body is not valid UTF-8"
}
```

## Reference implementation (esystematics)

| File | Role |
|---|---|
| `config/whatsmine_capture.php` | `enabled` (from `WHATSMINE_CAPTURE_ENABLED`), `token` (from `WHATSMINE_CAPTURE_TOKEN`), `dir` (`storage/app/whatsmine-capture`) |
| `app/Http/Controllers/WhatsMineCaptureController.php` | Checks enabled and token, writes the record, returns 200 |
| `routes/web.php` | Route `webhooks.whatsmine-capture` |
| `bootstrap/app.php` | Excludes `webhooks/whatsmine-capture/*` from CSRF checks |
| `tests/Feature/WhatsMineCaptureTest.php` | 4 tests: disabled, wrong token, empty token, valid delivery saved exactly |

Tests run with `php artisan test --filter=WhatsMineCaptureTest` (4 passed on 2026-10-12). They use no database.

## Run plan

Each step names who does it. Steps 4 and 6 change the live WhatsMine account or the server, so they need your go-ahead first.

1. **Pick a public address.** WhatsMine must reach the endpoint over HTTPS. The local dev site (`localhost`) is not reachable from the internet. Choose one:
   - deploy esystematics with the capture on (needs your approval), or
   - a tunnel to the dev machine (your choice of tool).
2. **Generate a token** (run in the esystematics folder): `php -r "echo bin2hex(random_bytes(24));"`. Keep it out of the repo and out of chat.
3. **Set the environment on that server:** `WHATSMINE_CAPTURE_ENABLED=true` and `WHATSMINE_CAPTURE_TOKEN=<token>`. Then clear the config cache there.
4. **Register the webhook with WhatsMine.** `POST https://<base>/api/v1/webhooks` with bearer token, body `{"url": "https://<public host>/webhooks/whatsmine-capture/<token>", "events": ["message.received"]}`. Save the returned `id` and `secret`; the secret is needed for step 6. **Needs your go-ahead.**
5. **Send four test messages** from a staff phone to the connected number:
   - (a) one line of text;
   - (b) `REPLY-TK-0001`, a line break, then two lines of text;
   - (c) an image alone;
   - (d) an image with the caption `REPLY-TK-0001`, a line break, then text.
6. **Read the files** in `storage/app/whatsmine-capture/` (one per delivery, newest last). For each one, record:
   - the envelope and field names in `raw_body_text` (sender, text, message id, type, media fields, caption);
   - whether the text keeps its line breaks;
   - which header carries the signature (`x-signature-256` or another name).
   Then check the signature: decode `raw_body_base64`, compute `sha256=` + HMAC-SHA256 with the secret from step 4, and compare with the header. A match confirms W6.
7. **Record the results** in `WHATSMINE-REQUIREMENTS.md`: replace the example shape in section 2 with the real one, and update the status of W4 to W10.
8. **Remove the capture.** Set `WHATSMINE_CAPTURE_ENABLED=false`, then delete the webhook on WhatsMine (`DELETE /api/v1/webhooks/{id}`), then delete the captured files (they hold phone numbers and message text), then remove the endpoint code and route in a separate commit. **Needs your go-ahead.**

## What to build in another repo

If the same capture is needed in another repo, build the endpoint with the contract above, then run the run plan from step 2. Use the same route shape, the same record format, and the same tests (disabled, wrong token, empty token, saved exactly). Keep the storage folder out of git.
