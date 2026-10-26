---
id: T-0061
title: Order alert flow from the EPOS team's alert.push guide; local problems give reason no_phone / invalid
status: completed
priority: high
type: feature
plan: PLAN-000 (general)
phase: phase-01-ongoing
source: TEMP/intake-2026-10-26-1318.md
created: 2026-10-26 16:30
completed: 2026-10-26 17:30
---

# T-0061

Request: the user pasted the EPOS team's guide "For the tool team: send an alert to the shop (alert.push)".

## Result
- `EposExecutor`: a value that is missing, wrong or over `max_length` now gives reason `invalid`, a chat with no verified number gives `no_phone` (was `no_data`); HTTP errors and timeouts stay `no_data`. No call is made in these cases.
- Importable example flow `app/logic/examples/epos-order-alert.flow.json` with the guide's reply texts (English + Urdu in one message), documented in `app/logic/epos-integration.md`.
- Tests: `EposNodeTest` 40 OK; `EposOrderAlertFlowTest` 10 OK (7 EPOS answers, a 500, a 1501-character message, the exact request); `AutomationImportTest` 11 OK. The two new reason tests were written after the change, not before.
- Not run against a real EPOS or on the live site. The Urdu texts are the guide's samples and need a native check.
