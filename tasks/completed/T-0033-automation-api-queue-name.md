---
id: T-0033
title: API-triggered automation runs went to queue 'automations' (no worker listens there)
status: completed
priority: high
type: bug
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-14-1245.md
created: 2026-10-14 12:45
started: 2026-10-14 12:45
updated: 2026-10-14 12:55
completed: 2026-10-14 12:55
---

# T-0033: API-triggered automation runs went to queue 'automations'

## Root cause (read from code)
`app/Http/Controllers/Api/V1/AutomationApiController.php` dispatched `ExecuteAutomationRunJob` with `->onQueue('automations')`. Every worker in `docker/supervisor/whatsmine.conf` and `docker-compose.queues.yml` listens on `automation` (singular). Under redis, those runs sit in redis and never run. The existing test only asserted that a job was pushed, not which queue.

## Fix
- Test first: `tests/Feature/Api/V1/AutomationApiTest.php` now asserts `Queue::assertPushedOn('automation', ...)`. It failed (job pushed to the wrong queue).
- Controller changed to `onQueue('automation')`. The test then passed (5 tests, 10 assertions).

## Rules
- CLAUDE.md: never guess; verified against the code and the test run.
- Live behaviour: unverified until a live API trigger is run after deploy.

## Skills
- [x] superpowers:systematic-debugging
- [x] superpowers:test-driven-development
- [x] superpowers:verification-before-completion

## Progress log
- 2026-10-14 12:50 red: `AutomationApiTest::test_trigger_automation_creates_run_and_dispatches_job` fails (job not pushed on `automation`).
- 2026-10-14 12:55 green: `AutomationApiTest` 5 tests OK.
