---
id: T-0014
title: Confirm live delivery and the 00:39 gap on the live server
status: blocked
priority: high
type: research
plan: tasks/plans/PLAN-001-inbox-speed-and-media/PLAN.md
phase: tasks/plans/PLAN-001-inbox-speed-and-media/phase-01-speed-and-media.md
depends_on: []
source: TEMP/intake-2026-10-13-0049.md
created: 2026-10-13 00:55
started:
updated: 2026-10-13 00:55
completed:
---

# T-0014: Confirm live delivery and the 00:39 gap on the live server

## What the user asked
> "check that it hasn't been synces since 00:39" and "isn't it should be syncing live? messages should be instant instead of cron or waiting and refresh"

## What this means
We know whether new WhatsApp messages reach the live server, whether they are pushed to the open inbox without a refresh, and where the sync stopped if it did.

## What is known so far (read from the code and the live page; not verified on the server)
- Live page: the Content-Security-Policy on https://wa.esystematics.com allows the Pusher host `ws-ap2.pusher.com`, so the browser is set up to connect for live updates.
- Whether the server actually pushes events depends on `BROADCAST_CONNECTION=pusher` and the Pusher keys on the server. The app default is `null` (`config/broadcasting.php:18`) and the sample `.env` says `log`. Not checked on the live server.
- The inbound WhatsApp Web path processes each webhook right after the response (`WhatsappWebWebhookController.php:70–92`). It does not wait for cron.
- `routes/console.php` runs `whatsapp-web:sync` every 10 minutes. That is a backfill, not the live path.
- The latest message in the screenshot is 00:39; the check was at about 00:51. Twelve minutes with no new message is not proof of a stop. I cannot see the live database or logs from here.

## Rules from CLAUDE.md (REQUIRED)
- [ ] Live behaviour is unverified until checked against the running system. Report it as unverified until the steps below are done.
- [ ] Do not write secrets (Pusher secret, API keys) into the repo or into the task file. Record only "set" or "not set".
- [ ] State-changing calls to the live server only when the user asks.

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/realtime-config.md`, `app/logic/waha-sync-and-labels.md`, `app/logic/README.md`
- Update when done: `app/logic/realtime-config.md` with the verified live state

## Skills (REQUIRED)
- [x] superpowers:systematic-debugging — phases 1–2 done at intake (what is known above)
- [ ] superpowers:verification-before-completion — before reporting the result

## Prerequisites
- [ ] Needed from user (only the user can do these; they are read-only checks):
  1. Open the WhatsApp Web health page, `https://wa.esystematics.com/app/whatsapp-web/health` (login needed). Read the "Last message from WAHA to this app" line (with its time) and the realtime check.
  2. On the live server, check whether `BROADCAST_CONNECTION` is `pusher` and whether `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER` are set. Report only "set" or "not set".
  3. Send a test message from a phone to the linked number. Note the time, and whether it appears in an open inbox tab without a refresh. Then press the refresh button and note whether it appears only after the refresh.

## Blocked by
The user's answers to the three checks above. Cannot be done from the code in this repo.

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-001-inbox-speed-and-media/PLAN.md` · Phase: `tasks/plans/PLAN-001-inbox-speed-and-media/phase-01-speed-and-media.md` · Intake: `TEMP/intake-2026-10-13-0049.md`
- Related files: `app/Modules/WhatsappWeb/Services/WhatsappWebHealth.php` (`lastWebhook`), `app/Events/MessageReceived.php`, `config/broadcasting.php`

## Steps
- [ ] User runs the three checks above and sends the results
- [ ] Decide: if live is off on the server, fix the server env (needs user go-ahead); if live is on but the test message does not appear live, debug the client path

## Acceptance criteria
- [ ] Result records: last webhook time, realtime status (set / not set), test message timing with and without refresh
- [ ] A conclusion: "sync stopped at X", "live off on server", or "live works, nothing came in"

## Progress log
- 2026-10-13 00:55 created from intake (blocked: needs the user's checks on the live server)

## Result
(not started)
