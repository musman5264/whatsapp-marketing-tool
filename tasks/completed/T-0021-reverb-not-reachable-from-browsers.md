---
id: T-0021
title: Reverb is not reachable from browsers on wa.esystematics.com (needs the host to forward it)
status: cancelled
priority: high
type: bug
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: [T-0019, T-0020]
source: TEMP/intake-2026-10-13-1318.md
created: 2026-10-13 13:40
updated: 2026-10-13 13:55
completed: 2026-10-13 13:55
---

# T-0021: Reverb is not reachable from browsers on wa.esystematics.com (needs the host to forward it)

## What the user reported
> "after starting still not reachable" (screenshot of the Reverb page on the live site: Reverb started, Test Connection shows the website's 404 page)

## What was checked (2026-10-13, read-only)
- The Start button starts Reverb on the server: the log shows `INFO  Starting server on 0.0.0.0:8080.`
- `https://wa.esystematics.com/up` answers 200, but with the website's own page, not Reverb's `{"health":"OK"}`. So the public address is not routed to Reverb.
- `https://wa.esystematics.com:8080/up` and `http://wa.esystematics.com:8080/up`: no answer. Port 8080 is not reachable from outside.
- Test Connection shows the website's 404 HTML as its reason.

## Why it is not a code bug
Browsers connect to `wss://wa.esystematics.com/app/…` on port 443. Nothing on the host forwards `/up` and the WebSocket path to `127.0.0.1:8080`. Reverb serves plain WebSocket on 8080. The app cannot add that routing.

## Options (host decision needed)
1. Host forwards `/up`, `/app/` and `/apps/` on `wa.esystematics.com` to `127.0.0.1:8080` with TLS (Apache `mod_proxy` + `mod_proxy_wstunnel` through the site's config or `.htaccess`). Needs a host that allows these modules. Then keep the listen address at `127.0.0.1`, so Reverb is not open to the internet.
2. A separate public address for Reverb (for example `ws.esystematics.com`) with TLS in front of port 8080. Needs an open port and TLS termination. Reverb is not verified to serve TLS itself here.
3. Keep hosted Pusher (the realtime switch stays off). No change.

## Follow-ups (in the app, small)
- Test Connection and the status card should say "the public address answers with the website, not Reverb" when the body is HTML, instead of printing the HTML.
- Listen address: keep the page's `127.0.0.1` option for the proxy setup, with a hint.

## Rules from CLAUDE.md
- [ ] Live changes (proxy, firewall, DNS) only when the user asks for them.
- [ ] Report the live result as "unverified" until the browser check passes on the live site.

## Progress log
- 2026-10-13 13:40 created. Blocked on the host decision (option 1, 2 or 3).
- 2026-10-13 13:50 user has only cPanel: no port control. Option 2 is out. Option 1 depends on the host enabling WebSocket proxying. Recommendation: keep Pusher (option 3). Waiting for the user's choice.
- 2026-10-13 13:55 cancelled. The user keeps Pusher for now and will run Reverb on a separate VPS through Easypanel. Replaced by T-0023.

## Result
- Cancelled 2026-10-13 13:55: the user keeps Pusher for now and will run Reverb on a separate VPS through Easypanel (their cPanel host has no WebSocket proxy or open ports). The browser-reachability problem moves to T-0023.
- The facts recorded above still hold: `https://wa.esystematics.com/up` answers the website's page, and port 8080 does not answer from outside.

