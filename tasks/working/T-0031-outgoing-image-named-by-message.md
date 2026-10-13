---
id: T-0031
title: Outgoing WhatsApp Web image shows 404 after 5e7137e: the file is saved under a random name the login route cannot find
status: working
priority: high
type: bug
plan: tasks/plans/PLAN-002-private-media/PLAN.md
phase: tasks/plans/PLAN-002-private-media/phase-01-private-media.md
depends_on: []
source: TEMP/intake-2026-10-13-1747.md
created: 2026-10-13 18:01
started: 2026-10-13 18:01
updated: 2026-10-13 18:01
completed:
---

# T-0031: Outgoing WhatsApp Web image shows 404 after 5e7137e (random file name)

## What the user asked
> "complete them with multi agents for each tasks or 2 agents for each task" (the private-media work, T-0028).

Found while checking T-0029's hash-named files. Not reported by the user.

## What this means
Commit 5e7137e (on main, not yet live) stores an outgoing composer image as `message-media/{random hash}.jpg` and removes its `preview_url`. The chat then asks the login route for the image, which returns 404 "No media available.". After this task, an outgoing image is saved as `message-media/{messageId}.{ext}`, its payload points at the login route, and it displays in the chat. The WhatsApp Web send still gets a 15-minute signed link.

## Root cause (phase 1-2, from reading the code; the failing test is the reproduction)
- `app/Modules/Inbox/Http/Controllers/InboxController.php` `reply()` (about lines 204-226): `$storedPath = 'message-media/'.$file->hashName()`. The file is stored under that name, `media_path` is set to it, and the WAHA `link` is a 15-minute signed link to that hash name. `preview_url` is not set (5e7137e removed it).
- `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` `existingPath()`: accepts `media_path` only when it is `message-media/{message id}.{ext}`. The hash name is rejected, so it falls back to the name derived from the MIME type, `message-media/{id}.jpg`, which does not exist. Returns null.
- `serveMedia()` (about lines 528-590): `preview_url` is empty, so the stored-copy branch is skipped. There is no `media_id` for a WhatsApp Web message, and the `$payload[$type]['link']` branch does not match a top-level `link`. It reaches `abort(404, 'No media available.')`.
- `resources/js/Pages/Inbox/Show.jsx` (about lines 299-300): the bubble points at the login route when `preview_url` is missing, so the 404 shows.
- Scope: this affects new WhatsApp Web and Cloud composer images after 5e7137e is deployed. Incoming photos are not affected (named by message id).

## Fix (design decision, recorded in PLAN-002)
In `reply()`, after `Message::create`, inside one `DB::transaction` with the create:
1. Move the stored file from its hash name to `message-media/{message->id}.{ext}` (same extension). If the move fails, the transaction rolls back and the request returns an error, so no message is left without its file.
2. Payload: `media_path` = the new path; `preview_url` = `WahaMediaStore::authenticatedUrl($message)`.
3. WhatsApp Web (`provider` = `whatsapp_web`): `link` = `WahaMediaStore::fetchUrl(newPath)`, computed after the move, not before. Cloud: `media_id` unchanged.
4. Then send, as now.

Rejected: letting `existingPath()` accept a hash name in `media_path`. That path comes from the request body (`$validated['payload']`), so trusting it would let a user name another message's file. Renaming to the message id keeps the existing rule.

## What this changes for the other tasks
- T-0029 (migration): old outgoing files keep their hash names until the command moves them. The command gives each linked file the name `message-media/{messageId}.{ext}`, so the result matches this fix.
- T-0028: deploy 5e7137e together with T-0030 and T-0031. Deploying 5e7137e without this fix breaks outgoing WhatsApp Web images.

