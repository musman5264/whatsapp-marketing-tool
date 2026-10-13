---
id: T-0016
title: Live sync: a failed realtime send must not cancel automations or create the message again
status: completed
priority: high
type: bug
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: []
source: TEMP/intake-2026-10-13-1140.md
created: 2026-10-13 11:40
started: 2026-10-13 11:41
updated: 2026-10-13 12:13
completed: 2026-10-13 12:13
---

# T-0016: Live sync: a failed realtime send must not cancel automations or create the message again

## What the user asked
> "why are the messages still not live synced as i have turned on the pusher also but it worked only for once."

## What this means
When Pusher (or Reverb) refuses a live event, the message is still stored once, the automations still run, and the inbound retry does not create a second copy. The failure is logged so it can be seen.

## Root cause (systematic-debugging phases 1-2, from the code)
- `Illuminate\Events\Dispatcher::invokeListeners` sends a `ShouldBroadcastNow` event to the broadcaster before running its listeners. A broadcast that throws (`BroadcastException`, e.g. Pusher refuses the event) stops the listeners: automations never run for that message.
- `app/Modules/Whatsapp/Services/WhatsappDriver.php` `processInboundMessage()` releases the `whatsapp_msg` idempotency key when anything throws, even after the message row exists. `app/Modules/WhatsappWeb/Http/Controllers/WhatsappWebWebhookController.php` then re-queues the event. The retry creates a second row for the same WhatsApp message, and the broadcast fails again.
- Laravel's `Illuminate\Contracts\Broadcasting\ShouldRescue` interface makes `BroadcastManager::queue()` wrap the send in `rescue()`, which reports the error and continues. Our realtime events do not implement it.

## Rules from CLAUDE.md (REQUIRED)
- [ ] WAHA calls from the inbound pipeline are soft-fail: catch Throwable, log, never block or lose an inbound message.
- [ ] Backfilled/historical messages never fire automations, AI replies or unread bumps.
- [ ] Never show SQL or stack traces to end users; log them.
- [ ] Tests: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='<name>'`; never two test processes at once.
- [ ] Known failing at baseline (not regressions): HandoverTest (2), LabelCrudTest (3), SlaTrackingTest (2), MetaInboundWebhookTest::global_webhook_dedupes_duplicate_entry_ids, TypingEndpointTest (2).
- [ ] Git: `git add` by name; commit; `git pull --rebase origin main`; `git push origin main`. Live deploy is manual on this host.

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/realtime-config.md`, `app/logic/waha-sync-and-labels.md`, `app/logic/README.md`
- Update when done: `app/logic/realtime-config.md` (realtime events never throw into the inbound pipeline; failures are logged)

## Skills (REQUIRED)
- [x] superpowers:systematic-debugging — phases 1-2 done at intake (root cause above)
- [ ] superpowers:test-driven-development — failing test first: failing broadcaster, message stored once, listeners still run
- [ ] superpowers:verification-before-completion — before completing

## Prerequisites
- [ ] Tasks: none
- [ ] Needed from user: none for the code. Live verification needs a deploy (Admin → Deployment).

## Blocked by
Nothing.

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-000-general/PLAN.md` · Phase: `tasks/plans/PLAN-000-general/phase-01-ongoing.md` · Intake: `TEMP/intake-2026-10-13-1140.md`
- Related files: `app/Events/MessageReceived.php`, `app/Events/MessageSent.php`, `app/Events/MessageStatusUpdated.php`, `app/Events/TypingChanged.php`, `app/Events/MessageMediaReady.php`, `app/Modules/Whatsapp/Services/WhatsappDriver.php`

## Steps
- [ ] Failing test: a broadcaster that throws → webhook still 200, message stored once, a MessageReceived listener still runs
- [ ] Add `ShouldRescue` to every event that implements `ShouldBroadcastNow`
- [ ] Release the inbound idempotency key only when the message row was not stored
- [ ] Re-run the test; run the inbound and realtime tests
- [ ] Update `app/logic/realtime-config.md`

## Acceptance criteria
- [ ] Test passes: broadcast failure leaves one message row and runs the listeners (phpunit output in Result)
- [ ] The existing realtime and inbound tests still pass
- [ ] A rescued broadcast failure is logged (checked in the test)

## Progress log
- 2026-10-13 11:41 created from intake; approved by the user's message; started
- 2026-10-13 11:58 done locally; the rescue was verified against a real queue run; commit a434e48

## Result
- Changed: `app/Events/MessageReceived.php`, `MessageSent.php`, `MessageStatusUpdated.php`, `TypingChanged.php`, `MessageMediaReady.php` (ShouldRescue); `app/Modules/Whatsapp/Services/WhatsappDriver.php` (duplicate-guard release only when no row was stored); test `tests/Feature/Realtime/BroadcastFailureDoesNotBreakInboundTest.php` (new).
- Checked: `BroadcastFailureDoesNotBreakInboundTest` → `OK (2 tests, 5 assertions)`. Each test was checked to FAIL with the fix removed: the listener did not run (0 of 1), and the duplicate case stored 2 rows.
- Wider run (`Realtime|Setting|Inbox|WhatsappWeb|WahaMedia|AdminPermissions|Branding|Integration`): 243 tests, 9 failures, all on the known-baseline list (HandoverTest 2, LabelCrudTest 3, SlaTrackingTest 2, TypingEndpointTest 2).
- Commit: `a434e48`. Pushed with the later commits.
- Live: not deployed yet (deploys are manual on this host: Admin → Deployment → Deploy latest commit).
- Limit: the fix stops the side effects. It does not make Pusher accept events. The log will now show each refused send ("Pusher refused"/"BroadcastException") so the cause can be read on the server.
