---
id: T-0035
title: Outgoing message stays at one tick: check the live cause, then decide on the dropped-ack fix
status: blocked
priority: high
type: research
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-14-1245.md
created: 2026-10-14 12:45
started:
updated: 2026-10-14 13:05
completed:
---

# T-0035: Outgoing message stays at one tick

## What the user asked
> "the message i sent why its still single tick? is it queue issue? or reverb issue?"
The 12:40 "?" showed one tick; the 12:34 "?" showed two.

## What the code shows (read, not verified live)
- The tick comes from the WAHA `message.ack` webhook, handled by `WahaEventProcessor::handleAck()`. That webhook is processed in-process after WAHA gets its 200 response. It does not use the queue.
- The send job (`SendQueuedWhatsappMessageJob`, queue `whatsapp`) saved `sent` and the WAHA id. The job ran, so the queue delivered the send.
- `MessageStatusUpdated` is broadcast with `ShouldBroadcastNow`. Reverb only pushes the change to an open screen. The saved status is what a reload shows.
- **Likely cause:** the recipient's phone has not received the message yet (WhatsApp keeps one tick until it does). Then the later `delivered` ack updates the row and the screen.
- **Possible code gap:** `handleAck()` returns when no row has that WAHA id. If the device ack arrives before `SendQueuedWhatsappMessageJob` saves the id (`SendQueuedWhatsappMessageJob.php:57`), the ack is dropped and WAHA does not resend it. The message then stays at `sent`. Not shown to have happened here.

## What settles it (needs the user; live site)
1. Reload the chat. If the 12:40 "?" now shows two ticks, the live push (Reverb) is the problem. The saved status was right.
2. If it still shows one tick after a reload, check the WAHA logs for a `message.ack` for that message at about 12:40 PKT (14 Oct).
   - An ack exists but the tick did not change: the dropped-ack gap above. Build the fix: keep unmatched acks briefly and apply them when the id is saved, with a test first.
   - No ack exists: WAHA or the recipient's phone. Not a code bug in this repo.

## Acceptance criteria
- [ ] Result recorded: reload shows two ticks (Reverb) / still one tick and the WAHA ack seen or not seen.
- [ ] Decision on the dropped-ack fix, based on that result.

## Rules
- CLAUDE.md: live WAHA behaviour is unverified until checked against the running system.
- Reverb path: `app/logic/realtime-config.md`. WAHA ack: `app/logic/waha-api-coverage.md` (`message.ack`).
