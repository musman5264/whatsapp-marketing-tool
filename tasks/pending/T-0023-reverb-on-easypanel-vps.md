---
id: T-0023
title: Run Reverb on the Easypanel VPS; cPanel uses it as an external realtime server
status: blocked
priority: high
type: feature
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: [T-0021]
source: TEMP/intake-2026-10-13-1318.md
created: 2026-10-13 13:55
updated: 2026-10-13 14:03
---

# T-0023: Run Reverb on the Easypanel VPS; cPanel uses it as an external realtime server

## What the user decided
> "keep the pusher for the time being i will add reverb on another vps i have. and reverb will be working under the easypanel. main is cpanel but reverb will be on easypanel"

## What is already in the repo
- `docker/supervisor/app.conf` runs `php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080` next to Apache in the same container. So the Docker image already starts Reverb.
- The admin Reverb page already stores app ID, key, secret, host, port and scheme, and runs Test Connection. The browser endpoint is built from those.

## What is still missing (design needed before code: architectural, two hosts)
1. **Public route.** Easypanel must send `https://<vps-domain>/app/…` (WebSocket), `/apps/…` (events API, which cPanel calls) and `/up` to container port 8080. Set up in Easypanel. Not code.
2. **Same credentials on both sides.** The cPanel admin settings and the VPS Reverb must share app ID, key and secret. The VPS container has its own database, so the values are entered on its admin page, or set as `REVERB_*` env vars. Decide which.
3. **cPanel: external mode.** With Reverb on the VPS, cPanel must not start a local Reverb. Today the watchdog (every minute, while the switch is on) and the Start button would try to start one and fail. Add a switch "Reverb runs on another server": the watchdog skips, Start/Restart are hidden, Test Connection and the status card use the VPS address.
4. **Broadcasting from cPanel.** Events go from the cPanel app to `https://<vps-domain>/apps/…`. Check this path with Test Connection before switching realtime over.

## Open questions for the user
- The VPS domain for Reverb (for example `ws.<domain>`).
- Does the VPS container run its own copy of the app with its own database? (Assumed yes.)
- Is the app secret allowed in the VPS env, or should it be entered on the VPS admin page?

## Verification
- Test Connection from cPanel green, then switch on, then a message arrives in an open inbox without refresh (browser check at 360 px and 1920 px).

## Rules from CLAUDE.md
- [ ] Live changes (Easypanel routes, env vars, switch on) only when the user asks for them.
- [ ] Brainstorming and a short design before code (two hosts, shared credentials).

## Logic / design docs
- `app/logic/realtime-config.md`, `app/logic/deployment-commands.md`

## Progress log
- 2026-10-13 14:20 Easypanel: the Reverb domain now points at port 8080. `https://ws.esystematics.com/up` returns `{"health":"OK"}`. The VPS reads the Reverb app ID from the shared database (`tinker` prints `590920`) after the cPanel Remote MySQL host was allowed. Test Connection on cPanel is the next check.
- 2026-10-13 14:20 Dockerfile: `gd`, `intl` and `zip` now install with `install-php-extensions` after the apt purge, which had removed their runtime libraries. Needs a rebuild of the Easypanel service with the build cache off.
- 2026-10-13 14:03 decided with the user: shared database; the VPS runs only Reverb; `ws.esystematics.com` through Cloudflare (not the same host as the website).
- 2026-10-13 14:03 code done (commit 4a851b3): `REVERB_RUNS_HERE` (config `reverb.runs_here`), the watchdog, the start and the status respect it; the toggle tests the other server without starting one here; `REVERB_ONLY` container mode in `docker/entrypoint.sh`; the page says where Reverb runs. Tests: 3 new in `ReverbWatchdogTest`, Realtime folder 59 run, 2 baseline failures.
- 2026-10-13 13:55 created from the user's decision. Pusher stays the live realtime for now. Not started.

## Result
- Code: done (commit 4a851b3). Nothing live changed.
- Blocked on the user's setup, in this order (full steps in `app/logic/realtime-config.md`, section "Reverb on another server"): cPanel Remote MySQL for the VPS address; Cloudflare DNS `ws` proxied; Easypanel service with `REVERB_ONLY=true`, `REVERB_RUNS_HERE=true`, the same `APP_KEY`, the remote `DB_*`, and the domain `ws.esystematics.com` on port 8080 with TLS; then cPanel Reverb Settings host `ws.esystematics.com`, port 443, https, Test Connection, then switch on.
- Not verified: anything on the VPS and through Cloudflare. The check: Test Connection green from cPanel, then a message shows up in an open inbox without refresh (360 px and 1920 px).