## Rules from CLAUDE.md (REQUIRED)
- [ ] Root cause before the fix; the failing test reproduces it (`superpowers:systematic-debugging`, `superpowers:test-driven-development`).
- [ ] Tests first: the failing test is seen on the current code (expected 404 from the login route).
- [ ] Never run two test processes at once: agents do not run phpunit; the main thread does.
- [ ] Never `git add -A`; agents never commit; the main thread commits by file name.
- [ ] Soft-fail for WAHA: the send path keeps its current error handling (`$sendError`).
- [ ] Do not log or expose the signed link outside the send.
- [ ] Logic docs: `app/logic/private-media.md` (T-0029 creates it) gets a line about the outgoing naming, written by the T-0031 implementer in `app/logic/inbox-paging.md` only if it covers media links; otherwise noted in `private-media.md` by T-0029. Check, do not guess.

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/waha-sync-and-labels.md` (media, lines 14-18), `app/logic/inbox-paging.md` (media links, line 20), `tasks/plans/PLAN-002-private-media/PLAN.md`.
- Update when done: `app/logic/private-media.md` (outgoing files are named by message id), `app/logic/inbox-paging.md` if it describes outgoing media links.

## Skills (REQUIRED)
- [x] superpowers:systematic-debugging — phases 1-2 done by reading the code (above); the failing test is phase 3
- [x] superpowers:test-driven-development — the test author writes the red test first
- [ ] superpowers:dispatching-parallel-agents — two agents for this task (to tick when invoked)
- [ ] superpowers:verification-before-completion — before done
- [ ] code-reviewer — the send path and file move (a request-supplied payload is involved)

## Prerequisites
- [x] Tasks: none. 5e7137e is committed on main.
- [x] Environment: MySQL test database works.

## Blocked by
(not blocked)

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-002-private-media/PLAN.md` · Phase: `tasks/plans/PLAN-002-private-media/phase-01-private-media.md` · Intake: `TEMP/intake-2026-10-13-1747.md`
- Related files: `app/Modules/Inbox/Http/Controllers/InboxController.php` (`reply`, `serveMedia`), `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` (`existingPath`, `authenticatedUrl`, `fetchUrl`), `resources/js/Pages/Inbox/Show.jsx`, `tests/Feature/Inbox/PrivateMediaTest.php`, `tests/Feature/Inbox/WahaMediaServeTest.php`

## Interfaces
- Consumes: `WahaMediaStore::authenticatedUrl(Message): string`, `WahaMediaStore::fetchUrl(string): string`, `WahaMediaStore::existingPath(Message, array): ?string`, Laravel `local` disk.
- Produces: outgoing media stored as `message-media/{messageId}.{ext}`; payload `media_path`, `preview_url` (login route), and `link` (WAHA, signed 15 min) set after the move.

## Steps (two agents, non-overlapping files)
Agent A — test author. Owns only `tests/Feature/Inbox/OutboundMediaNamingTest.php` (new).
- [ ] Find the reply route (grep routes/web.php and routes/*.php for `reply`) and how existing tests post a composer attachment (grep tests for `attachment`, e.g. WahaMediaServeTest). Fake the WAHA and Cloud HTTP calls with Http::fake so no network call happens.
- [ ] Write the failing tests listed in Acceptance criteria. Do not run phpunit; the main thread confirms the red run (test 2 must fail with a 404).

Agent B — implementer. Owns `app/Modules/Inbox/Http/Controllers/InboxController.php` (only `reply()`), `app/logic/private-media.md` (outgoing naming section) and `app/logic/inbox-paging.md` if it covers media links.
- [ ] Start only after the red run is confirmed by the main thread.
- [ ] Implement the fix as in "Fix". Keep the Cloud path's `media_id` and the WAHA send's error handling as they are.
- [ ] Update the logic docs.

Main thread:
- [ ] Run `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=OutboundMediaNamingTest` (red, then green), and the existing `PrivateMediaTest`, `WahaMediaServeTest`, `WahaMediaArrivalTest`.
- [ ] Run `vendor/bin/phpstan analyse app/Modules/Inbox/Http/Controllers/InboxController.php`.
- [ ] Read the diff. Commit by file name, push.

## Acceptance criteria
- [ ] A WhatsApp Web composer image: the file is stored as `message-media/{messageId}.jpg` and no file is left under the hash name. Checked by a test.
- [ ] The payload has `media_path` = `message-media/{messageId}.jpg` and `preview_url` = the login route. Checked by a test.
- [ ] A GET to that login route, as a logged-in user of the conversation, returns 200 with the file's bytes. Checked by a test. FAILS on the current code with 404 (the reproduction).
- [ ] The WAHA send gets a signed link (`link`) that serves the file. Checked by a test.
- [ ] A Cloud composer image: `media_path` and `preview_url` as above, and `media_id` kept. Checked by a test.
- [ ] Existing tests `PrivateMediaTest`, `WahaMediaServeTest`, `WahaMediaArrivalTest` still pass. Checked by the main thread.
- [ ] phpstan on `InboxController.php`: no new errors.

## Progress log
- 2026-10-13 18:01 created. Root cause found by reading `reply()`, `existingPath()`, `serveMedia()` and `Show.jsx`. Reproduction test next.

## Result
(not finished)
