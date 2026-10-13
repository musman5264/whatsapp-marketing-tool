---
id: T-0029
title: Move old public chat media to the private disk with media:make-private (dry run first)
status: working
priority: high
type: security
plan: tasks/plans/PLAN-002-private-media/PLAN.md
phase: tasks/plans/PLAN-002-private-media/phase-01-private-media.md
depends_on: []
source: TEMP/intake-2026-10-13-1747.md
created: 2026-10-13 17:54
started: 2026-10-13 17:55
updated: 2026-10-13 17:55
completed:
---

# T-0029: Move old public chat media to the private disk with media:make-private (dry run first)

## What the user asked
> "Old photos are still on the public link until I write the command that moves them. They keep working, but they stay public until then."

> "complete them with multi agents for each tasks or 2 agents for each task"

## What this means
`php artisan media:make-private` lists every file under `message-media/` on the public disk and shows what it would move. Without `--apply` it changes nothing. With `--apply` it:
1. copies each file to the private (`local`) disk and checks the copy. The name: a file that a message links to goes to `message-media/{messageId}.{ext}`, so the lookup works (this matters for outgoing images, which were saved under random names; see T-0031). A file no message links to (an orphan) keeps its own relative path;
2. rewrites that message's `preview_url` and `link` from `/storage/message-media/...` to the login route, and sets `media_path` to the new path;
3. deletes the public copy only after step 2 succeeded.

Running `--apply` on live data is for the user (see T-0028).

## Rules from CLAUDE.md (REQUIRED)
- [ ] Ask before moving or deleting stored data: the command is a dry run by default; Claude does not run `--apply` on live data.
- [ ] Tests first: the failing tests exist and were seen to fail before the command was written (`superpowers:test-driven-development`).
- [ ] Module layout: the command lives in `app/Modules/WhatsappWeb/Console/` and is registered in `WhatsappWebServiceProvider`.
- [ ] Never run two test processes at once: agents do not run phpunit; the main thread runs it.
- [ ] Never `git add -A`; agents never commit; the main thread commits by file name.
- [ ] No secrets in repo files.
- [ ] Not added to `app/Modules/Deployment/Services/CommandAllowlist.php` (moves and deletes files, so it needs the second key). `public/deploy.php` unchanged.

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/waha-sync-and-labels.md` (media section, lines 14-18), `app/logic/deployment-commands.md`, `tasks/plans/PLAN-002-private-media/PLAN.md` (Decisions).
- Update when done: `app/logic/private-media.md` (created by this task), `app/logic/deployment-commands.md` (note that the command is outside the no-key allowlist), `app/logic/README.md` (index row).

## Skills (REQUIRED)
- [x] superpowers:brainstorming — design recorded in `tasks/plans/PLAN-002-private-media/PLAN.md` (classified bounded-to-architectural; the user delegated the webhook decision, the command design is recorded there)
- [x] superpowers:writing-plans — steps below
- [x] superpowers:test-driven-development — the test author writes red tests first; the implementer writes the code after the red run
- [x] superpowers:dispatching-parallel-agents — two agents for this task, non-overlapping files (invoked 17:55, before spawning)
- [ ] superpowers:verification-before-completion — before done
- [ ] code-reviewer — backend security audit of the command (it moves files and rewrites links)

## Prerequisites
- [x] Tasks: none. 5e7137e is committed on main.
- [x] Environment: MySQL test database works (baseline: 26 tests passed on 2026-10-13 17:53).

## Blocked by
(not blocked)

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-002-private-media/PLAN.md` · Phase: `tasks/plans/PLAN-002-private-media/phase-01-private-media.md` · Intake: `TEMP/intake-2026-10-13-1747.md`
- Related files: `app/Modules/WhatsappWeb/Services/WahaMediaStore.php`, `app/Modules/WhatsappWeb/WhatsappWebServiceProvider.php`, `app/Modules/Shared/Models/Message.php`, `app/Services/StorageManager.php`, `config/filesystems.php`, `tests/Feature/Inbox/PrivateMediaTest.php`

## Interfaces
- Consumes:
  - `WahaMediaStore::authenticatedUrl(Message $message): string` (route `client.inbox.message-media`, parameters `conversation`, `message`).
  - `WahaMediaStore::existingPath(Message $message, array $payload): ?string` (checks the local disk only).
  - Laravel disks `public` (old copies) and `local` (private, `storage/app/private`).
  - `StorageManager::diskName()`: the command stops with a message unless it returns `public`.
- Produces:
  - Class `App\Modules\WhatsappWeb\Console\MakePrivateMediaCommand`, signature `media:make-private {--apply : Move the files and rewrite the links. Without it, nothing changes.}`.
  - Exit code 0 when everything succeeded or there was nothing to do; 1 when there was any error or conflict.

