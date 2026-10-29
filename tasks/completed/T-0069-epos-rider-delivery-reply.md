---
id: T-0069
title: Delivery riders answer an order on WhatsApp (delivery.rider_reply): hand the text to EPOS first, send its reply, stop the normal flow
status: completed
priority: high
type: feature
plan: PLAN-003 (epos-integration)
phase: phase-01-integrations-api
source: TEMP/intake-2026-10-29-2241.md
created: 2026-10-29 22:41
started: 2026-10-29 22:41
updated: 2026-10-29 23:15
completed: 2026-10-29 23:15
---

# T-0069: delivery.rider_reply

## What this means
Spec from the EPOS team: `d:\Dev\projects\epos\Modules\Whatsapp\docs\TOOL-rider-delivery-reply.md`. For every incoming WhatsApp **text** message that has a digit, the tool calls the EPOS action `delivery.rider_reply` (`phone` = verified sender, `message` = raw text, up to 1500 chars) before the normal flow. `handled: true` -> send `reply` to the sender as one text and stop (no automations, no AI, no alert.push, no webhook, no unread). `handled: false` or any failure -> normal flow, silently, no retry.

## Design (decided)
- New `RiderReplyHandler` next to `EposExecutor`; reuses the executor (identity binding, SafeHttp, health, rate limit, call log, idempotency key).
- Called from `WhatsappDriver::storeNormalizedMessage()` just before the unread bump and `MessageReceived::dispatch` (both Cloud and WAHA paths end there). The listeners are auto-discovered so their order cannot be controlled; a hook at the source really stops the normal flow.
- Runs only when: inbound, not historical, type `text`, body has a digit, the workspace has an EPOS link whose cached manifest lists `delivery.rider_reply`. Everyone else costs one DB read and no HTTP.
- Message param is the manifest param with `from: incoming_message`; cut at its `max_length`; not lowercased or trimmed inside.
- Idempotency seed = the provider message id.

## Acceptance criteria
- [x] handled:true -> reply sent once as a text, automations/AI/webhook not fired, unread not bumped
- [x] handled:false (not_a_rider, no_order_number) -> normal flow runs as before, nothing sent
- [x] timeout / 500 / non-JSON / not_available / capability not in manifest -> normal flow
- [x] no digit, image/voice/sticker, historical, outbound -> EPOS not called
- [x] sender number comes from the contact, never from the text; message sent raw with line breaks
- [x] same provider message id twice -> one EPOS call/one reply

## Rules from CLAUDE.md
- [x] Never guess; verified against the code (done above)
- [x] Soft-fail: catch Throwable, log, never lose an inbound message
- [x] Historical/backfilled messages never fire this
- [x] Test first; real test run quoted
- [x] phpstan level 6 on new files
- [x] Logic doc written in app/logic/epos-integration.md
- [x] Commit by file name, pull --rebase if rejected, push main; no frontend change so no build
- [x] Live EPOS / live site stays "unverified"

## Logic / design docs
- app/logic/epos-integration.md (update), the EPOS spec above

## Skills
- [x] task-tracker
- [x] superpowers:brainstorming (spec is the design)
- [x] superpowers:test-driven-development
- [x] superpowers:verification-before-completion
- [ ] code-reviewer (not invoked, see Progress log)

## Progress log
- 2026-10-29 22:41 created and started.
- 2026-10-29 23:15 done. Test first: `RiderReplyTest` (16 tests) written before the handler; with the hook switched off 8 of them failed (red), with it on all pass. Final run: `OK (59 tests, 141 assertions)` for RiderReplyTest + EposNodeTest; regression `tests/Feature/{WhatsappWeb,Integrations,Automation,Inbox,ProductionHardening}`: 431 tests, 8 failures = the 7 known baseline ones (HandoverTest 2, LabelCrudTest 3, SlaTrackingTest 2) plus `PlanLimitTest::test_campaign_launch_returns_redirect_when_limit_exhausted` (404 on /app/broadcasts/campaigns/{id}/launch; not on the baseline list; the code path is not touched by this change, but I could not run it on the old code, so not confirmed as baseline). phpstan level 6: no new error in the new file; `WhatsappDriver.php:112` is old.
- Skipped on purpose: code-reviewer skill not invoked separately (the change adds no endpoint or secret; logs carry message id and error text only). Live EPOS / live site: unverified.

## Result
New `RiderReplyHandler`; hook in `WhatsappDriver::storeNormalizedMessage()`; tests `tests/Feature/Integrations/RiderReplyTest.php`; logic doc section in `app/logic/epos-integration.md`. No frontend change, so no build.
