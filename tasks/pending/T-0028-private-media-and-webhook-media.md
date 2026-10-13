---
id: T-0028
title: Private media storage (photos are public with guessable names) and media in the outgoing webhook
status: blocked
priority: high
type: security
plan: tasks/plans/PLAN-002-private-media/PLAN.md
phase: tasks/plans/PLAN-002-private-media/phase-01-private-media.md
depends_on: [T-0027, T-0029, T-0030]
source: TEMP/intake-2026-10-13-1318.md
created: 2026-10-13 17:35
started: 2026-10-13 17:35
updated: 2026-10-13 17:55
completed:
---

# T-0028: Private media storage and media in the outgoing webhook

## Evidence (17:35)
- `https://wa.esystematics.com/storage/message-media/1567.jpg` opened from outside with no login (200 image/jpeg). Files were on the public disk, named after the message id, so they could be guessed.
- Outgoing images from the composer were stored the same way (`InboxController::reply`, public disk, `link` = public URL sent to WAHA).
- The outgoing webhook sent `media: null` for WhatsApp photos. The payload only read the `Media` table through `media_id`, which WhatsApp photos do not use.

## Decisions and status
1. **Private storage: done in code, commit `5e7137e`** (on main, not live until the user deploys). New received and sent media is on the private disk. The inbox serves it through the login check. WAHA gets a 15-minute signed link.
2. **Webhook media: decided by Claude** (the user wrote "the webhook media decision is still yours"). The `message.received` webhook carries `media.url`, a signed link valid for 1 hour, created on every attempt. The payload change is additive. Built in **T-0030**. Design in `tasks/plans/PLAN-002-private-media/PLAN.md`.
3. **Old files: command `media:make-private`** (**T-0029**). It is a dry run by default. `--apply` moves the files. Claude does not run it on live data.

## Rules from CLAUDE.md
- [ ] Ask before changing stored data (moving files): the command moves files only when the user runs `--apply`.
- [ ] Tests first for the link check and the webhook payload: the test files for T-0029 and T-0030 are written and seen to fail before the code.

## Logic / design docs
- `app/logic/private-media.md` (T-0029) and `app/logic/webhook-deliveries.md` (T-0030).

## Blocked by (the user's live steps; Claude cannot reach the live server, and live changes happen only when the user asks)
1. Deploy `5e7137e`, the T-0030 commit and the T-0031 commit together, from Admin → Deployment. Do not deploy `5e7137e` alone: it makes new outgoing WhatsApp Web images return 404 until T-0031 is in (T-0031). Then `php artisan config:clear`. No migration. `whatsapp-web:resubscribe` is not needed: no webhook event changed.
2. Run the command on the server: first `php artisan media:make-private` (a dry run; check the counts), then `php artisan media:make-private --apply`. Use the Deployment Run box with the second key, or the cPanel terminal.
3. Live checks:
   - `https://wa.esystematics.com/storage/message-media/1567.jpg` no longer opens without login after the move. Before the move it returned 200 (checked 17:25).
   - Old photos still show in the chat (they open through the login check).
   - A new photo from the phone: the outgoing webhook body carries `media.url`, and that URL returns the file. Unverified until this is done.
4. Note: the isinternet receiver returns 401 for every delivery (see `app/logic/webhook-deliveries.md`). It cannot use the new field until its own check is fixed. That code is not in this repo.

## Progress log
- 2026-10-13 17:35 created. Waiting for the decisions above.
- 2026-10-13 17:55 decision 1 is in `5e7137e`; decision 2 is T-0030; decision 3 is T-0029. Plan PLAN-002. Code and tests in progress (two agents per task). The live steps above are the user's.

## Result
(not finished) The code work is in T-0029 and T-0030. This task closes when the live steps above are done and checked.
