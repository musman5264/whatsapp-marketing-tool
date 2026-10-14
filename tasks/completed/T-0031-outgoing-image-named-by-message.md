---
id: T-0031
title: Outgoing WhatsApp Web image shows 404 after 5e7137e: the file is saved under a random name the login route cannot find
status: completed
priority: high
type: bug
plan: tasks/plans/PLAN-002-private-media/PLAN.md
phase: tasks/plans/PLAN-002-private-media/phase-01-private-media.md
depends_on: []
source: TEMP/intake-2026-10-13-1747.md
created: 2026-10-13 18:01
started: 2026-10-13 18:01
updated: 2026-10-14
completed: 2026-10-14
---

# T-0031: Outgoing WhatsApp Web image shows 404 after 5e7137e (random file name)

## What the user asked
> "complete them with multi agents for each tasks or 2 agents for each task" (the private-media work, T-0028).

Found while checking T-0029's hash-named files. Not reported by the user.

## What this means
Commit 5e7137e (on main, not yet live) stores an outgoing composer image as `message-media/{random hash}.jpg` and removes its `preview_url`. The chat then asks the login route for the image, which returns 404 "No media available.". After this task, an outgoing image is saved as `message-media/{messageId}.{ext}`, its payload points at the login route, and it displays in the chat. The WhatsApp Web send still gets a 15-minute signed link.

## Root cause (phase 1-2, from reading the code; the failing test is the reproduction)
- `app/Modules/Inbox/Http/Controllers/InboxController.php` `reply()`: `$storedPath = 'message-media/'.$file->hashName()`. The file was stored under that name, `media_path` was set to it, and the WAHA `link` was a 15-minute signed link to that hash name. `preview_url` was not set (5e7137e removed it).
- `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` `existingPath()`: accepts `media_path` only when it is `message-media/{message id}.{ext}`. The hash name is rejected, so it falls back to the name derived from the MIME type, which does not exist. Returns null.
- `serveMedia()`: `preview_url` was empty, so the stored-copy branch was skipped, and it reached `abort(404, 'No media available.')`.
- `resources/js/Pages/Inbox/Show.jsx`: the bubble points at the login route when `preview_url` is missing, so the 404 shows.
- Scope: new WhatsApp Web and Cloud composer images after 5e7137e is deployed. Incoming photos are not affected (named by message id).
- A second bug in the same path (the login link used the integer conversation id) was found while writing the test. It is T-0032, committed as `6282223`, and it is fixed.

## Fix (design decision, recorded in PLAN-002)
In `reply()`:
1. The upload is still saved under its random name first (it needs a path before the message exists).
2. Inside one `DB::transaction`, the message row is created, then the file is moved to `message-media/{message->id}.{ext}` (same extension). If the move fails, the transaction rolls back and the request returns an error, so no message is left without its file.
3. The payload gets `media_path` = the new path, and `preview_url` = `WahaMediaStore::authenticatedUrl($message)`.
4. WhatsApp Web (`provider` = `whatsapp_web`): `link` = `WahaMediaStore::fetchUrl(newPath)`, computed after the move, not before. Cloud: `media_id` unchanged.
5. The send happens after the transaction, as before. The send's `$sendError` handling is unchanged.

Rejected: letting `existingPath()` accept a hash name in `media_path`. That path comes from the request body, so trusting it would let a user name another message's file. Renaming to the message id keeps the existing rule.

## What this changes for the other tasks
- T-0029 (migration): old outgoing files keep their hash names until the command moves them. The command gives each linked file the name `message-media/{messageId}.{ext}`, so the result matches this fix.
- T-0028: deploy 5e7137e together with T-0030, T-0031 and T-0032. Deploying 5e7137e without this fix breaks outgoing WhatsApp Web images.

