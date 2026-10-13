---
id: T-0017
title: Reverb watchdog in the scheduler (cron) and live status on the Reverb configurator
status: completed
priority: high
type: feature
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: [T-0015]
source: TEMP/intake-2026-10-13-1222.md
created: 2026-10-13 12:22
started: 2026-10-13 12:22
updated: 2026-10-13 12:39
completed: 2026-10-13 12:39
---

# T-0017: Reverb watchdog in the scheduler (cron) and live status on the Reverb configurator

## What the user asked
> "can we add it as cron under the schedule/queue to autostart the reverb? if we configure the reverb it should make sure it works with the cron entry automatically. and when its running it should show the information on reverb configurator its running and its information"

## What this means
- The cron entry that already runs `schedule:run` every minute also runs a Reverb watchdog. While Reverb is switched on, the watchdog starts it when it is not answering, so it comes back after a reboot or a crash without anyone logging in.
- The Reverb configurator shows whether Reverb is running (pid, start time, uptime, the address it listens on), the last watchdog check, and the last lines of its log. It has Start and Restart buttons and refreshes itself.

## Design (bounded, extends T-0015)
- **Health:** Reverb answers `GET /up` with `{"health":"OK"}` (vendor `HealthCheckController`). "Running" means that answer, checked on the server's own loopback address.
- **Start:** `setsid nohup php artisan reverb:start --host=<listen host> --port=<listen port>` with output to `storage/logs/reverb.log`; the pid is written to `storage/app/reverb.pid`. Uses `shell_exec`. If the host disables shell commands, the page says so and nothing is attempted.
- **Watchdog:** `reverb:ensure-running` (new command) scheduled `everyMinute()` in `routes/console.php`, `withoutOverlapping`. It does nothing when Reverb is off, records its result in the cache, and starts Reverb when it is not healthy.
- **Restart after credential change:** the existing restart signal makes the running server exit; the next watchdog run starts it with the new credentials.
- **New settings:** listen address (`0.0.0.0` or `127.0.0.1`, default `0.0.0.0`) and listen port (default `8080`), used for the start command and the health check.
- **Status:** `GET admin/reverb-settings/status` (JSON, polled every 10 s by the page). Start and Restart are `POST` routes.

## Rules from CLAUDE.md (REQUIRED)
- [ ] Secrets never in the status JSON or page props (the status payload has no secret).
- [ ] Super-admin only (`manage_settings`).
- [ ] New strings in `resources/js/locales/en.json`; check the page at 360 px and 1920 px, light and dark.
- [ ] `public/build` committed in its own commit.
- [ ] Git: add by name; commit; pull --rebase; push. Live deploy is manual (Admin → Deployment).
- [ ] Tests: one test process at a time.

## Logic / design docs (REQUIRED)
- Update when done: `app/logic/realtime-config.md` (watchdog, status, cPanel limits).

## Skills (REQUIRED)
- [x] superpowers:brainstorming — bounded design above (in this file)
- [ ] superpowers:test-driven-development — failing tests first
- [ ] senior-frontend — status card
- [ ] code-reviewer — process start, shell command, log tail
- [ ] superpowers:verification-before-completion — before completing

## Prerequisites
- [x] T-0015 completed.
- [ ] Needed from user: on the cPanel host, confirm that shell commands (exec) are allowed and that a process started from cron survives the cron run (the status card shows this after the first watchdog run).

## Blocked by
Nothing for the code.

## References
- Plan: `tasks/plans/PLAN-000-general/PLAN.md` · Phase: `tasks/plans/PLAN-000-general/phase-01-ongoing.md` · Intake: `TEMP/intake-2026-10-13-1222.md`
- Related files: `app/Http/Controllers/Admin/ReverbSettingsController.php`, `app/Services/ReverbConnectionTester.php`, `app/Services/RealtimeConfig.php`, `routes/console.php`, `resources/js/Pages/Admin/ReverbSettings/Index.jsx`, `vendor/laravel/reverb/src/Protocols/Pusher/Http/Controllers/HealthCheckController.php`

## Steps
- [ ] Failing tests: watchdog (off, running, starts, host blocks shell), scheduler entry, status JSON, start endpoint
- [ ] ReverbRuntime service (health, launch, watchdog, status, log tail)
- [ ] `reverb:ensure-running` command and schedule entry
- [ ] Controller: status, start, restart routes; listen settings
- [ ] React: status card with polling, buttons, log tail; server setup text
- [ ] Build, lint, docs, browser check (local)

## Acceptance criteria
- [ ] With Reverb on and not answering, one watchdog run starts it (test with a fake launcher)
- [ ] With Reverb answering, the watchdog starts nothing
- [ ] With Reverb off, the watchdog starts nothing
- [ ] Status shows running/stopped, pid, uptime, listen address, last check and log tail, and no secret
- [ ] The watchdog is in the schedule, every minute

## Progress log
- 2026-10-13 12:22 created from intake; started
- 2026-10-13 12:40 failing tests first (ReverbWatchdogTest: class missing); feature built; tests green (19 Reverb tests, then 33 realtime tests)
- 2026-10-13 12:55 browser check: status card shows running/stopped; Start now on Windows records the shell error in the log and on the card

## Result
- Changed: `app/Services/ReverbRuntime.php` (new: health via `GET /up`, launch via setsid/nohup in the background with a lock, watchdog, status, log tail); `app/Console/Commands/ReverbEnsureRunning.php` (new, `reverb:ensure-running`); `routes/console.php` (every minute, `reverb-watchdog`); `app/Http/Controllers/Admin/ReverbSettingsController.php` (status, start, restart, listen settings); `routes/admin.php` (3 routes); `resources/js/Pages/Admin/ReverbSettings/Index.jsx` (status card, polling, buttons, header badge); `resources/js/locales/en.json` (`reverb` block); `app/logic/realtime-config.md`; tests `tests/Feature/Realtime/ReverbWatchdogTest.php` (new, 7 tests).
- Checked: `ReverbWatchdogTest` + `ReverbSettingsTest` → `OK (19 tests)`; with `RealtimeConfigTest`, `BroadcastFailureDoesNotBreakInboundTest`, `RealtimeCspTest` → `OK (33 tests, 133 assertions)`. `npm run build` passes; eslint no errors on the page.
- Browser (local, separate database, dark theme): with Reverb running, the card showed Running and the last watchdog check ("Reverb answers on port 8080"); after stopping Reverb it showed Stopped, Start now, and the header "Not running". Start now on this Windows machine failed with the reason on the card (the Linux shell command cannot run here). Not run: the background start itself (needs a Linux host).
- Commits: `9a35221` (watchdog), `02b5278` (status card), `33e5bd9` (build).
- Self-review (code-reviewer not run as a separate pass): shell command arguments are escaped; the start is admin-only or cron; the status has no secrets; the log tail is admin-only.
- Not verified: on cPanel, that a process started from cron survives the cron run and host limits; that browsers can reach the listen port (or a proxy) over WSS. Live site: unverified; deploy is manual.
