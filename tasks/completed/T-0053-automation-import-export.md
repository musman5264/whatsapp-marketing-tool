---
id: T-0053
title: Import / Export button in the automation builder (JSON and Markdown)
status: completed
priority: medium
type: feature
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: [T-0052]
source: TEMP/intake-2026-10-24-1408.md
created: 2026-10-24 18:05
started: 2026-10-24 18:05
updated: 2026-10-24 18:40
completed: 2026-10-24 18:40
---

# T-0053: Import / Export button

## What the user asked
"add button next to the test to import/export the automation. so when clicked it will ask to export or import; import will take the exported automation to import into current system/profile; export will export the json format", and "also add option to export the flow in .md format".

## Decisions (assumptions)
Import creates a NEW draft in the current workspace and opens it (never overwrites the open flow). Secret header values are redacted on export. Markdown is a readable description (not importable).

## Rules from CLAUDE.md
- [x] Tests first (backend import 10, frontend 10); strings in en.json (+ ur.json); modal seen in a browser; npm run build committed on its own; logic doc app/logic/automation-import-export.md; commit by name and push; live behaviour unverified.

## Skills
- [x] test-driven-development, [x] senior-frontend; broad suites not run (user instruction)

## Result
Files: Builder.jsx (button, modal, handlers, Ask Question warning), lib/automationFile.js, AutomationController::import, route, en.json + ur.json, tests. Checked: AutomationImportTest OK (10), vitest automationFile + epos OK (20), eslint no new problems, modal looked at at 1500px. Not checked: 360px/dark of the modal, a real file round trip by hand, live.
