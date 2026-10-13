---
id: T-0019
title: Reverb: manual start from the Deployment commands and a Start button on the Reverb page
status: completed
priority: high
type: feature
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: [T-0018]
source: TEMP/intake-2026-10-13-1257.md
created: 2026-10-13 12:57
started: 2026-10-13 12:57
updated: 2026-10-13 13:11
completed: 2026-10-13 13:11
---

# T-0019: Reverb: manual start from the Deployment commands and a Start button on the Reverb page

## What the user asked
> "add laravel reverb start command on deployment manual commands so we can manually start it. also add button on reverb page to start it manually"

## Design (bounded, approved by the request)
- `php artisan reverb:start` runs in the foreground and never exits. The Deployment runner waits for the remote command to finish (`app/Modules/Deployment/Services/DeployClient.php` `command()`, 120 s), and the remote `public/deploy.php` `cmd` action waits on `proc_open`. So the vendor command cannot be run from there. Add an app command `reverb:launch` that calls `ReverbRuntime::launch()` (the same call the Start button uses), waits for the health check (up to 8 s) and exits.
- Allowlist `php artisan reverb:launch` in three places: `DeploymentController::runCommand()` `$allow`, the Deployment page quick-command list, and `public/deploy.php` `$allowedPrefixes`. Without the last one the remote server asks for the deploy key again.
- Reverb page: **Start now** shows when credentials are saved, Reverb does not answer, and shell commands are allowed. It also shows while the Reverb switch is off. A start does not switch realtime over.
- A start while the switch is off must load the saved app, so `ReverbSettingsServiceProvider::apply()` copies the server app config whenever credentials are complete. Only the broadcaster default still follows the switch.
- `ReverbRuntime::launch()` refuses to start when credentials are incomplete, so a process is never started with the `.env` values.

## Shared file warning
- `public/deploy.php` has an uncommitted change from another session (`$DEPLOY_KEY` falls back to a built-in key). It must **not** be committed. This task commits only the allowlist line, staged on top of `HEAD`.

