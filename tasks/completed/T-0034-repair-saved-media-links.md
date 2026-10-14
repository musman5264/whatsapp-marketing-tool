---
id: T-0034
title: Repair WhatsApp Web photo links saved with the integer conversation id
status: completed
priority: high
type: bug
plan: PLAN-002 (private media)
phase: phase-01-private-media
depends_on: [T-0032]
source: TEMP/intake-2026-10-14-1245.md
created: 2026-10-14 12:45
started: 2026-10-14 12:50
updated: 2026-10-14 13:05
completed: 2026-10-14 13:05
---

# T-0034: Repair saved photo links (integer conversation id)

## Root cause (read from code)
5e7137e built the login link from `$message->conversation_id` (integer). The route `client.inbox.message-media` binds the conversation by uuid (`Conversation::getRouteKeyName()` returns `uuid`), so those links returned 404. 6282223 (T-0032) fixed new links. Saved links keep the old form, and `media:make-private` skips them because their files are already on the private disk.

## Fix
- New command `media:fix-links` (`app/Modules/WhatsappWeb/Console/FixMediaLinksCommand.php`), registered in `WhatsappWebServiceProvider`.
- Dry run by default; `--apply` rewrites `preview_url` and `link` values that match `/conversations/{integer}/messages/{this message}/media`. The WAHA engine `link` under the type key is never touched.
- Each message is re-checked under a row lock before writing.
- Tests first: `tests/Feature/WhatsappWeb/FixMediaLinksCommandTest.php` (3 tests). The command was missing, so all 3 errored (red). Then green.
- Related run: `FixMediaLinksCommandTest`, `MakePrivateMediaCommandTest`, `AuthenticatedMediaUrlTest` (15 tests) OK. WhatsApp Web and related group (167 tests) OK. PHPStan clean on the changed files.

## Not done
- Not run on live data. It needs the live database and a deploy of 6282223 first. Dry run first, then `--apply`, from the cPanel terminal.

## Rules
- CLAUDE.md: live data change only when asked. The command is built and tested; the run is left to the user.
- Logic doc: `app/logic/private-media.md` section "Repairing links saved with the conversation id".

## Skills
- [x] superpowers:test-driven-development
- [x] superpowers:verification-before-completion

## Progress log
- 2026-10-14 12:50 red: `FixMediaLinksCommandTest` (3 errors: command not found).
- 2026-10-14 13:00 green after the command and provider registration.
- 2026-10-14 13:05 related and WhatsApp Web tests green; logic doc updated.
