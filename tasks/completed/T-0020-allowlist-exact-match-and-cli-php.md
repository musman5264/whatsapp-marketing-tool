---
id: T-0020
title: Security: exact-match command allowlist, no built-in deploy key, Reverb starts with the command-line PHP
status: completed
priority: high
type: security
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: [T-0019]
source: TEMP/intake-2026-10-13-1318.md
created: 2026-10-13 13:18
started: 2026-10-13 13:18
updated: 2026-10-13 13:40
completed: 2026-10-13 13:40
---

# T-0020: Security: exact-match command allowlist, no built-in deploy key, Reverb starts with the command-line PHP

## What the user asked
> "Security: the command allowlist only checks that a command starts with an allowed phrase, and the command then runs through a shell. An allowed command followed by ; and anything else runs without the second key. Both the app and public/deploy.php have this … Another session's uncommitted change in public/deploy.php sets a built-in fallback deploy key (esystematicTechnologies) … Review it before it goes anywhere. Start on the live host is unproven. The button uses PHP's PHP_BINARY … fix them also"

## Design (bounded fix)
1. **Allowlist.** One rule, applied on the app side and in `public/deploy.php`:
   - Command is trimmed and must match only `A-Z a-z 0-9`, space, `_ . : = / -`. Anything else (`; & | < > $ \` ( ) ' " \` and newlines) is refused.
   - Either an exact entry from the list (`git status`, `git log`, `git log --oneline -20`, `git branch`, `composer install --no-dev`, `composer install --no-dev --optimize-autoloader`), or `php artisan <name>` where `<name>` is one of the allowed artisan commands, optionally followed by arguments.
   - Refused commands need the second key (existing behaviour); the app forwards it, the script checks it.
   - The app's list and the script's list must be identical; a test reads `public/deploy.php` and compares them.
2. **Fallback key.** `public/deploy.php` line 33 goes back to HEAD (`?? ''`), so the existing refusal (503 when the key is empty) applies again. The other session's one-line hunk is saved as a patch before it is reverted. The key string in `WAHA-EXPANSION-PLAN.md` is replaced with a placeholder. The key stays in git history: rotating it is the user's call.
3. **PHP binary.** `ReverbRuntime::cliBinary()` returns the command-line PHP (Symfony `PhpExecutableFinder`, which ignores the web SAPI's `PHP_BINARY`), falling back to `PHP_BINARY`. Used by `launch()`. `CronSetupController` checked and changed if it shows the web binary.

## Rules from CLAUDE.md
- [x] `git add` by name; never commit another session's files. The other session's hunk is not committed; it is reverted in the working tree and saved as a patch first.
- [x] Never write secrets into the repo: the key placeholder replaces the literal.
- [x] PHPUnit: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit …`; one test process at a time.
- [x] Logic docs updated: `app/logic/realtime-config.md` (PHP binary), new `app/logic/deployment-commands.md` (allowlist), `app/logic/README.md` index.
- [x] No `resources/js` change expected; if one happens, build and commit `public/build` separately.
- [x] Known failing baseline (`TypingEndpointTest`) compared before blaming this change.

## Skills
- [x] superpowers:systematic-debugging — phases 1–2 done (reading above).
- [x] superpowers:test-driven-development — tests written first; 26 failed before the code.
- [x] code-reviewer — scan by hand of the allowlist and key handling: the lists match, no shell characters pass, no built-in key. Found nothing new beyond the items in Result.
- [x] superpowers:verification-before-completion — evidence in Result.

## Acceptance criteria
1. Each injection string (`optimize:clear; …`, `&&`, `|`, backticks, `$()`, redirect, newline, `migrate:fresh`, `optimize:clearx`) is refused without the second key on the app side and in `deploy.php` (tests).
2. Allowed commands from the Deployment page still run without the second key (tests).
3. `deploy.php` with no `DEPLOY_KEY` in the config refuses every request with 503 (test).
4. The allowlist in `deploy.php` equals the app's list (test).
5. `ReverbRuntime::cliBinary()` returns an existing executable (test). Live host: not verifiable from here.
6. Test suites `tests/Feature/Deployment`, `tests/Feature/Realtime` pass apart from baseline.

## Progress log
- 2026-10-13 13:18 created from intake; design above; approved by the request (bounded).
- 2026-10-13 13:18 read the code: fallback key line; allowlist prefix match on both sides; `PHP_BINARY` in the start command and on the Cron Setup page.
- 2026-10-13 13:30 tests written first (CommandAllowlistTest, ReverbLaunchCommandTest); 26 failed as expected.
- 2026-10-13 13:40 implemented; commits 835db3e (allowlist, key) and 508b184 (CLI binary); tracker closed.

## Result
- Changed:
  - `app/Modules/Deployment/Services/CommandAllowlist.php` (new): the one rule. Plain characters only (letters, digits, space, `_ . : = / -`). Then either an exact entry, or `php artisan <name>` with a name from the list, optionally followed by plain arguments. `DeploymentController::runCommand()` uses it instead of the prefix match.
  - `public/deploy.php`: the same rule in `$allowedExact` and `$allowedArtisan`. The fallback key is back to the committed `?? ''`, so no key means 503 again. The other session's one-line fallback hunk was saved as a patch before the revert (`scratchpad/other-session-deploy-key.patch` in this session's temp folder, not in the repo).
  - `WAHA-EXPANSION-PLAN.md`: the three literal uses of the key replaced by `<DEPLOY_KEY>`. The key stays in git history: rotate it if it is used anywhere.
  - `app/Services/CliPhpBinary.php` (new): Symfony's `PhpExecutableFinder` first, `PHP_BINARY` as the fallback. Used by `ReverbRuntime::launch()` and by `CronSetupController` (the cron line shown to the admin).
  - Docs: `app/logic/deployment-commands.md` (new, indexed in `app/logic/README.md`), `app/logic/realtime-config.md` (start binary, live reachability check).
  - Tests: `tests/Feature/Deployment/CommandAllowlistTest.php` (new): 13 refused patterns checked against the app and against a copy of the remote script; allowed commands run without the second key; the two lists match; no built-in key; no config key means 503. `tests/Feature/Admin/CronSetupBinaryTest.php` (new). `ReverbLaunchCommandTest` checks the binary. `RollbackAndCommandTest` lost its literal-string check, which the list-match test replaces.
- Red first: 26 failures before the code (all refused patterns on both sides, the list match, the no-key case, the key literal in the repo files).
- Checked:
  - `tests/Feature/Deployment`: OK (71 tests, 169 assertions).
  - `tests/Feature/Realtime`: 56 tests, 2 failures, both `TypingEndpointTest` (baseline).
  - `tests/Feature/Admin/CronSetupBinaryTest.php`: OK (1 test).
  - `php -l` on `public/deploy.php` and the new classes: no syntax errors.
  - PHPStan on the changed PHP files: 5 findings, all pre-existing in `ReverbRuntime.php` (T-0017/T-0018). None in the new or changed files.
- Commits: `835db3e` (allowlist, key, docs), `508b184` (command-line PHP), then the tracker commit.
- Not verified:
  - `CliPhpBinary` on the live cPanel host: which binary the finder returns there.
  - The live deploy: after a deploy, `public/deploy.php` on the server has the new rule. Refusal without a key is unchanged, because the committed version already had `?? ''`.
- Live finding (same day, from the admin page screenshot): the Start button now starts Reverb, but browsers still cannot reach it. Tracked in T-0021.

