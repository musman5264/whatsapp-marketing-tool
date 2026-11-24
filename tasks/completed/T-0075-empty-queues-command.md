---
id: T-0075
title: Clear failed jobs and empty all queues from the Deployment Run box
status: completed
plan: PLAN-000
phase: phase-01
priority: high
depends_on: none
started: 2026-11-24 15:50
completed: 2026-11-24 16:05
---

## Design (assumptions, user asked "clear the failed queue and make the queue empty")
New `php artisan queue:empty-all` (Deployment module): dry run by default; `--apply` deletes failed jobs and waiting jobs on queues default, whatsapp, broadcast, automation, social, leads, ai of the default connection and the old `database` one; `--failed-only` skips waiting jobs. Stays behind the deploy key (not on the no-key list); the page asks for confirmation before `--apply`.

## Acceptance criteria
- [x] Dry run deletes nothing; --apply empties jobs + failed_jobs; --failed-only keeps waiting jobs. Check: EmptyQueuesCommandTest (3, red first).
- [x] Still refused without the second key. Check: CommandAllowlistTest (new row).
- [x] Three options in the Run box, confirm before --apply. Check: eslint (no new errors; Toggle/Field errors are old), build ok. Browser check at 360/1920px, light/dark NOT done.
- [ ] Live: user runs the dry run, then --apply, after deploying. Redis path (ClearableQueue on redis) untested locally.

## Rules from CLAUDE.md
- [x] en.json string; build committed on its own; logic doc; commit by name. [ ] 360/1920px browser check not done.

## Skills
- [x] task-tracker, [x] test-driven-development, [x] verification-before-completion (partial: no browser)

## Result
Files: EmptyQueuesCommand.php, DeploymentServiceProvider.php, Deployment/Index.jsx, en.json, tests, deployment-commands.md. Nothing was run against live data.
