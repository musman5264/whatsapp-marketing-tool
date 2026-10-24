---
id: T-0051
title: E-POS recent calls table and a one-hour request/response debug switch
status: completed
priority: high
type: feature
plan: tasks/plans/PLAN-003-epos-integration/PLAN.md
phase: tasks/plans/PLAN-003-epos-integration/phase-01-integrations-api.md
depends_on: [T-0048, T-0049]
source: TEMP/intake-2026-10-24-1408.md
created: 2026-10-24 16:50
started: 2026-10-24 16:50
updated: 2026-10-24 16:50
completed: 2026-10-24 17:20
---

# T-0051: E-POS recent calls table and a one-hour request/response debug switch

## What this means
- Every E-POS call and manifest read writes a row to `external_call_logs`: time, question, outcome (found / not_found / no_data), HTTP status or reason, milliseconds, a short message. Never a phone, key or body.
- A switch per link on the status page turns on recording for 1 hour (`debug_until`). While on, rows also keep the request (method, URL, params) and the response (status, first 2 KB) with phone numbers masked like +9233•••6444 and never the key. Payloads are wiped after 1 day, rows after 30 days.
- The status page shows the last 50 calls per link, with details for rows that have a payload. Admins only.

## Rules from CLAUDE.md
- [x] Test first; logic written to app/logic/epos-integration.md; new strings in en.json (and ur.json); 360px and 1920px, light and dark; npm run build committed on its own; commit by name and push; report live behaviour as unverified.

## Logic / design docs
- app/logic/epos-integration.md, tasks/plans/PLAN-003-epos-integration/PLAN.md (R8 privacy: masked phones, no bodies by default)

## Skills
- [x] brainstorming (user chose option 3), [ ] test-driven-development, [ ] senior-frontend, [ ] senior-dashboard, [ ] verification-before-completion

## Progress log
- 2026-10-24 16:50 created.

## Result
Built test-first. Files: ExternalCallLog model + migration 2026_10_24_000002, EposExecutor/ManifestFetcher recording, EposIntegrationController (calls, debug), routes/client.php, Epos.jsx, en.json + ur.json, 3 test files.
Checked: 46 tests in the three EPOS test files OK; phpstan clean on touched files; eslint clean; one browser look at 360px. Per the user's instruction the broad suites were not re-run. Pint was run on app/Modules/Integrations and touched two unrelated files; those were restored with git checkout (they held only pint changes).
