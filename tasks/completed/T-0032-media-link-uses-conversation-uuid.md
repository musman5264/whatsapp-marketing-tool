---
id: T-0032
title: Login media link is built from the conversation id, not its uuid: every new WhatsApp media preview returns 404 (5e7137e)
status: completed
priority: high
type: bug
plan: tasks/plans/PLAN-002-private-media/PLAN.md
phase: tasks/plans/PLAN-002-private-media/phase-01-private-media.md
depends_on: []
source: TEMP/intake-2026-10-13-1747.md
created: 2026-10-13 18:23
started: 2026-10-13 18:23
updated: 2026-10-14 11:58
completed: 2026-10-14 11:58
---

# T-0032: The login media link is built from the conversation id, not its uuid

## What the user asked
> "complete them with multi agents for each tasks or 2 agents for each task" (the private-media work, T-0028).

Found by the T-0031 test author while fixing its fixture. Not reported by the user.

## What this means
`WahaMediaStore::authenticatedUrl()` (`app/Modules/WhatsappWeb/Services/WahaMediaStore.php`, line 165) built the login link with `'conversation' => $message->conversation_id`, which is the integer id. The route is keyed on the conversation's uuid (`Conversation::getRouteKeyName()` returns `'uuid'`, `app/Modules/Shared/Models/Conversation.php` line 77-80). The integer fails route binding, so the link returned 404 before the controller ran.

After this task, the link is built with the conversation model (its uuid), so it opens the file for a logged-in user.

## Root cause (phase 1-2, verified by reading and by the T-0031 test author's diagnostic)
- `authenticatedUrl()` passed the integer id. `Route` implicit binding looks up `Conversation` by uuid, so it throws `ModelNotFoundException` (`ImplicitRouteBinding.php:61`). The request returns 404.
- Callers that store this link as `preview_url`:
  - `WahaMediaStore::store()` (line 124): every newly received WhatsApp Web photo or file. The inbox shows `preview_url` first (`resources/js/Pages/Inbox/Show.jsx` line 299), so new inbound photos would be broken after 5e7137e is deployed.
  - `InboxController::serveMedia()` (line 582): files stored lazily.
  - `MakePrivateMediaCommand::rewriteLinks()` (T-0029, committed in 7d8c1c0): the migration rewrites old links with this method.
- The existing tests did not catch it: `MakePrivateMediaCommandTest::loginUrl()` built the expected URL with the same integer, so it matched the buggy output.

## Fix
`authenticatedUrl(Message $message)` passes the conversation model:
`route('client.inbox.message-media', ['conversation' => <Conversation model>, 'message' => $message->id])`, with the model loaded from `$message->conversation` (`loadMissing`). The route then uses the uuid.

Then `MakePrivateMediaCommandTest::loginUrl()` uses the same model form, so that test checks the correct URL.

