---
id: T-0063
status: completed
plan: PLAN-000 / phase-01
created: 2026-10-26 17:34
completed: 2026-10-26 17:50
---
# Session status events were deduped for 30 days, so later drops never alerted EPOS

## Root cause
`WhatsappWebWebhookController::eventKey()` keyed `session.status` as `status:session`. `inbound_webhook_events` keeps keys 30 days, so each status (WORKING, FAILED, SCAN_QR_CODE) was processed once; every later one was dropped. The DB status froze (here at `failed`), `wasActive` was never true again, and the EPOS alert never fired.

## Fix
`session.status` returns no dedup key (handler is idempotent). Test: `EposReconnectAlertTest::every_drop_through_the_webhook_alerts_not_only_the_first_of_each_status` (failed first, passes now).

## Rules from CLAUDE.md
- [x] Verified against code; live behaviour marked unverified
- [x] Logic doc updated: app/logic/waha-sync-and-labels.md
- [x] No frontend change, no build

## Skills
- [x] task-tracker  - [x] superpowers:systematic-debugging  - [x] test-driven-development

## Result
Test failed before (round 2: WORKING ignored, status stayed failed), passes after. EposReconnectAlertTest + InboundWebhookTest: 26 tests, only known baseline failure MetaInboundWebhookTest::global_webhook_dedupes_duplicate_entry_ids. phpstan OK. Live: unverified.
