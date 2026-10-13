---
id: T-0018
title: Reverb: turning it on starts it; automatic start switch; turning it off stops it
status: completed
priority: high
type: bug
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: [T-0017]
source: TEMP/intake-2026-10-13-1245.md
created: 2026-10-13 12:45
started: 2026-10-13 12:45
updated: 2026-10-13 12:52
completed: 2026-10-13 12:52
---

# T-0018: Reverb: turning it on starts it; automatic start switch; turning it off stops it

## What the user asked
> "its still not working? add option there to configure it start the reverb automaticaly. if its toggle on then it will automatically check the reverb via cron and start it automatically. otherwise it will remain off."

## What this means
- Switching Reverb on starts it right away, then tests it, then switches realtime to Reverb. Today the switch refuses when Reverb is not running, and nothing starts it while the switch is off.
- A new switch, **Start Reverb automatically**: while Reverb is on and this switch is on, the cron checks every minute and starts Reverb if it stopped. With this switch off, Reverb only runs when someone presses Start now.
- Switching Reverb off stops the Reverb process this panel started, so it stays off.
- The status card shows whether a browser can reach Reverb (a public check), because the live site is served over HTTPS.

## Diagnosis (systematic-debugging phases 1-2, from the code and the live page)
- `ReverbSettingsController::toggle` (on) runs `ReverbConnectionTester::check()` first. The test needs Reverb answering on its listen port, and the page shows Reverb off.
- `ReverbRuntime::watchdog()` starts Reverb only when `RealtimeConfig::reverbActive()` is true, which needs the switch on. Neither step can start Reverb first, so the switch never turns on.
- The live page shows the watchdog runs from cron (last check 07:43 UTC, "Reverb is off"), so the scheduler works on the host.

## Rules from CLAUDE.md (REQUIRED)
- [ ] No secrets in the status payload or page props.
- [ ] Super-admin only (`manage_settings`).
- [ ] New strings in `resources/js/locales/en.json`; check the page at 360 px and 1920 px, light and dark.
- [ ] `public/build` committed in its own commit.
- [ ] Git: add by name; commit; pull --rebase; push. Live deploy is manual (Admin → Deployment).
- [ ] Tests: one test process at a time.

## Logic / design docs (REQUIRED)
- Update when done: `app/logic/realtime-config.md` (watchdog, automatic start, toggle start and stop).

## Skills (REQUIRED)
- [x] superpowers:systematic-debugging — phases 1-2 done (deadlock above)
- [ ] superpowers:test-driven-development — failing tests first
- [ ] superpowers:verification-before-completion — before completing

## Prerequisites
- [x] T-0017 completed.
- [ ] Needed from user: deploy after the push (Admin → Deployment → Deploy latest commit).

## Blocked by
Nothing for the code.

## References
- Plan: `tasks/plans/PLAN-000-general/PLAN.md` · Phase: `tasks/plans/PLAN-000-general/phase-01-ongoing.md` · Intake: `TEMP/intake-2026-10-13-1245.md`
- Related files: `app/Http/Controllers/Admin/ReverbSettingsController.php`, `app/Services/ReverbRuntime.php`, `app/Services/ReverbConnectionTester.php`, `resources/js/Pages/Admin/ReverbSettings/Index.jsx`, `tests/Feature/Realtime/ReverbWatchdogTest.php`, `tests/Feature/Realtime/ReverbSettingsTest.php`

## Steps
- [ ] Failing tests: toggle on starts first; toggle on refused when Reverb cannot start; automatic start off → watchdog waits; toggle off stops; status has autostart and public check
- [ ] ReverbRuntime: autostart(), stop(), publicReachable(); watchdog respects autostart
- [ ] Controller: toggle on starts first; toggle off stops; autostart setting; status fields
- [ ] React: autostart switch, status text for each state, public-reachability line, hints
- [ ] Build, lint, docs, browser check (local)

## Acceptance criteria
- [ ] Turning on with Reverb down starts it (test with a fake launcher) and switches realtime to Reverb
- [ ] Turning on when the start fails leaves it off, with the reason shown
- [ ] With automatic start on, the watchdog starts a stopped Reverb (existing test); with it off, it does not and reports "waiting"
- [ ] Turning off stops a Reverb this panel started
- [ ] Status shows automatic start and whether browsers can reach Reverb, with no secret

## Progress log
- 2026-10-13 12:45 created from intake; started
- 2026-10-13 13:05 failing tests first (4 red); fixed; 32 realtime tests green
- 2026-10-13 13:20 browser check (local): automatic start switch saves; turning on with Reverb down is refused with the reason

## Result
- Changed: `app/Services/ReverbRuntime.php` (autostart switch in the watchdog, `stop()`, `publicReachable()`, status fields); `app/Http/Controllers/Admin/ReverbSettingsController.php` (toggle on starts first, toggle off stops, `reverb_autostart` setting, restart on save while running); `resources/js/Pages/Admin/ReverbSettings/Index.jsx` (automatic start switch, stopped-manual status, reachability line); `resources/js/locales/en.json`; `app/logic/realtime-config.md`; tests `ReverbWatchdogTest.php` (+4), `ReverbSettingsTest.php` (runtime stand-in; restart assertion moved).
- Checked: `ReverbWatchdogTest` + `ReverbSettingsTest` + `RealtimeConfigTest` → `OK (32 tests, 135 assertions)`. Realtime and settings group: 64 tests, 2 failures, both `TypingEndpointTest` (baseline). `npm run build` passes; eslint clean on the page.
- Browser (local): automatic start switch saves (aria-pressed false after the click); turning Reverb on with nothing running is refused with "Reverb could not be started, so it was not turned on".
- Commits: `99d9d90` (behaviour), `37e57ed` (page), `bc786ed` (build). Pushed with the tracker commit.
- Live: needs a deploy (Admin → Deployment). Not verified on the live host: that the start works there (exec, process survival) and that browsers can reach the port.
