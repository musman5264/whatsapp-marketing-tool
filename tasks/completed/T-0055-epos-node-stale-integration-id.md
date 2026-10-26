---
id: T-0055
title: E-POS node says "not linked" when its saved integration id is stale (imported automation); fall back to the only linked E-POS like the builder
status: completed
priority: high
type: bug
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-26-1210.md
created: 2026-10-26 12:10
updated: 2026-10-26 12:30
completed: 2026-10-26 12:30
---

# T-0055: E-POS node, stale integration id

## Root cause
`AutomationEngine::executeEpos` uses `find($data['integration_id'])` and never falls back; `EposNode.jsx` `resolveEpos` does fall back to the single integration. An imported automation carries an id from elsewhere, so the builder shows it linked while the run says "E-POS is not linked." and writes no call log. Live DB not checked (unverified).

## Rules from CLAUDE.md
- [x] Test first; verify with PHPUnit command from CLAUDE.md (empty DB_PASSWORD, memory 1G); phpstan on touched file
- [x] Logic doc app/logic updated (E-POS node)
- [x] Commit by file name, push main; deploy not claimed until asset/behaviour checked on live (unverified)

## Skills
- [x] superpowers:systematic-debugging (phases 1-2)
- [x] superpowers:test-driven-development
- [x] superpowers:verification-before-completion

## Result
`executeEpos` now ignores a saved id that is not in the workspace and uses the only linked E-POS (two or more links and an unknown id still ends "not linked"). Test first: new test failed (`NO` instead of `Ali Khan`), then `EposNodeTest` OK (34 tests, 76 assertions). phpstan: 8 errors in the file, none on the changed lines. Logic doc `app/logic/epos-integration.md` updated. Live run unverified: the live node's saved id was not read.
