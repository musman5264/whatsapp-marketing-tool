---
id: T-0047
title: Integrations API, manifest cache, safe EPOS client and health (R1, R2)
status: completed
priority: high
type: feature
plan: tasks/plans/PLAN-003-epos-integration/PLAN.md
phase: tasks/plans/PLAN-003-epos-integration/phase-01-integrations-api.md
depends_on: []
source: TEMP/intake-2026-10-24-1408.md
created: 2026-10-24 14:30
started: 2026-10-24 14:50
updated: 2026-10-24 14:30
completed: 2026-10-24 15:40
---

# T-0047: Integrations API, manifest cache, safe EPOS client and health (R1, R2)

## What this means
Build slice 1 of PLAN-003: external_integrations table and model, SafeHttp (SSRF guard, 5 s, no redirects, size caps), ManifestFetcher with per-capability validation, IntegrationHealth with breaker and backoff, `/api/v1/integrations` endpoints, `integrations:write` ability, tests and app/logic/epos-integration.md. Waits for the user to review the written spec in PLAN.md.

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
- [x] brainstorming, [ ] writing-plans, [ ] test-driven-development, [ ] code-reviewer, [ ] verification-before-completion, [ ] planetscale-database (migration)

## Progress log
- 2026-10-24 14:30 created from the approved design (user chose slice 1 first).

## Result
Slice 1 built test-first. Files: SafeHttp, HostResolver, ExternalCallException, ManifestFetcher, IntegrationHealth (app/Modules/Integrations/Services/External), ExternalIntegration model + migration, IntegrationApiController, routes/api.php, ApiAbilities, config/external_integrations.php, app/logic/epos-integration.md (+ README row), two test files.
Checked: RED first (31 errors, classes missing), then `DB_PASSWORD= DB_DATABASE=wm3_test_t0047 php -d memory_limit=1G vendor/phpunit/phpunit/phpunit tests/Feature/Integrations tests/Feature/Api tests/Feature/ApiTokenTest.php` -> OK (138 tests, 379 assertions). phpstan level 6 on new files: no errors.
Unverified: real DNS pinning (CURLOPT_RESOLVE) and a real EPOS; migration not run on live (`php artisan migrate --force` needed after deploy). Docs.jsx scope list not updated (no frontend build in this slice).
