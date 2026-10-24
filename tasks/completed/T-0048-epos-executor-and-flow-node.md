---
id: T-0048
title: Executor and the epos flow node, ledger action exits (R3, R6.3, R6.4, R7 caching)
status: completed
priority: high
type: feature
plan: tasks/plans/PLAN-003-epos-integration/PLAN.md
phase: tasks/plans/PLAN-003-epos-integration/phase-01-integrations-api.md
depends_on: [T-0047]
source: TEMP/intake-2026-10-24-1408.md
created: 2026-10-24 14:30
started: 2026-10-24 16:00
updated: 2026-10-24 14:30
completed: 2026-10-24 17:10
---

# T-0048: Executor and the epos flow node, ledger action exits (R3, R6.3, R6.4, R7 caching)

## What this means
New `epos` node type in AutomationEngine: server-bound sender_phone (group chats refused), chat_answer/fixed mapping, 5 s, breaker, 60/min limit, Idempotency-Key for actions, found / no-data exits, reason variable, 60 s cache only for non-person capabilities.

## Rules from CLAUDE.md
- [x] Read the code before stating behaviour; live EPOS / staging behaviour is reported "unverified" until checked.
- [x] New backend code under `app/Modules/<Module>/`; shared classes in `app/Services`.
- [x] Tests first; run with `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=...`, never two test processes at once (private DB `wm3_test_<task>` if another session runs); compare failures with the baseline list.
- [x] phpstan level 6 on touched files; eslint on touched JSX.
- [x] Every new user-facing string in `resources/js/locales/en.json` (Urdu too per EPOS R9); 360px and 1920px, light and dark; `npm run build` committed on its own if `resources/js` changed.
- [x] Logic written to `app/logic/` (update README index) before "done".
- [x] Commit files by name, pull --rebase if rejected, push to main; confirm deploy by asset hash only when asked to deploy. No secrets in the repo.

## Logic / design docs
- tasks/plans/PLAN-003-epos-integration/PLAN.md, TEMP/intake-2026-10-24-1408.md (R1-R9), app/logic/automation-webhook-node.md, app/logic/api-message-send.md

## Skills
- [x] brainstorming, [ ] writing-plans, [ ] test-driven-development, [ ] code-reviewer, [ ] verification-before-completion, [ ] dispatching-parallel-agents

## Progress log
- 2026-10-24 14:30 created from the approved design (user chose slice 1 first).

## Result
Slice 2 built test-first (26 tests red, then green). Files: EposExecutor.php (new), AutomationEngine.php (epos node and builder preview), EposNodeTest.php, app/logic/epos-integration.md.
Checked: DB_PASSWORD= DB_DATABASE=wm3_test_t0047 phpunit on tests/Feature/Automation, ProductionHardening/AutomationEngineTest, Integrations, IntegrationApiTest -> OK (134 tests, 349 assertions). phpstan clean on the new service. Real 6 s server -> unreachable at 5.2 s; DNS pin honoured.
Not done: the builder cannot place the node yet (T-0049); queries are not retried; no live EPOS; HTTPS not checked on the server image (CA bundle).