## Rules from CLAUDE.md
- [x] `git add` by name only; never `git add -A`; never commit another session's files (`WHATSMINE-CAPTURE-SPEC.md`, `public/deploy.php` other hunks, `tasks/pending/T-0014-…`).
- [x] `resources/js` changed → `npm run build`, build output committed in its own commit (`public/build`).
- [x] New user-facing strings go into `resources/js/locales/en.json`.
- [x] Reverb/realtime docs updated in `app/logic/realtime-config.md`.
- [x] PHPUnit: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit …`; one test process at a time.
- [x] Known failing baseline (`TypingEndpointTest` etc.) compared before blaming this change.
- [x] No secrets in repo, props or logs.
- [x] Super-admin pages stay behind `manage_settings` / super-admin guard.

## Logic / design docs
- `app/logic/realtime-config.md` (Reverb section, watchdog section): add the manual start and the command.

## Skills
- [x] superpowers:brainstorming — classified bounded; design above (approved by the request).
- [x] superpowers:test-driven-development — tests written first; 3 of 5 failed before the code.
- [x] code-reviewer — scan rules applied by hand to the diff (RBAC, command execution, shell use). Found the allowlist prefix issue listed under Result; not fixed here.
- [x] superpowers:verification-before-completion — evidence in Result.
- [ ] superpowers:writing-plans — not needed: bounded, three steps.

## Acceptance criteria
1. `php artisan reverb:launch` starts Reverb in the background with saved credentials, prints the result and exits 0 (test). Without credentials it exits 1 and starts nothing (test).
2. The Deployment runner sends `php artisan reverb:launch` without a deploy key (test in `tests/Feature/Deployment/RollbackAndCommandTest.php`), and `public/deploy.php` allowlists it (grep check).
3. Reverb page: Start now appears with the switch off when credentials are saved and Reverb is not answering (browser check at 360 px and 1920 px, light and dark; or reported as not checked).
4. A start with the switch off loads the saved app and leaves `broadcasting.default` unchanged (test).
5. `tests/Feature/Realtime` and `tests/Feature/Deployment` run; only baseline failures remain.
6. `npx eslint` and `vendor/bin/phpstan analyse` on the changed files; build committed separately.

## Progress log
- 2026-10-13 12:57 created from intake. Design written above (bounded; the request names both changes).
- 2026-10-13 12:57 tests written first; ReverbLaunchCommandTest, ReverbWatchdogTest, ReverbSettingsTest, RollbackAndCommandTest run: 3 of 5 new tests failed as expected.
- 2026-10-13 13:11 implemented, checked, committed (ea875d1, 818ebc2); browser check on the local server (see Result).

## Result
- Changed:
  - `app/Console/Commands/ReverbLaunch.php` (new): `php artisan reverb:launch` starts Reverb in the background through `ReverbRuntime::launch()`, prints the result, exits 0 or 1. It returns in seconds, unlike `reverb:start`, which runs in the foreground.
  - `app/Services/ReverbRuntime.php`: `launch()` refuses to start without complete credentials, before any shell command. Manual-start message is now "Started manually.".
  - `app/Modules/Deployment/Http/Controllers/DeploymentController.php`: `php artisan reverb:launch` on the `runCommand()` allowlist.
  - `public/deploy.php`: one line in `$allowedPrefixes`. Committed alone, staged on top of `HEAD`. The other session's `$DEPLOY_KEY` hunk is still uncommitted and was not touched.
  - `resources/js/Pages/Admin/Deployment/Index.jsx`: the command in the quick-command list.
  - `resources/js/Pages/Admin/ReverbSettings/Index.jsx`: Start now shows when credentials are saved and Reverb does not answer, also while the Reverb switch is off. A hint shows when credentials are missing.
  - `resources/js/locales/en.json`: `reverb.start_needs_settings`.
  - `app/logic/realtime-config.md`: manual-start paragraph.
  - Tests: `tests/Feature/Realtime/ReverbLaunchCommandTest.php` (new, 2), `ReverbWatchdogTest.php` (+2), `ReverbSettingsTest.php` (+1, pins that the saved app loads while the switch is off), `tests/Feature/Deployment/RollbackAndCommandTest.php` (+2).
  - Build: `public/build` regenerated in its own commit (`818ebc2`).
- Design note: `ReverbSettingsServiceProvider::apply()` already loads the saved app whenever credentials are complete, so it needed no change. The new test pins that.
- Red first: 3 of the 5 new tests failed before the code (command missing; runner returned 422 for the command; remote allowlist line absent). The other two pin existing behaviour.
- Checked:
  - `tests/Feature/Realtime`: Tests 55, Assertions 178, Failures 2. Both failures are `TypingEndpointTest`, on the baseline list.
  - `tests/Feature/Deployment`: OK (20 tests, 58 assertions).
  - `php -l public/deploy.php` and `php -l app/Console/Commands/ReverbLaunch.php`: no syntax errors.
  - `npx eslint` on the Reverb page: clean. On the Deployment page: 9 problems (7 errors, 2 warnings), the same count as the committed `HEAD` version, so not from this change.
  - `phpstan` on the three changed PHP files: 5 findings, all in `ReverbRuntime.php` in methods from T-0017/T-0018 (return types and an always-false condition). None in `ReverbLaunch.php` or `DeploymentController.php`. Not fixed here.
  - Browser, local (database `wm3_browser`, port 8099, dark theme): switch off and credentials saved, so Start now is visible at 360 px and at 1920 px. Clicking it on Windows showed "The start command did not return a process id.", which then became the last watchdog check (expected on Windows). With the host cleared, the button disappeared and the hint showed; host restored afterwards. Light theme not checked.
- Commits: `ea875d1` (feature, tests, docs), `818ebc2` (build), then the tracker commit.
- Not verified:
  - A start on the Linux host: background process, and `PHP_BINARY` under the web PHP handler, which may not be the CLI binary, so the button could fail where the command works. Windows only ran the failure path.
  - The Deployment command on the live server. The live `public/deploy.php` needs this deploy's allowlist line first; until then the server asks for the deploy key.
  - Light theme.
- Found, not fixed (outside this task, found by reading the code, not exercised):
  - The allowlist is a prefix match, both in `DeploymentController::runCommand()` and in `public/deploy.php`. The command runs through a shell (`proc_open` with a string). So `php artisan optimize:clear; <anything>` passes without the second key. Needs an exact-match or no-shell-metacharacter check.
  - The other session's uncommitted `public/deploy.php` hunk sets `$DEPLOY_KEY` to the built-in string `esystematicTechnologies` when the config has none. Do not commit or deploy it without review.

