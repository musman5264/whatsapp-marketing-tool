---
id: T-0056
title: Builder pickers save only the last field (Run Sub-flow shows "Select an automation", run fails "No sub-flow selected.")
status: completed
priority: high
type: bug
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-26-1210.md
created: 2026-10-26 12:40
completed: 2026-10-26 13:00
---

# T-0056

## Root cause
`Builder.jsx` `set(key, val)` writes `{...d, [key]: val}` from the panel's render-time `d`, and `updateNodeData` replaces the node data. Pickers that call `set` twice in one click therefore keep only the last key: Run Sub-flow lost `automation_uuid` (kept `subflow_name`, so the node subtitle read "Menu Automation" while the select was empty and the engine said "No sub-flow selected."), Assign Agent lost `user_id`, WhatsApp template lost `template_name` and `language`.

## Rules from CLAUDE.md
- [x] Frontend: eslint on Builder.jsx (no new warnings; the 1 error + 3 warnings left were there before); `npm run build`, build committed on its own
- [ ] 360px / 1920px / dark check not done (no UI change, handler fix only); live check pending
- Skills: [x] superpowers:systematic-debugging, [x] verification-before-completion (lint + build; no JS test harness for the builder)

## Result
The three pickers now use `setMany` (one update). Nodes already saved wrong (subflow with a name but no id) must be picked again once. Not verified in a browser.
