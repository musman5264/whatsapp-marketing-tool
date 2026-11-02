---
id: T-0073
title: RentOyee gets its own flow step instead of sharing the E-POS one
status: completed
plan: PLAN-000
phase: phase-01
priority: high
depends_on: none
started: 2026-11-02 17:53
completed: 2026-11-02 18:05
---

## Root cause (systematic-debugging, phases 1-2)
T-0071 reused one `epos` node type for both systems: the palette, config panel, engine (`executeEpos`) and `GET client.automations.epos` all mixed E-POS and RentOyee links. The builder therefore showed a single "E-POS / RentOyee" step; with both linked it offered both in one dropdown, and a step without an id picked nothing.

## Acceptance criteria
- [x] Separate `rentoyee` step in the palette, shown only when a RentOyee link is enabled. Check: code (Builder.jsx); browser NOT checked.
- [x] Each step lists only its own system's links/questions. Check: eposNode.test.js (3 tests).
- [x] Engine runs a rentoyee step against RentOyee only, never falls back to E-POS. Check: RentOyeeNodeTest (3 tests; red first).
- [x] Flows saved with an `epos` step pointing at a RentOyee link still run. Check: eposNode.test.js + engine id lookup kept.

## Rules from CLAUDE.md
- [x] New strings in en.json; npm run build committed on its own; logic doc updated; commit by name.
- [ ] Check at 360px and 1920px, light and dark: NOT done (no logged-in browser session).

## Skills
- [x] task-tracker, [x] systematic-debugging, [x] test-driven-development (backend test first; JS test written after the change), [ ] verification-before-completion (partial: tests + build, no browser)

## Result
Files: AutomationEngine.php, Builder.jsx, EposNode.jsx, automationFile.js, en.json, epos-integration.md, 2 new tests. Found, not fixed: EposNodeTest, RiderReplyTest and other EPOS tests fail at baseline because T-0071's per-client switch defaults to off.
