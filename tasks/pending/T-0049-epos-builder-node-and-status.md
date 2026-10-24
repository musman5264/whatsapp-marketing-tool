---
id: T-0049
title: Builder E-POS node, integration status page and banner, English and Urdu (R4, R2 banner)
status: pending
priority: medium
type: feature
plan: tasks/plans/PLAN-003-epos-integration/PLAN.md
phase: tasks/plans/PLAN-003-epos-integration/phase-01-integrations-api.md
depends_on: [T-0048]
source: TEMP/intake-2026-10-24-1408.md
created: 2026-10-24 14:30
started:
updated: 2026-10-24 14:30
completed:
---

# T-0049: Builder E-POS node, integration status page and banner, English and Urdu (R4, R2 banner)

## What this means
Dropdowns from the manifest, read-only phone param, outputs in the variable picker, summary_template with the variable name prefixed, admin-only status page, RTL Urdu.

## Rules from CLAUDE.md
- [ ] Read the code before stating behaviour; live EPOS / staging behaviour is reported "unverified" until checked.
- [ ] New backend code under `app/Modules/<Module>/`; shared classes in `app/Services`.
- [ ] Tests first; run with `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=...`, never two test processes at once (private DB `wm3_test_<task>` if another session runs); compare failures with the baseline list.
- [ ] phpstan level 6 on touched files; eslint on touched JSX.
- [ ] Every new user-facing string in `resources/js/locales/en.json` (Urdu too per EPOS R9); 360px and 1920px, light and dark; `npm run build` committed on its own if `resources/js` changed.
- [ ] Logic written to `app/logic/` (update README index) before "done".
- [ ] Commit files by name, pull --rebase if rejected, push to main; confirm deploy by asset hash only when asked to deploy. No secrets in the repo.

## Logic / design docs
- tasks/plans/PLAN-003-epos-integration/PLAN.md, TEMP/intake-2026-10-24-1408.md (R1-R9), app/logic/automation-webhook-node.md, app/logic/api-message-send.md

## Skills
- [x] brainstorming, [ ] writing-plans, [ ] test-driven-development, [ ] code-reviewer, [ ] verification-before-completion, [ ] frontend-design, [ ] senior-frontend, [ ] senior-dashboard

## Progress log
- 2026-10-24 14:30 created from the approved design (user chose slice 1 first).

## Result
