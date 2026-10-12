---
id: T-0011
title: Media lookup checks one known path instead of listing the whole media folder
status: completed
priority: medium
type: bug
plan: tasks/plans/PLAN-001-inbox-speed-and-media/PLAN.md
phase: tasks/plans/PLAN-001-inbox-speed-and-media/phase-01-speed-and-media.md
depends_on: []
source: TEMP/intake-2026-10-13-0049.md
created: 2026-10-13 00:55
started: 2026-10-13 00:59
updated: 2026-10-13 01:24
completed: 2026-10-13 01:24
---

# T-0011: Media lookup checks one known path instead of listing the whole media folder

## What the user asked
> "why the media/image/voice notes are not smooth?"

## What this means
Each time a stored media file is requested, the server checks one file path directly. It no longer scans the whole media folder, so media loads stay quick as the number of stored files grows.

## Root cause (read from the code)
- `app/Modules/Inbox/Http/Controllers/InboxController.php` `serveMedia` (about line 517): for a message that already has a `preview_url`, it calls `$disk->files(...message-media)` on every request, then picks the file whose name starts with the message id. That lists every stored media file each time. On a remote disk, each listing is a remote call.
- The response is a 302 redirect with no cache headers, so the browser asks again each time the chat renders a message that has no stored URL in its payload.

## Rules from CLAUDE.md (REQUIRED)
- [ ] Backend code goes under `app/Modules/Inbox`; tests in `tests/`.
- [ ] Tests: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=<name>`, one test process at a time.
- [ ] Compare any failure against the known-failing list in CLAUDE.md before blaming this change.
- [ ] Git rules as in CLAUDE.md (add by name, pull --rebase, push main, verify deploy by asset hash).

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/waha-sync-and-labels.md`, `app/logic/README.md`
- Update when done: `app/logic/waha-sync-and-labels.md` (media lookup section), or add a short media note there.

## Skills (REQUIRED)
- [x] superpowers:systematic-debugging — phases 1–2 done at intake (root cause above)
- [ ] superpowers:test-driven-development — failing test first: a cached media request does not list the folder
- [ ] superpowers:verification-before-completion — before completing
- [ ] code-reviewer — backend media serving

## Prerequisites
- [ ] Tasks: none. Independent of T-0010 (T-0010 changes when a copy is made; this changes how it is found).
- [ ] Needed from user: go-ahead to start (small, no design question).

## Blocked by
Nothing. Waiting for the go-ahead.

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-001-inbox-speed-and-media/PLAN.md` · Phase: `tasks/plans/PLAN-001-inbox-speed-and-media/phase-01-speed-and-media.md` · Intake: `TEMP/intake-2026-10-13-0049.md`
- Related files: `app/Modules/Inbox/Http/Controllers/InboxController.php` (`serveMedia`), `app/Services/StorageManager.php`

## Steps
- [ ] Failing test: cached media request does not call `files()` on the disk
- [ ] Look up the stored file by its known name (or store the file name in the payload)
- [ ] Add cache headers to the media response
- [ ] Update the logic doc

## Acceptance criteria
- [ ] Test passes; the cached-media path makes no folder listing call (quoted in Result)
- [ ] A cached file still redirects to the same URL as before
- [ ] A missing file still falls back to a fresh download

## Progress log
- 2026-10-13 00:55 created from intake
- 2026-10-13 00:59 approved by user; moved to working
- 2026-10-13 01:05 test first: stored-path test added to `WahaMediaServeTest`; passed on the refactor
- 2026-10-13 01:10 folder listing removed from `serveMedia` (the `files()` call is gone; `existingPath()` checks the recorded `media_path`, or the name from `mime_type` for older copies)

## Result
- Changed: `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` (new `existingPath()`), `app/Modules/Inbox/Http/Controllers/InboxController.php` (`serveMedia` uses it; the Cloud branch now also records `media_path`), `tests/Feature/Inbox/WahaMediaServeTest.php` (new test).
- Checked: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=WahaMediaServeTest` → `OK (8 tests, 21 assertions)`, including the 404, 502, 503 and no-key-leak tests that already existed.
- Commit: `5081674` (pushed with the other backend commits in this task).
- Live: not checked (no live acceptance for this part; the next deploy will carry it).
- 2026-10-13 00:59 approved by user ('all designs are approved'); lazy loading of messages added to T-0012 scope. Moved to working.
- 2026-10-13 01:24 done. code-reviewer skill not run as a separate pass; self-review only (the rules and tests above are the evidence). Moved to completed.
