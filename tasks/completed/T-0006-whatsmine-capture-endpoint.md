---
id: T-0006
title: Temporary WhatsMine capture endpoint in this repo (off by default)
status: completed
priority: high
type: feature
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: []
source: TEMP/intake-2026-10-12-2050.md
created: 2026-10-12 22:32
started: 2026-10-12 22:32
updated: 2026-10-12 22:43
completed: 2026-10-12 22:43
---

# T-0006: Temporary WhatsMine capture endpoint in this repo (off by default)

## What the user asked
> check what you need and build. built it now for your self. DONOT WORK IN SIBLING REPO esystematics PROJECT

## What this means
Add the capture endpoint described in the pasted "WhatsMine capture endpoint: spec and run plan" to this repo, so WhatsMine deliveries can be recorded exactly as sent. It is off unless `WHATSMINE_CAPTURE_ENABLED=true` and the URL carries the token. It does not verify signatures, change data or send messages. esystematics was not touched.

## Rules from CLAUDE.md (REQUIRED)
- [x] New backend code under `app/Modules/<Module>/` and registered by its service provider (Integrations module, loaded by its provider's module auto-discovery).
- [x] Webhook routes live in `routes/webhooks.php` (no CSRF, throttled) and each path is listed in `bootstrap/app.php` CSRF exceptions.
- [x] Never write secrets (token, signing secret) into the repo or into captured files.
- [x] Captured files go to `storage/app/whatsmine-capture`, which `storage/app/.gitignore` ignores.
- [x] Tests run with `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit`; one test process at a time.
- [x] Static analysis on changed PHP: `phpstan analyse` reports no errors on the controller and config.
- [x] Feature logic written to `app/logic/whatsmine-capture.md` and indexed in `app/logic/README.md`.
- [x] Commit by file name, push to `main`. No `resources/js` change, so no `npm run build`.
- [x] No deploy to wa.esystematics.com.

## Logic / design docs (REQUIRED)
- Read before work: `WHATSMINE-REQUIREMENTS.md`, `app/logic/README.md`.
- Updated: created `app/logic/whatsmine-capture.md`; added its row to `app/logic/README.md`.

## Skills (REQUIRED)
- [x] superpowers:brainstorming — bounded: the design is the pasted spec, which the user approved with "check what you need and build". Not re-run as a separate design step.
- [x] superpowers:test-driven-development — tests written first and seen failing (route missing → 404), then passing.
- [x] superpowers:verification-before-completion — test and phpstan output quoted below.
- [ ] superpowers:requesting-code-review — skipped: bounded change, one endpoint, no shared logic changed beyond one route and one CSRF entry.

## Prerequisites
- [x] Tasks: none
- [x] Needed from user / environment: none to build. A public address and a go-ahead are needed to run it live.

## Blocked by
none

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-000-general/PLAN.md` · Phase: `tasks/plans/PLAN-000-general/phase-01-ongoing.md` · Intake: `TEMP/intake-2026-10-12-2050.md`
- Related files: `config/whatsmine_capture.php`, `app/Modules/Integrations/Http/Controllers/WhatsMineCaptureController.php`, `routes/webhooks.php`, `bootstrap/app.php`, `tests/Feature/Integrations/WhatsMineCaptureTest.php`, `app/logic/whatsmine-capture.md`, `app/logic/README.md`

## Steps
- [x] Write the failing tests (disabled, wrong token, empty token, valid delivery saved exactly)
- [x] Run them and see them fail (valid delivery: 404, route missing)
- [x] Add `config/whatsmine_capture.php`, the controller, the route and the CSRF exception
- [x] Run the tests and see them pass
- [x] Run phpstan on the changed PHP files
- [x] Write `app/logic/whatsmine-capture.md` and the README row
- [x] Commit by file name, push to `main`

## Acceptance criteria
- [x] With the capture off, or a wrong or empty token, the endpoint returns 404 and writes nothing (covered by 3 tests, all green)
- [x] With the capture on and the right token, the endpoint returns `200 {"ok": true}` and writes one JSON file with the raw body (base64 and text), lower-cased headers, and no `authorization`/`cookie`/`x-csrf-token` (covered by the valid-delivery test, green)
- [x] phpstan reports no errors on the changed files
- [x] `app/logic/whatsmine-capture.md` exists and is indexed

## Progress log
- 2026-10-12 22:32 created from intake; started
- 2026-10-12 22:36 tests written; first run: valid delivery 404 (route not yet added), as expected
- 2026-10-12 22:40 config, controller, route and CSRF entry added; tests green
- 2026-10-12 22:43 phpstan clean; logic doc written; completed

## Result
- Changed: `config/whatsmine_capture.php` (new), `app/Modules/Integrations/Http/Controllers/WhatsMineCaptureController.php` (new), `routes/webhooks.php` (one route, one `use`), `bootstrap/app.php` (one CSRF exception), `tests/Feature/Integrations/WhatsMineCaptureTest.php` (new), `app/logic/whatsmine-capture.md` (new), `app/logic/README.md` (one row).
- Test: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=WhatsMineCaptureTest` → `OK (4 tests, 14 assertions)`.
- Static analysis: `php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G <controller> <config>` → `[OK] No errors`. (Without the memory flag phpstan ran out of memory, which is an environment limit, not a code finding.)
- Not run live: no public address yet and no webhook registration on WhatsMine. Both need the owner's go-ahead.
- Not deployed to wa.esystematics.com.
