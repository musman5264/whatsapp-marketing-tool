---
id: T-0071
title: RentOyee as a second linked system (same machinery as EPOS) and per-client EPOS / RentOyee switches
status: completed
priority: high
type: feature
plan: tasks/plans/PLAN-003-epos-integration/PLAN.md
phase: tasks/plans/PLAN-003-epos-integration/phase-01-integrations-api.md
depends_on: []
source: TEMP/intake-2026-10-30-2052.md
created: 2026-10-30 20:52
started: 2026-10-30 20:52
updated: 2026-10-30 20:52
completed: 2026-10-30 21:40
---

# T-0071: RentOyee integration + client switches

## What this means
RentOyee (spec: `rentalLaravel/docs/integrations/e-whatsapp-tool`) links through the existing `/api/v1/integrations` API with `type: rentoyee`. It reuses the manifest cache, safe client, health, call log and the flow step. Added for RentOyee: file/`reply_context`/`chat_attachment` params, `{ref}` path params, string `required_if`, plain-string choices, 401 = unlinked, 5-minute ping that refetches a changed manifest, `reply_context` stored from `messages/send` and applied to the customer's next text/photo. EPOS and RentOyee each have an on/off switch per client (Settings page, client administrators). Clients that already have an EPOS link are switched on by the migration; everything else starts off.

## Rules from CLAUDE.md
- [x] Read the code before stating behaviour; real RentOyee / live behaviour reported "unverified".
- [x] Backend code under `app/Modules/Integrations`; no secrets in the repo.
- [x] Tests and phpstan SKIPPED on the user's explicit instruction (2026-10-30); eslint run on the 3 changed JSX files, clean.
- [x] New strings in `resources/js/locales/en.json`; `npm run build` committed on its own.
- [x] Logic written to `app/logic/` (README index) before "done".
- [x] Commit files by name, push to main.

## Logic / design docs
- app/logic/epos-integration.md (extend), rentalLaravel/docs/integrations/e-whatsapp-tool/README.md

## Skills
- [x] task-tracker, [x] ponytail, [x] brainstorming (reduced: user asked to go direct), [x] verification-before-completion (php -l, eslint, Http::fake multipart check, npm build)

## Progress log
- 2026-10-30 20:52 created; user asked to skip tests and reviews.

## Result
See app/logic/epos-integration.md section "RentOyee as a second linked system + per-client switches". Unverified against a real RentOyee and the live site. Run `php artisan migrate --force` after deploy (sets EPOS on for clients that already had a link).
