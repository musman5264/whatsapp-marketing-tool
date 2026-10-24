---
id: T-0049
title: Builder E-POS node, integration status page and banner, English and Urdu (R4, R2 banner)
status: completed
priority: medium
type: feature
plan: tasks/plans/PLAN-003-epos-integration/PLAN.md
phase: tasks/plans/PLAN-003-epos-integration/phase-01-integrations-api.md
depends_on: [T-0048]
source: TEMP/intake-2026-10-24-1408.md
created: 2026-10-24 14:30
started: 2026-10-24 17:30
updated: 2026-10-24 14:30
completed: 2026-10-24 18:20
---

# T-0049: Builder E-POS node, integration status page and banner, English and Urdu (R4, R2 banner)

## What this means
Dropdowns from the manifest, read-only phone param, outputs in the variable picker, summary_template with the variable name prefixed, admin-only status page, RTL Urdu.

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
- [x] brainstorming, [ ] writing-plans, [ ] test-driven-development, [ ] code-reviewer, [ ] verification-before-completion, [ ] frontend-design, [ ] senior-frontend, [ ] senior-dashboard

## Progress log
- 2026-10-24 14:30 created from the approved design (user chose slice 1 first).

## Result
Slice 3 built: tests first (backend RED, vitest RED), then code. Files: EposNode.jsx, Builder.jsx, lib/epos.js, Client/EposIntegrationController.php, AutomationController::epos, Pages/client/Integrations/Epos.jsx, routes, en.json + ur.json, tests, vitest setup.js renamed to setup.jsx.
Checked: 258 backend tests OK, vitest 21 passed, eslint clean on new files, screens looked at in a browser (status page 360/1920 light+dark; builder 1920 light). Not checked: builder at 360px and dark, Urdu on screen, live EPOS, deploy.
