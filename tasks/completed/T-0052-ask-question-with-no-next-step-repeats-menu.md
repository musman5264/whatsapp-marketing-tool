---
id: T-0052
title: Ask Question with nothing after it restarted the flow and repeated the menu on every reply
status: completed
priority: high
type: bug
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-24-1408.md
created: 2026-10-24 17:40
started: 2026-10-24 17:40
updated: 2026-10-24 17:50
completed: 2026-10-24 17:50
---

# T-0052: Ask Question with nothing after it repeated the menu

## Root cause (code read, user screenshots)
`executeAskQuestion()` parked the run with `resume_node_id = null` when no step was connected after it. `executeRun()` treats a null resume step as a fresh start, so each reply ("1", "3", "R") restarted the flow at the trigger and sent the menu again. Runs parked this way before the fix, and any run waiting from earlier tests, also swallowed the next message (any text) because `resumeAwaitingReplies()` resumes every waiting run of the contact regardless of the keyword filter.

## Rules from CLAUDE.md
- [x] Systematic debugging; test first (2 tests red, then green); logic doc written (app/logic/automation-ask-question.md); commit by name and push; live behaviour reported unverified.

## Skills
- [x] systematic-debugging, [x] test-driven-development, [ ] verification-before-completion (targeted tests only, by the user's instruction)

## Result
Fix in `AutomationEngine`: Ask Question does not park when nothing follows it; `resumeAwaitingReplies()` ends an already-parked run that has no step to resume at. Tests: `AutomationNodeBehaviourTest` (2 new) + `BranchFallbackTest` ask_question: OK. Not checked live. Builder warning for an unconnected Ask Question: see T-0053 commit.
