# WhatsMine capture endpoint (temporary)

Status: built, **off by default**, not deployed, not run against WhatsMine. Task T-0006. Spec: [WHATSMINE-CAPTURE-SPEC.md](../../WHATSMINE-CAPTURE-SPEC.md) (its committed version; the working-tree copy was empty at the time of writing).

## What it does

Records every delivery sent to it, exactly as received, so the real WhatsMine webhook shape (field names, signature header, inbound image fields) can be read from files. It does **not** verify signatures, change data, send messages or answer anything except `200 {"ok": true}`.

## Where the code is

| File | Role |
|---|---|
| `config/whatsmine_capture.php` | `enabled` (from `WHATSMINE_CAPTURE_ENABLED`), `token` (from `WHATSMINE_CAPTURE_TOKEN`), `dir` (`storage/app/whatsmine-capture`) |
| `app/Modules/Integrations/Http/Controllers/WhatsMineCaptureController.php` | `receive()`: checks enabled and token, writes one JSON file, returns 200 |
| `routes/webhooks.php` | `POST webhooks/whatsmine-capture/{token}` inside the `throttle:webhooks` group |
| `bootstrap/app.php` | `webhooks/whatsmine-capture/*` in the CSRF exceptions list |
| `tests/Feature/Integrations/WhatsMineCaptureTest.php` | 4 tests |

## Data flow

1. WhatsMine POSTs to `/webhooks/whatsmine-capture/{token}`.
2. Not enabled, or token missing / wrong / empty → `404`, nothing written.
3. Enabled and token matches → one file is written to `storage/app/whatsmine-capture/`:

```json
{
  "received_at": "2026-10-12T17:13:00+00:00",
  "method": "POST",
  "headers": { "x-signature-256": ["sha256=..."], "content-type": ["application/json"] },
  "raw_body_base64": "...",
  "raw_body_text": "... or null when the body is not valid UTF-8"
}
```

4. Response: `200 {"ok": true}`.

Headers are lower-cased. `authorization`, `cookie` and `x-csrf-token` are never written. The raw body is kept byte-for-byte in base64; the text copy is only there for reading.

## Security and storage

- Off unless both the flag and the token are set. An empty token never matches.
- Files go to `storage/app/`, which `storage/app/.gitignore` ignores (everything except `private/`, `public/`).
- The files contain phone numbers and message text. Delete them after the field names are recorded.
- The token and the WhatsMine signing secret are never written to disk by this code.

## Failure behaviour

- The folder is created on first use (mode 0700).
- Non-UTF-8 bodies are still saved (base64 is exact; the text copy is `null`).

## How to test

```
DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=WhatsMineCaptureTest
```

Result on 2026-10-12: `OK (4 tests, 14 assertions)`. The tests use no database. Static analysis: `php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G <files>` reports no errors for the controller and config.

## Known unverified points

- Whether WhatsMine accepts a plain HTTPS URL at this path, and which signature header it sends. Both are the purpose of the capture; see `WHATSMINE-REQUIREMENTS.md`, items W3 to W10.
- The live capture has not been run. It needs a public HTTPS address (this app is public at wa.esystematics.com once deployed, which needs the owner's go-ahead) and the webhook registration on the WhatsMine account, which also needs the owner's go-ahead.

## Removal

Once the contract is recorded: set `WHATSMINE_CAPTURE_ENABLED=false`, delete the WhatsMine webhook, delete the captured files, then remove the controller, the route, the config, the CSRF entry and the tests in a separate commit. Update this file to say it was removed.
