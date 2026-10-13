---
id: T-0022
title: composer.lock is out of date with composer.json (deploy runs composer install)
status: pending
priority: medium
type: chore
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: []
source: TEMP/intake-2026-10-13-1318.md
created: 2026-10-13 13:40
updated: 2026-10-13 13:40
---

# T-0022: composer.lock is out of date with composer.json (deploy runs composer install)

## What was checked
- `composer validate --no-check-publish --no-check-all` (local): "composer.json is valid but your composer.lock has some errors. The lock file is not up to date with the latest changes in composer.json."
- `laravel/reverb v1.10.0` is in the lock's packages, and in `require` in composer.json.
- Deploy runs `composer install --no-dev --optimize-autoloader --no-interaction` (`public/deploy.php`, lines near 299 and 480).

## What it may cause
Composer warns and installs from the lock, so the deploy probably still works. It is not verified on the live host. The earlier live log `Command "reverb:start" is not defined` shows the server's vendor folder lacked the Reverb package at that time. Whether that came from this mismatch is not known.

## Plan
1. Look at what differs (for example `composer update --lock --dry-run`, or the `content-hash` change), without upgrading any package.
2. Run `composer update --lock` only if the diff is only the hash. Check `git diff composer.lock` before committing.
3. Ask the user to run the Deployment page's "composer install" once and check `composer validate` output on the server.

## Rules from CLAUDE.md
- [ ] `composer.lock` changes committed by name only; no package upgrades.

## Progress log
- 2026-10-13 13:40 created from the Reverb investigation.

## Result
(not finished)
