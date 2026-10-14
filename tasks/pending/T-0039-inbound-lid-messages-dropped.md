---
id: T-0039
title: Inbound messages from LID senders were dropped when WAHA could not map the number
status: blocked
priority: high
type: bug
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-14-1500.md
created: 2026-10-14 15:00
started: 2026-10-14 15:00
updated: 2026-10-14 15:30
completed:
---

# T-0039: Inbound messages from LID senders were dropped

## What the user reported
> "the inbound messages are not working and syncing but outgoing messages are working and syncing live. waha is sending it accurately. check this."
WAHA log: `message.any` posted to `/webhooks/whatsapp-web/...` and answered 200, then `GET /api/contacts?contactId=...@lid` (WAHA) straight after.

## Root cause (read from code; live cause not yet confirmed)
`InboundNormalizer::resolveSender()` turns a `@lid` sender into a phone number through WAHA. If WAHA's reply has no `@c.us` id, the phone is empty, `normalize()` returns null, and the message is dropped. Only the warning `whatsapp_web.inbound.unresolved_sender` is logged. CLAUDE.md says an inbound message must never be lost. The existing test only covered the case where WAHA maps the LID.

## Fix (test first)
- `InboundWebhookTest`: 2 new tests, both red before the fix (the message table stays empty):
  - `lid_sender_that_waha_cannot_map_is_still_stored`
  - `lid_sender_is_stored_when_the_lookup_fails`
- `InboundNormalizer::resolveSender()`: when the LID cannot be mapped or the lookup fails, the LID digits stand in for the phone number. Not cached. Logged as `whatsapp_web.inbound.lid_unmapped` (info).
- Result: WhatsappWeb and inbound group: 177 tests, 1 failure, the known baseline `MetaInboundWebhookTest::global_webhook_dedupes_duplicate_entry_ids`. PHPStan clean on `InboundNormalizer.php`.

## Not fixed (needs a decision or a live check)
- Why WAHA cannot map some LIDs. Likely the WAHA NOWEB store (`config.noweb.store.enabled` and `full_sync`). The earlier WAHA error said these must be on when a session starts. Check the session config in WAHA.
- Replies to a LID-stored contact use `<digits>@c.us` and will likely fail (✗). Merging with the same person's `@c.us` contact is not done.
- Duplicates: the same person can have two contacts (phone and LID digits).

## What settles it (user, on the live server)
1. `grep -c "whatsapp_web.inbound.unresolved_sender\|whatsapp_web.inbound.lid_unmapped" storage/logs/laravel.log` and the last 10 lines. Before the deploy, `unresolved_sender` lines mean messages were dropped at that time.
2. After the deploy: send one message from a phone whose chat is a LID. The message should appear. Note the time and whether it shows without a refresh.
3. WAHA session `ws-1`: the `config.noweb.store` values (names and true/false only).

## Rules
- CLAUDE.md: live behaviour is unverified until checked against the running system.
- Logic doc: `app/logic/waha-sync-and-labels.md` (section "Inbound from a LID sender").
