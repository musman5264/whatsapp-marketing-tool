---
id: T-0029
title: Move old public chat media to the private disk with media:make-private (dry run first)
status: completed
priority: high
type: security
plan: tasks/plans/PLAN-002-private-media/PLAN.md
phase: tasks/plans/PLAN-002-private-media/phase-01-private-media.md
depends_on: []
source: TEMP/intake-2026-10-13-1747.md
created: 2026-10-13 17:54
started: 2026-10-13 17:55
updated: 2026-10-14
completed: 2026-10-14
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
- [x] Ask before moving or deleting stored data: the command is a dry run by default; Claude did not run `--apply` on live data (only on the test fakes).
- [x] Tests first: the 10 tests were written and seen to fail before the command existed (red run 2026-10-13 18:04: `CommandNotFoundException`).
- [x] Module layout: the command is in `app/Modules/WhatsappWeb/Console/` and registered in `WhatsappWebServiceProvider`.
- [x] Never run two test processes at once: the main thread ran phpunit alone (see Result).
- [x] Never `git add -A`; agents never commit; the main thread commits by file name (commit `7d8c1c0`).
- [x] No secrets in repo files.
- [x] Not added to `app/Modules/Deployment/Services/CommandAllowlist.php`; `public/deploy.php` unchanged (checked: neither file is in commit `7d8c1c0`).

## Logic / design docs (REQUIRED)
- [x] `app/logic/private-media.md` (created by this task; the command section).
- [x] `app/logic/deployment-commands.md` (note: the command is outside the no-key allowlist).
- [x] `app/logic/README.md` (index row).

## Skills (REQUIRED)
- [x] superpowers:brainstorming — design recorded in `tasks/plans/PLAN-002-private-media/PLAN.md` (classified bounded-to-architectural; the user delegated the webhook decision, the command design is recorded there)
- [x] superpowers:writing-plans — steps below
- [x] superpowers:test-driven-development — the test author wrote red tests first; the implementer wrote the code after the red run
- [x] superpowers:dispatching-parallel-agents — two agents for this task, non-overlapping files (invoked 17:55, before spawning)
- [x] superpowers:verification-before-completion — the green run and PHPStan below, on 2026-10-14
- [ ] code-reviewer — backend security audit of the command (it moves files and rewrites links). **Not run yet. Still open.**

## Prerequisites
- [x] Tasks: none. 5e7137e is committed on main.
- [x] Environment: MySQL test database works.

