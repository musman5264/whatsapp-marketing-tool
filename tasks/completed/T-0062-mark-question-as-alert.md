---
id: T-0062
title: Mark an Ask Question as an alert: badge, E-POS answer picker lists it first, flow check warns when no E-POS step sends it
status: completed
priority: medium
type: feature
plan: PLAN-000 (general)
phase: phase-01-ongoing
source: TEMP/intake-2026-10-26-1318.md
created: 2026-10-26 18:00
completed: 2026-10-26 18:30
---

# T-0062

Request: "mark that answer/question as alert so it must be clear that this specific question must be sent to epos". Design chosen by the user: mark + link + warn (the other options were "send automatically" and "badge only").

## Result
See `app/logic/epos-integration.md` (section "Marking a question as an alert"). eslint: same 1 error + 3 warnings as before; `npm run build` ok; example flow `app/logic/examples/epos-order-alert.flow.json` has the switch on. No test written (frontend only; the builder has no test harness for this). Not looked at in a browser at 360/1920px or dark mode; live check pending.