## Rules from CLAUDE.md (REQUIRED)
- [x] Root cause before the fix; a failing test reproduces it (`superpowers:systematic-debugging`, `superpowers:test-driven-development`).
- [x] Tests first: the new test is seen to fail on the current code (404).
- [x] Never run two test processes at once: agents do not run phpunit except when the main thread says so.
- [x] Never `git add -A`; agents never commit; the main thread commits by file name.
- [x] Do not change production behaviour beyond the link (no change to the route, the controller or the policy).

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/private-media.md` (login route section), `app/logic/waha-sync-and-labels.md` (media lines 14-18).
- Updated: `app/logic/private-media.md` (the login link uses the conversation uuid; the route section says `{conversation}` is the uuid).

## Skills (REQUIRED)
- [x] superpowers:systematic-debugging — phases 1-2 by reading `authenticatedUrl()`, the route key and the callers; confirmed by the T-0031 diagnostic
- [x] superpowers:test-driven-development — the test author wrote the red test first
- [x] superpowers:verification-before-completion — green run and PHPStan below

## Prerequisites
- [x] Tasks: none. 5e7137e and 7d8c1c0 are on main.

## Blocked by
(not blocked)

## References
- Main instructions: `CLAUDE.md`
- Plan: `tasks/plans/PLAN-002-private-media/PLAN.md` · Phase: `tasks/plans/PLAN-002-private-media/phase-01-private-media.md` · Intake: `TEMP/intake-2026-10-13-1747.md`
- Related files: `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` (`authenticatedUrl`, `store`), `app/Modules/Shared/Models/Conversation.php` (`getRouteKeyName`), `app/Modules/Inbox/routes/web.php` (line 18), `tests/Feature/WhatsappWeb/MakePrivateMediaCommandTest.php` (`loginUrl`), `tests/Feature/WhatsappWeb/AuthenticatedMediaUrlTest.php`, `tests/Feature/Inbox/WahaMediaServeTest.php`

## Interfaces
- Consumes: `Message::conversation()`, route `client.inbox.message-media` (parameters `conversation` as Conversation model, `message` as id).
- Produces: `WahaMediaStore::authenticatedUrl(Message): string` returns a working path for the login route.

## Steps (two agents, non-overlapping files)
Agent A — test author. Owns `tests/Feature/WhatsappWeb/AuthenticatedMediaUrlTest.php` (new).
- [x] Write a failing test: a WhatsApp Web inbound photo stored on the private disk, then `GET authenticatedUrl($message)` as a logged-in user returns 200 with the file's bytes. On the current code this returned 404.
- [x] Add a test that the URL contains the conversation uuid, not its integer id.
- [x] Did not change `MakePrivateMediaCommandTest` (the implementer does that).

Agent B — implementer. Owns `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` (only `authenticatedUrl()`), `tests/Feature/WhatsappWeb/MakePrivateMediaCommandTest.php` (only the `loginUrl()` helper), and `app/logic/private-media.md`.
- [x] Started after the main thread confirmed the red run.
- [x] Fixed `authenticatedUrl()` to pass the conversation model. Updated the `loginUrl()` helper the same way.
- [x] Updated `app/logic/private-media.md`.
- Note: this agent stopped on the weekly usage limit before it reported. The main thread checked its edits (the diff touches only the lines above) and ran the tests itself.

Main thread:
- [x] Red run (seen by the T-0031 test author at 18:23): tests 1 and 2 failed, 404 and the integer-id URL.
- [x] Green run: `AuthenticatedMediaUrlTest`, `MakePrivateMediaCommandTest`, `WahaMediaServeTest`, `PrivateMediaTest`, `WebhookMediaLinkTest`.
- [x] phpstan on the changed PHP file (`WahaMediaStore.php`). Read the diff. Commit by file name, push.

## Acceptance criteria
- [x] `authenticatedUrl()` returns a link with the conversation uuid. Checked by a test.
- [x] `GET authenticatedUrl($message)` as a logged-in user returns 200 with the file's bytes. Checked by a test (red on the old code with 404).
- [x] The T-0029 migration tests still pass with the corrected link. Checked by `MakePrivateMediaCommandTest`.
- [x] `WahaMediaServeTest` and `PrivateMediaTest` still pass. Checked by the main thread.
- [x] phpstan: no new errors on the changed files (`WahaMediaStore.php`: no errors).

## Progress log
- 2026-10-13 18:23 created. Found by the T-0031 test author; verified by reading `authenticatedUrl()`, `Conversation::getRouteKeyName()` and the three callers.
- 2026-10-14 the main thread checked the implementer's edits: `authenticatedUrl()` uses `loadMissing('conversation')` and passes the model; `loginUrl()` passes `$this->conversation`. Nothing else changed.
- 2026-10-14 edge case checked: `messages.conversation_id` is NOT NULL and has no foreign key (`app/Modules/Shared/database/migrations/2026_04_30_000102_create_conversations_messages_table.php`). A dangling id would make `route()` throw. Inbound `store()` runs only after its conversation is loaded, and the migration command catches the error per file (the public copy is kept and logged). Not guarded further; recorded here as a known edge.
- 2026-10-14 the first combined run errored with `Table 'wm3_test.migrations' doesn't exist`. A PHPUnit process this session did not start (`WebhookMediaLinkTest`) was using the same test database at the same time. It had exited when checked. Reran alone: green.
- 2026-10-14 green: `OK (36 tests, 143 assertions)`. PHPStan on `WahaMediaStore.php`: no errors. Comment in `AuthenticatedMediaUrlTest` updated (it still said "expected to FAIL").

## Result
Done on 2026-10-14. The link carries the conversation's uuid, and the login route serves the file. Verified by the green run above and PHPStan. Not verified live: the live site's links change only after the user's deploy (T-0028 steps).