## Blocked by
(not blocked)

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-002-private-media/PLAN.md` · Phase: `tasks/plans/PLAN-002-private-media/phase-01-private-media.md` · Intake: `TEMP/intake-2026-10-13-1747.md`
- Related files: `app/Modules/WhatsappWeb/Console/MakePrivateMediaCommand.php`, `app/Modules/WhatsappWeb/WhatsappWebServiceProvider.php`, `app/Modules/WhatsappWeb/Services/WahaMediaStore.php`, `app/Modules/Shared/Models/Message.php`, `app/Services/StorageManager.php`, `config/filesystems.php`, `tests/Feature/WhatsappWeb/MakePrivateMediaCommandTest.php`

## Interfaces
- Consumes:
  - `WahaMediaStore::authenticatedUrl(Message $message): string` (route `client.inbox.message-media`, parameters `conversation`, `message`). Its conversation argument was fixed by T-0032 (commit `6282223`).
  - `WahaMediaStore::existingPath(Message $message, array $payload): ?string` (checks the local disk only).
  - Laravel disks `public` (old copies) and `local` (private, `storage/app/private`).
  - `StorageManager::diskName()`: the command stops with a message unless it returns `public`.
- Produces:
  - Class `App\Modules\WhatsappWeb\Console\MakePrivateMediaCommand`, signature `media:make-private {--apply : Move the files and rewrite the links. Without it, nothing changes.}`.
  - Exit code 0 when everything succeeded or there was nothing to do; 1 when there was any error or conflict.

## Steps (two agents, non-overlapping files)
Agent A — test author. Owns only `tests/Feature/WhatsappWeb/MakePrivateMediaCommandTest.php`.
- [x] Failing tests written: 10 tests. Red run on 2026-10-13 18:04: all 10 fail with `CommandNotFoundException: The command "media:make-private" does not exist`.
- The file uses `Storage::fake('public')` and `Storage::fake('local')` (not the real disks), so real storage is never touched. Messages are created with `Message::create` (there is no `MessageFactory`).
- Tests 9 and 10 (hash-named outgoing photo linked by a message; orphan hash-named file) were added on 2026-10-13 18:02, red as above.

Agent B — implementer. Owns `app/Modules/WhatsappWeb/Console/MakePrivateMediaCommand.php` (new), the command registration line in `app/Modules/WhatsappWeb/WhatsappWebServiceProvider.php`, `app/logic/private-media.md` (new), and the note in `app/logic/deployment-commands.md`.
- [x] Command implemented; the tests pass (see Result). Behaviour:
  1. List `Storage::disk('public')->allFiles('message-media')`. For each file, find the message it belongs to: first by the id in its name (`message-media/{id}.{ext}`), otherwise by a message whose `payload` `preview_url` or `link` ends with that file's path under `/storage/message-media/`. A file with no message is an orphan.
  2. Target name: a file with a message goes to `message-media/{messageId}.{ext}`; an orphan keeps its own relative path.
  3. Dry run prints one line per planned move and per message link rewrite, plus totals. It writes nothing.
  4. `--apply`: copy to `local` at the target path, and compare sizes. Same size: skip the copy. Different size: record a conflict and leave the public copy alone.
  5. Rewrite the message payload: `preview_url` and `link` that point at `/storage/message-media/...` become the login route; set `media_path`. Other keys are unchanged. Saved inside a DB transaction per message with `lockForUpdate`.
  6. Delete the public copy only after step 5 succeeded for that file.
  7. Print the summary (moved, rewritten, conflicts, errors). Exit 1 if there were any errors or conflicts.
- [x] `app/logic/private-media.md` written.
- [x] Note added to `app/logic/deployment-commands.md`.

Main thread:
- [x] Red then green for `MakePrivateMediaCommandTest` (see Result).
- [x] PHPStan on the command and the provider: no errors.
- [x] Diffs read. Index row in `app/logic/README.md`. Committed by file name (`7d8c1c0`) and pushed.

## Acceptance criteria
- [x] Dry run (no `--apply`): lists the files and messages it would change and changes nothing. Checked by `dry_run_changes_nothing_and_lists_the_file`.
- [x] `--apply`: every `message-media/*` file is on the local disk at the same path with the same bytes, and gone from the public disk. Checked by `apply_copies_the_file_to_the_private_disk_and_removes_the_public_copy`.
- [x] `preview_url` and `link` pointing at `/storage/message-media/...` become the login route; `media_path` is set; other payload keys are unchanged. Checked by `apply_points_the_links_at_the_login_route_and_keeps_the_other_payload_keys`.
- [x] After `--apply`, `WahaMediaStore::existingPath()` finds the file for that message. Checked by `after_apply_the_stored_copy_is_found_for_its_message`.
- [x] Running `--apply` twice: the second run moves nothing and exits 0. Checked by `running_apply_twice_moves_nothing_on_the_second_run`.
- [x] A file on the local disk with a different size at the target path is not overwritten; the public copy stays; the run reports a conflict and exits 1. Checked by `a_different_size_file_already_on_the_private_disk_is_not_overwritten`.
- [x] Files outside `message-media/` on the public disk are not touched. Checked by `files_outside_message_media_are_not_touched`.
- [x] If the message update throws, the public copy is kept and the run reports an error. Checked by `a_failed_message_save_keeps_the_public_copy_and_exits_1`.
- [x] Command is not in `CommandAllowlist`; `public/deploy.php` is unchanged. Checked by the file list of commit `7d8c1c0`.
- [x] `app/logic/private-media.md` written; index row in `app/logic/README.md`; note in `deployment-commands.md`.
- [x] phpunit on `MakePrivateMediaCommandTest`: OK. phpstan on the changed PHP files: no errors.
- [ ] (extra, not in the original list) code-reviewer audit of the command. Open.

## Progress log
- 2026-10-13 17:54 created from intake `TEMP/intake-2026-10-13-1747.md` and `PLAN-002`.
- 2026-10-13 17:55 started. Two agents: test author (red tests) and implementer (command and docs).
- 2026-10-13 18:04 red run: 10 of 10 fail (command not found).
- 2026-10-13 after the red run: implementation and fixes; committed as `7d8c1c0`.
- 2026-10-14 green: `MakePrivateMediaCommandTest` runs inside a 36-test set, `OK (36 tests, 143 assertions)`. That run was on the working tree that became commit `6282223` (T-0032 changed the expected login URL in this test to the uuid form). PHPStan on the command and provider: no errors.
- 2026-10-14 the command was NOT run on live data. That is the user's step (T-0028).

## Result
Done on 2026-10-14 as code: the command, its tests, and its docs are committed (`7d8c1c0`) and pushed. Its dry run and `--apply` have **not** been run on the live server. The user runs them (T-0028 steps 2 and 3).

Open: the code-reviewer audit (the command moves files and rewrites links). Not run yet.