## Steps (two agents, non-overlapping files)
Agent A — test author. Owns only `tests/Feature/WhatsappWeb/MakePrivateMediaCommandTest.php`.
- [x] Failing tests written: 10 tests in `tests/Feature/WhatsappWeb/MakePrivateMediaCommandTest.php`. Red run on 2026-10-13 18:04: all 10 fail with `CommandNotFoundException: The command "media:make-private" does not exist`.
- The file uses `Storage::fake('public')` and `Storage::fake('local')` (not the real disks), so real storage is never touched. Messages are created with `Message::create` (there is no `MessageFactory`).
- Tests 9 and 10 (hash-named outgoing photo linked by a message; orphan hash-named file) were added on 2026-10-13 18:02, red as above.

Agent B — implementer. Owns `app/Modules/WhatsappWeb/Console/MakePrivateMediaCommand.php` (new), the command registration line in `app/Modules/WhatsappWeb/WhatsappWebServiceProvider.php`, `app/logic/private-media.md` (new), and the note in `app/logic/deployment-commands.md`.
- [ ] Implement the command so the tests pass. Behaviour:
  1. List `Storage::disk('public')->allFiles('message-media')`. For each file, find the message it belongs to: first by the id in its name (`message-media/{id}.{ext}`), otherwise by a message whose `payload` `preview_url` or `link` ends with that file's path under `/storage/message-media/` (outgoing images use random names, so this is the common case). A file with no message is an orphan.
  2. Target name: a file with a message goes to `message-media/{messageId}.{ext}` (ext from the public file name); an orphan keeps its own relative path.
  3. Dry run prints one line per planned move (from, to) and per message link rewrite, plus totals (files, messages, conflicts). It writes nothing.
  4. `--apply`: copy to `local` at the target path, and compare sizes. If the target exists with the same size, skip the copy. If it exists with a different size, record a conflict and leave the public copy alone.
  5. Rewrite the message payload: `preview_url` and `link` that point at `/storage/message-media/...` become `WahaMediaStore::authenticatedUrl($message)`; set `media_path` to the target path. Other keys are unchanged. Save with Eloquent (`save`/`update`) inside a DB transaction per message, so a model event can make it fail.
  6. Delete the public copy only after step 5 succeeded for that file.
  7. Print the summary (moved, rewritten, conflicts, errors). Exit 1 if there were any errors or conflicts.
- [ ] Write `app/logic/private-media.md`: what is private, the serve route (`/private-files`, signed, 404 in production without a signature), the login route, WAHA's 15-minute link, the webhook link (link to `webhook-deliveries.md`), and the command with its dry run and `--apply`. Mark anything not yet verified live as "unverified".
- [ ] Add the note to `app/logic/deployment-commands.md`: `media:make-private` is outside the no-key allowlist; run it with the second key (Deployment Run box) or from the cPanel terminal.

Main thread:
- [ ] Run `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=MakePrivateMediaCommandTest` before and after the implementation (red, then green).
- [ ] Run `vendor/bin/phpstan analyse app/Modules/WhatsappWeb/Console/MakePrivateMediaCommand.php app/Modules/WhatsappWeb/WhatsappWebServiceProvider.php`.
- [ ] Read every diff. Add the index row to `app/logic/README.md`. Commit by file name and push.

## Acceptance criteria
- [ ] Dry run (no `--apply`): lists the files and messages it would change and changes nothing (public disk, local disk and messages unchanged). Checked by a test.
- [ ] `--apply`: every `message-media/*` file is on the local disk at the same path with the same bytes, and gone from the public disk. Checked by a test.
- [ ] `preview_url` and `link` pointing at `/storage/message-media/...` become the login route; `media_path` is set; other payload keys are unchanged. Checked by a test.
- [ ] After `--apply`, `WahaMediaStore::existingPath()` finds the file for that message. Checked by a test.
- [ ] Running `--apply` twice: the second run moves nothing and exits 0. Checked by a test.
- [ ] A file on the local disk with a different size at the target path is not overwritten; the public copy stays; the run reports a conflict and exits 1. Checked by a test.
- [ ] Files outside `message-media/` on the public disk (for example `logos/...`) are not touched. Checked by a test.
- [ ] If the message update throws, the public copy is kept and the run reports an error. Checked by a test.
- [ ] Command is not in `CommandAllowlist`; `public/deploy.php` is unchanged. Checked by `git diff --stat`.
- [ ] `app/logic/private-media.md` written; index row in `app/logic/README.md`; note in `deployment-commands.md`.
- [ ] phpunit on `MakePrivateMediaCommandTest`: OK. phpstan on the changed PHP files: no new errors.

## Progress log
- 2026-10-13 17:54 created from intake `TEMP/intake-2026-10-13-1747.md` and `PLAN-002`.
- 2026-10-13 17:55 started. Two agents: test author (red tests) and implementer (command and docs).

## Result
(not finished)