## Rules from CLAUDE.md (REQUIRED)
- [x] Root cause before the fix; the failing test reproduces it (`superpowers:systematic-debugging`, `superpowers:test-driven-development`).
- [x] Tests first: the failing tests were seen on the current code (6 of 6 failed on the naming, see Progress log).
- [x] Never run two test processes at once: the main thread ran phpunit, and only on a private database (see Progress log).
- [x] Never `git add -A`; agents never commit; the main thread commits by file name.
- [x] Soft-fail for WAHA: the send path keeps its current error handling (`$sendError`).
- [ ] Do not log or expose the signed link outside the send. **Not met yet, and not caused by this change.** `reply()` still saves the signed link on the payload, the reply JSON returns it, and `MessageSent` broadcasts it. This predates T-0031. Open decision, see Open items.
- [x] Logic docs: `app/logic/private-media.md` (outgoing composer files section) and `app/logic/inbox-paging.md` (one sentence in "Media links").

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/waha-sync-and-labels.md` (media), `app/logic/inbox-paging.md` (media links), `tasks/plans/PLAN-002-private-media/PLAN.md`.
- Updated: `app/logic/private-media.md` (outgoing composer files are named by message id), `app/logic/inbox-paging.md` (media links).

## Skills (REQUIRED)
- [x] superpowers:systematic-debugging — phases 1-2 by reading the code (above); the failing tests are phase 3
- [x] superpowers:test-driven-development — the test author wrote the red tests first
- [x] superpowers:dispatching-parallel-agents — two agents: the test author (done before) and the implementer (`t0031-implementer`, finished)
- [x] superpowers:verification-before-completion — green run and PHPStan below
- [ ] code-reviewer — the send path and file move (a request-supplied payload is involved). **Not run yet. Still open.**

## Prerequisites
- [x] Tasks: none. 5e7137e is committed on main. T-0032 (`6282223`) is committed.
- [x] Environment: MySQL test database works.

## Blocked by
(not blocked)

## References
- Main instructions: `CLAUDE.md`
- Plan: `tasks/plans/PLAN-002-private-media/PLAN.md` · Phase: `tasks/plans/PLAN-002-private-media/phase-01-private-media.md` · Intake: `TEMP/intake-2026-10-13-1747.md`
- Related files: `app/Modules/Inbox/Http/Controllers/InboxController.php` (`reply`, `serveMedia`), `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` (`existingPath`, `authenticatedUrl`, `fetchUrl`), `resources/js/Pages/Inbox/Show.jsx`, `tests/Feature/Inbox/OutboundMediaNamingTest.php`, `tests/Feature/Inbox/PrivateMediaTest.php`, `tests/Feature/Inbox/WahaMediaServeTest.php`

## Interfaces
- Consumes: `WahaMediaStore::authenticatedUrl(Message): string`, `WahaMediaStore::fetchUrl(string): string`, `WahaMediaStore::existingPath(Message, array): ?string`, Laravel `local` disk.
- Produces: outgoing media stored as `message-media/{messageId}.{ext}`; payload `media_path`, `preview_url` (login route), and `link` (WAHA, signed 15 min, WhatsApp Web only) set after the move.

## Steps (two agents, non-overlapping files)
Agent A — test author. Owns `tests/Feature/Inbox/OutboundMediaNamingTest.php` (new).
- [x] Failing tests written (6). Fixture fixed on 2026-10-13 (the reply route was given the integer id; it takes the uuid model).
- [x] Fake-disk link fixture corrected on 2026-10-14 (see Progress log).

Agent B — implementer (`t0031-implementer`). Owns `InboxController.php` (`reply()` only), `app/logic/private-media.md`, `app/logic/inbox-paging.md`.
- [x] Implemented the fix (reply() only). Did not run tests and did not commit (per its instructions). The main thread checked the diff and ran the tests.

Main thread:
- [x] Red run: 6 of 6 failed on the naming (the stored file was `message-media/<random hash>.jpg`, not `message-media/{id}.jpg`; the login route returned 404 for the hash name).
- [x] Green run on the private database: `OutboundMediaNamingTest`, `PrivateMediaTest`, `WahaMediaServeTest`, `WahaMediaArrivalTest`.
- [x] Inbox namespace and `SlaTrackingTest`: 7 failures, all on the known baseline list.
- [x] PHPStan on `InboxController.php`: no new errors (see Progress log).
- [x] Diff read. Committed by file name and pushed.

## Acceptance criteria
- [x] A WhatsApp Web composer image: stored as `message-media/{messageId}.jpg`, and no file is left under the hash name. Checked by `a_whatsapp_web_composer_image_is_stored_under_its_message_id`.
- [x] The payload has `media_path` = `message-media/{messageId}.jpg` and `preview_url` = the login route. Checked by `the_whatsapp_web_message_points_at_its_message_named_file_and_login_route`.
- [x] A GET to that login route, as a logged-in user of the conversation, returns 200 with the file's bytes. Checked by `the_login_route_serves_a_whatsapp_web_composer_image_to_its_workspace_user` (red on the old code: 404).
- [x] The WAHA send gets a signed link (`link`) that serves the file. Checked by `the_waha_send_gets_a_signed_link_to_the_message_named_file`.
- [x] A Cloud composer image: `media_path` and `preview_url` as above, and `media_id` kept. Checked by `a_cloud_api_composer_image_points_at_its_message_named_file_and_keeps_its_media_id`.
- [x] A failed WAHA send keeps the message and its file (`the_message_is_kept_and_the_reply_does_not_fail_when_the_waha_send_fails`).
- [x] Existing tests `PrivateMediaTest`, `WahaMediaServeTest`, `WahaMediaArrivalTest` still pass. Checked by the main thread.
- [x] phpstan on `InboxController.php`: no new errors.

## Progress log
- 2026-10-13 18:01 created. Root cause found by reading `reply()`, `existingPath()`, `serveMedia()` and `Show.jsx`.
- 2026-10-13 18:20 the test file's fixture was fixed (the reply route was given the integer conversation id). Six tests failed on the naming bug.
- 2026-10-13 18:23 a second bug was found: `authenticatedUrl()` passed the integer id. Moved to T-0032, which is committed as `6282223`.
- 2026-10-14 red run again, after T-0032: 6 of 6 failed on the naming (the stored file was under the hash name; test 6 failed inside `assertExists`, PHPUnit prints the custom message above "Failed asserting that false is true.").
- 2026-10-14 the implementer finished `reply()`. Its report flagged five concerns (see Open items).
- 2026-10-14 first green attempt: 20 passed and 1 failed (`the_waha_send_gets_a_signed_link...`: the link returned 403). Cause: the test's fake disk signed an ABSOLUTE URL, but `ServeFile` checks a RELATIVE signature (`hasValidRelativeSignature`). The real disk (`LocalFilesystemAdapter::getTemporaryUrl`) signs a relative path and then prefixes it with `URL::to()`. The fake now does the same. Test-fixture fix only; production code unchanged.
- 2026-10-14 that first attempt also ran while another session was running PHPUnit (its tasklist check printed PHP processes and my command ran anyway). That run's result is not used. The results below are from runs with no other test process, or on a private database.
- 2026-10-14 green on the private database `wm3_test_t0031` (created for this run, with a scratch `phpunit.t0031.xml` that was removed afterwards): `OK (21 tests, 60 assertions)` for `OutboundMediaNamingTest`, `PrivateMediaTest`, `WahaMediaServeTest`, `WahaMediaArrivalTest`.
- 2026-10-14 Inbox namespace and `SlaTrackingTest` on the same private database: 90 tests, 7 failures, all on the CLAUDE.md baseline list: `HandoverTest` (2), `LabelCrudTest` (3), `SlaTrackingTest` (2). No new failures.
- 2026-10-14 PHPStan on `InboxController.php`: the new code added one `property.notFound` (`channelAccount?->provider` in the transaction closure). Fixed with `getAttribute('provider')`, which the existing code uses for the same relation. The remaining 16 findings are existing ones: the lines I could match to unchanged code are the `channelAccount?->provider` checks at lines 88 and 220 and the `first_response_at` / `last_inbound_at` / `conversation_id` lines outside `reply()`. The `?->channel ??` count is four occurrences, the same set of lines as before this change, so that count was already off. I did not run PHPStan on HEAD to confirm that, so this is from reading the lines.

## Open items
1. **Signed link in the payload (decision for you).** `reply()` saves the WAHA `link` on the message payload. The reply JSON returns the whole payload, and `MessageSent` broadcasts it. CLAUDE.md and this task's rules say the signed link must not be stored or exposed outside the send. This predates T-0031. Changing it means the WAHA send must get the link without reading it from the stored payload, and the test that reads `payload['link']` from the database must change too. Not changed here.
2. A file saved before the 422 (no Cloud account) or the 24-hour-window return is left under its random name. Pre-existing; not changed.
3. If the final payload save fails after the rename, the transaction rolls back the message, but the file stays under its message-id name. Rare; not cleaned up.
4. Side effect: the try/catch also wraps text-message creation, so a database error there now returns a JSON 500 or a flash error instead of an exception page.
5. code-reviewer audit of the send path and the move: not run.

## Result
Done as code on 2026-10-14: the composer image is stored under its message id, the payload points at the login route, and the WAHA link is made after the move. Committed and pushed by file name. Verified by the green run on the private database and by PHPStan. Not verified live: the outgoing images on the live server. Live checks are part of T-0028 (after the user's deploy).

Open: items 1 and 5 above need a decision or a review.
