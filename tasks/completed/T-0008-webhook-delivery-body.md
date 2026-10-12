---
id: T-0008
title: Show the exact body and headers of each WhatsMine webhook delivery
status: completed
priority: high
type: feature
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: []
source: TEMP/intake-2026-10-12-2050.md
created: 2026-10-13 00:07
started: 2026-10-13 00:07
updated: 2026-10-13 00:08
completed: 2026-10-13 00:08
---

# T-0008: Show the exact body and headers of each WhatsMine webhook delivery

## What the user asked
> i want you to show the body also here

Answer to the follow-up question: "add it to whatsmine. and this repo is whatsmine that we are working".

## What this means
The Deliveries page (WhatsMine webhook log) shows, for each delivery, the headers and body that were sent and the response. While building it, a bug was found: the body was sent twice-encoded, so the signature could never be verified by a receiver. That is fixed here too.

## Rules from CLAUDE.md (REQUIRED)
- [x] Feature logic written to `app/logic/webhook-deliveries.md` and indexed in `app/logic/README.md`.
- [x] Tests first (TDD): `tests/Feature/WebhookDeliveryBodyTest.php` written and seen failing before the code.
- [x] Frontend strings added to `resources/js/locales/en.json` (English baseline).
- [x] `resources/js` changed, so `npm run build` was run and `public/build` is committed in its own commit.
- [x] Mobile check: the table is wrapped in a horizontal scroll area, so it fits at 360px; dark mode classes used. Not checked in a browser (see Result).
- [x] Commit by file name; no `git add -A` over the source tree.
- [ ] No deploy to wa.esystematics.com (needs approval; see Result).

## Skills (REQUIRED)
- [x] superpowers:test-driven-development — tests written first; the first run failed as expected, and the failing test found the double-encoding bug.
- [x] superpowers:verification-before-completion — test, lint and static analysis output quoted in Result.
- [ ] superpowers:brainstorming — not run as a separate design step: the user chose the option ("add it to whatsmine") and the display follows the existing Deliveries page.

## Prerequisites
- [x] Tasks: none
- [ ] Needed from user: deploy approval, and the isinternet receiver change (separate repo).

## Blocked by
Deploy approval.

## References
- Plan: `tasks/plans/PLAN-000-general/PLAN.md` · Phase: `tasks/plans/PLAN-000-general/phase-01-ongoing.md` · Intake: `TEMP/intake-2026-10-12-2050.md`
- Logic doc: `app/logic/webhook-deliveries.md`

## Steps
- [x] Write the failing tests (exact body and headers stored; Deliveries page passes body and response)
- [x] Run them and see them fail (body null; property missing)
- [x] Add the migration, model fields, job change and page change
- [x] Run the tests: found the double-encoding bug; fixed it; all pass
- [x] ESLint on the page and PHPStan on the job and model
- [x] Build the front end and commit `public/build` separately
- [x] Write the logic doc

## Acceptance criteria
- [x] Each delivery row stores the exact body that was signed and sent (test: stored body equals the recorded wire body)
- [x] The stored headers include `X-Webhook-Event` and `X-Webhook-Signature`, and never the endpoint secret (test)
- [x] The Deliveries page passes the body, headers and response to the page (test)
- [ ] Checked in a browser at 360px and 1920px, light and dark (not done in this session)
- [ ] Deployed, migrated and confirmed on wa.esystematics.com (not done; needs approval)

## Progress log
- 2026-10-13 00:07 created from intake (time from the date command); work was already under way in this session
- Times for the intermediate steps were not recorded. Order: failing tests, then migration, model, job and page, then the double-encoding fix and all 8 tests passing, then lint, static analysis and the build, then the logic doc
- 2026-10-13 00:08 completed (time from the date command)

## Result
- Changed: `app/Jobs/DispatchWebhookJob.php` (store exact body and headers; send the exact body), `app/Models/WebhookDelivery.php` (fields and casts), `database/migrations/2026_10_13_000001_add_request_fields_to_webhook_deliveries_table.php` (new), `resources/js/Pages/client/Webhooks/Deliveries.jsx` (details per row), `resources/js/locales/en.json` (7 strings), `tests/Feature/WebhookDeliveryBodyTest.php` (new), `app/logic/webhook-deliveries.md` (new), `app/logic/README.md` (one row), `public/build` (rebuilt).
- Tests: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='WebhookDeliveryBodyTest|WebhookEndpointTest'` → `OK (8 tests, 32 assertions)`.
- Lint and static analysis: `npx eslint` on the page printed nothing; PHPStan on the job and model → `[OK] No errors`.
- Build: `npm run build` ran in about 1 minute. The output renamed 277 hashed chunk files; that is a full rebuild, not a partial change.
- Found and fixed: the body was double-encoded on the wire, so the signature could never match. Receivers that expected a JSON object will now get one; check each receiver.
- Found, not fixed here: the isinternet receiver checks `X-Signature-256` with `sha256=`, but WhatsMine sends `X-Webhook-Signature` with `t=,v1=`. That is why its deliveries return 401.
- Not done: browser check at 360px and 1920px; deploy and migration on wa.esystematics.com; the isinternet receiver change.
- Task ID collision noticed: another session has `T-0006` and `T-0007` in `tasks/working`. This record is T-0008, because IDs are not reused.
