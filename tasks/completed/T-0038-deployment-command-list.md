---
id: T-0038
title: Deployment command list: queue:failed on the no-key list; write commands documented behind the second key
status: completed
priority: medium
type: feature
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-14-1320.md
created: 2026-10-14 13:20
started: 2026-10-14 13:20
updated: 2026-10-14 13:30
completed: 2026-10-14 13:30
---

# T-0038: Deployment command list

## What the user asked
- The command to run the failed webhook job again.
- Add the commands to the Deployment command list, including the ones suggested earlier.

## Result
- `queue:failed` added to the no-key allowlist, in `CommandAllowlist.php` and `public/deploy.php` (the test checks both match).
- Write commands stay behind the second key: `queue:retry`, `queue:flush`, the `--apply` variants of `media:fix-links` and `media:make-private`, `whatsapp-web:resubscribe`, `whatsapp-web:sync`.
- `app/logic/deployment-commands.md` has a table with each command, the key it needs, and when to use it.
- Tests: `CommandAllowlistTest` and `RollbackAndCommandTest`: 79 tests OK.

## Not done
- No command run on live. No failed job retried, flushed or deleted. Retrying now would re-send to a receiver that returns 401 (T-0037).
- The `public/deploy.php` change reaches the live server only after a deploy.

## Skills
- [x] superpowers:test-driven-development (refused cases added as data providers)
- [x] superpowers:verification-before-completion
