---
id: T-0015
title: Super-admin Reverb settings: auto configure, test, on/off toggle (cPanel-aware)
status: completed
priority: medium
type: feature
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: []
source: TEMP/intake-2026-10-13-1132.md
created: 2026-10-13 11:32
started: 2026-10-13 11:41
updated: 2026-10-13 12:13
completed: 2026-10-13 12:13
---

# T-0015: Super-admin Reverb settings: auto configure, test, on/off toggle (cPanel-aware)

## What the user asked
> "add reverb auto configuration in super admin level in integration or separate like pusher settings"
> "add option to auto configure the reverb and toggle on and off."

## What this means
A super-admin page, next to Pusher Settings, where Reverb can be set up without editing files on the server: generate its app ID, key and secret, check that the Reverb server answers, and switch Reverb on or off as the realtime server. The page is honest about cPanel: it cannot start the Reverb process.

## Design (approved by the user's message)
- Separate page `/admin/reverb-settings` (permission `manage_settings`), same pattern as Pusher.
- **Generate credentials**: the server creates an app ID, key and secret. Nothing is saved until Save.
- **Defaults**: host from `APP_URL`, port 443, scheme https. The host may differ from the site (a VPS subdomain).
- **Secret**: stored encrypted through `SystemSetting`, shown masked.
- **Test connection**: sends a test event through Reverb's HTTP API with the saved credentials. It checks server-to-Reverb only.
- **Toggle on**: allowed only when the credentials are complete AND the test passes in the same request. **Toggle off**: always allowed, and realtime returns to the previous behaviour (Pusher or .env).
- **Apply**: a provider copies the saved values into the runtime config on each request (like Pusher); a saved change also sends the `reverb:restart` signal (`Cache::forever('laravel:reverb:restart')`, shared through the database cache) so a running Reverb process reloads its app credentials.
- **cPanel note on the page**: Reverb is a long-running process. Shared cPanel hosting only runs cron, so Reverb must run on a VPS (or another host) that the browser can reach over wss.

## Rules from CLAUDE.md (REQUIRED)
- [ ] Pusher key is public; the secret never goes to the frontend (only key, host, port, scheme are shared).
- [ ] New user-facing strings in `resources/js/locales/en.json`; check the page at 360 px and 1920 px, light and dark.
- [ ] Super-admin only (`manage_settings`).
- [ ] `public/build` committed in its own commit when `resources/js` changes.
- [ ] Git: add by name; commit; pull --rebase; push. Live deploy is manual (Admin → Deployment).
- [ ] Tests: one test process at a time.

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/realtime-config.md`, `app/logic/README.md`
- Update when done: `app/logic/realtime-config.md` (Reverb admin settings, toggle rules, cPanel note)

## Skills (REQUIRED)
- [x] superpowers:brainstorming — bounded design approved by the user (see the intake)
- [ ] superpowers:test-driven-development — failing tests first
- [ ] senior-frontend — React page
- [ ] code-reviewer — admin endpoints (secret handling, test endpoint host input)
- [ ] superpowers:verification-before-completion — before completing

## Prerequisites
- [ ] Tasks: T-0016 is independent; T-0014 (live checks) is not needed for the code.
- [ ] Needed from user: nothing for the code. Turning Reverb on for the live site needs a reachable Reverb server (not this host).

## Blocked by
Nothing.

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-000-general/PLAN.md` · Phase: `tasks/plans/PLAN-000-general/phase-01-ongoing.md` · Intake: `TEMP/intake-2026-10-13-1132.md`
- Related files: `app/Http/Controllers/Admin/PusherSettingsController.php`, `app/Providers/PusherSettingsServiceProvider.php`, `app/Services/RealtimeConfig.php`, `resources/js/Pages/Admin/PusherSettings/Index.jsx`, `routes/admin.php`, `resources/js/Layouts/AdminLayout.jsx`, `config/broadcasting.php`, `config/reverb.php`

## Steps
- [ ] RealtimeConfig: admin Reverb credentials + active flag; resolve() uses them when active
- [ ] Provider: apply saved Reverb values to broadcasting and reverb app config at boot
- [ ] Controller: index (masked secret), generate, update (validate), test, toggle (on needs passing test), restart signal
- [ ] Routes under manage_settings
- [ ] React page (mirror Pusher), nav entry, locale keys
- [ ] Tests for RealtimeConfig, provider, controller
- [ ] Build, lint, docs

## Acceptance criteria
- [ ] Generate returns app ID, key and secret; nothing is saved until Save
- [ ] Toggle on fails with a clear message when Reverb does not answer; toggle off always works
- [ ] Secret is encrypted in the database and never in the page props or JSON responses
- [ ] Non-super-admin gets 403
- [ ] Page checked at 360 px and 1920 px, light and dark (browser, local)

## Progress log
- 2026-10-13 11:41 created from intake; approved by the user's message; started
- 2026-10-13 12:20 done locally; end-to-end checked in the browser; commits 65bf13e, 3814347 (+ secret fix 2d76b18)

## Result
- Changed: `app/Services/RealtimeConfig.php` (`adminReverbCredentials`, `reverbActive`, Reverb-first branch in `resolve`); `app/Providers/ReverbSettingsServiceProvider.php` (new, registered after Pusher in `bootstrap/providers.php`); `app/Services/ReverbConnectionTester.php` (new); `app/Http/Controllers/Admin/ReverbSettingsController.php` (new: index, generate, update, toggle, test, restart signal); `routes/admin.php` (5 routes, `manage_settings`); `resources/js/Pages/Admin/ReverbSettings/Index.jsx` (new); `resources/js/Layouts/AdminLayout.jsx` (sidebar entry); `resources/js/locales/en.json` (`reverb` block, `admin.reverb_settings`); `app/logic/realtime-config.md`; test `tests/Feature/Realtime/ReverbSettingsTest.php` (new, 12 tests). Also fixed: `app/Models/SystemSetting.php` + migration `2026_10_13_120000_encrypt_plaintext_system_secrets.php` (see the second commit below).
- Checked: `ReverbSettingsTest` → `OK (12 tests, 71 assertions)`. `npm run build` passes; eslint: no errors in the new page (one existing warning in AdminLayout.jsx line 82).
- Browser (local server, separate database, 1920 px, dark theme): the page renders; Generate fills the fields; Save shows "Reverb settings saved." and the browser endpoint; Test with no Reverb running → "Could not reach Reverb at 127.0.0.1:8080 (…)"; with Reverb running → "Reverb accepted the test event."; toggle → "Reverb is now the realtime server.", status Active.
- End to end (local): the open chat connected to Reverb (state `connected`); a real inbound WhatsApp webhook for the open thread's contact appeared in the page without a reload (same document, marker kept).
- Commits: `65bf13e` (feature), `3814347` (build), `2d76b18` (secret fix), `a434e48` (live sync fix). Pushed.
- Live: not deployed yet. After the deploy, the migration repairs stored secrets. On this host Reverb cannot run from cPanel; the page says so. For the live site use hosted Pusher, or Reverb on a VPS with the host set in the page.
- Not verified: the browser WebSocket through a real public proxy (only local); the Reverb process on a VPS.
