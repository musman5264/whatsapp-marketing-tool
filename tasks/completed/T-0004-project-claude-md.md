---
id: T-0004
title: Port mnrdev CLAUDE.md structure into this project and amend it
status: completed
priority: high
type: chore
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: []
source: TEMP/intake-2026-10-09-1505.md
created: 2026-10-09 16:20
started: 2026-10-09 16:20
updated: 2026-10-09 16:20
completed: 2026-10-09 16:35
---

# T-0004: Project CLAUDE.md modelled on mnrdev

## What the user asked
> check the mnrdev project's clauds main instruction how it has been written and add in your proejct and then ammend it according to your proejct. always use the task manager skills ... superpowers ... and other plugins and skills.

## What this means
CLAUDE.md in this repo gets the mnrdev structure (read-first, task tracking, never guess, git, logic docs, testing) with mnrdev-only rules replaced by verified facts about this project. Design approved by the user 2026-10-09 ("go ahead").

## Rules from CLAUDE.md
- [ ] Every rule written is verified against the repo (never guess)
- [ ] Commit by file name and push to main

## Logic / design docs
- Read before work: D:\Dev\projects\mnrdev\CLAUDE.md, tasks/INSTRUCTIONS.md
- Update when done: app/logic/README.md (new)

## Skills
- [x] superpowers:brainstorming — bounded design approved in chat
- [ ] superpowers:verification-before-completion

## Acceptance criteria
- [ ] CLAUDE.md covers the 10 approved points; no mnrdev-only rule copied
- [ ] app/logic/README.md exists and is tracked (docs/ is git-ignored)
- [ ] INSTRUCTIONS.md points at CLAUDE.md; memory deploy note updated

## Progress log
- 2026-10-09 16:20 created; design approved

## Result
CLAUDE.md rewritten from mnrdev structure (10 approved points, facts verified: Laravel 12.54.1, php ^8.2, 17 locale files, test failure list, deploy check). Added app/logic/ (README + realtime-config + waha-sync-and-labels + waha-api-coverage copy), INSTRUCTIONS.md updated. Deploy tracked as T-0005.
