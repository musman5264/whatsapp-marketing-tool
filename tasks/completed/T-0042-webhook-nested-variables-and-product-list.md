---
id: T-0042
title: API Call node: nested response variables, lists, poll options from a list, nested conditions, rename
status: completed
priority: medium
type: feature
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-24-0205.md
created: 2026-10-24 02:05
started: 2026-10-24 02:30
updated: 2026-10-24 03:10
completed: 2026-10-24 03:10
---

# T-0042: API Call node: nested variables and lists

## What the user asked
> "approved but make the webhook renamed to API call"; `{{answer.customer.name}}`; show products of a category in WhatsApp (QR number).

## Rules from CLAUDE.md
- [x] Test first: 7 new tests in `AutomationNodeBehaviourTest`, watched 4 fail (then 1 more for the condition), then green.
- [x] New strings in `en.json` (2 hint keys); label renamed in `en.json` only (other 16 locales keep the old name: not done).
- [x] `app/logic/automation-webhook-node.md` updated in place.
- [x] `npm run build` run; build output committed on its own.
- [ ] 360px / 1920px light and dark check of the changed hint: NOT done (needs a browser); say so.
- [ ] Commit by file name, push: see Result.

## Logic / design docs
- `app/logic/automation-webhook-node.md`, `app/logic/api-message-send.md` (status values), `app/logic/README.md`

## Skills
- [x] task-tracker, [x] superpowers:brainstorming, [x] superpowers:test-driven-development, [ ] senior-frontend / code-reviewer (not run), [x] verification-before-completion (tests, phpstan, eslint, build)

## Result
- `AutomationEngine`: dotted paths, `{{#each}}`, `{{@n}}`, wildcard `*`, poll options from a list, condition on a nested field, internal `_` keys no longer sent to the other server.
- `Builder.jsx` + `en.json`: node label "API Call", hint shows the new syntax.
- Checked: `tests/Feature/Automation` + `AutomationEngineTest`: OK (77 tests, 202 assertions). phpstan: 8 errors, all in lines not touched (same file, lines 108 and 1488+). eslint on Builder.jsx: 1 error at line 553 and 3 warnings, none in the changed hint.
- NOT checked: the live site, the builder panel visually.
- Split off: the Alibaba-style cards are T-0044 (pending approval).
