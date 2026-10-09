---
id: T-0005
title: Get the WAHA sync/labels/realtime changes live on wa.esystematics.com
status: blocked
priority: high
type: chore
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: [T-0001, T-0002, T-0003]
source: TEMP/intake-2026-10-09-1505.md
created: 2026-10-09 16:35
updated: 2026-10-09 16:35
completed:
---

# T-0005: Deploy to wa.esystematics.com

## What the user asked
> have you deployed it?

## What this means
Code is on `main` but the live site still serves the old build (`assets/app-BKxKp2m-.js`, old CSP header) — checked 2026-10-09 16:30. Needs the real deploy mechanism for wa.esystematics.com (EasyPanel rebuild or a deploy.php + key for that host).

## Blocked by
Deploy trigger / credentials for wa.esystematics.com are not known to Claude (the saved wa2.dermavalue.pk deploy.php returns 404).

## Steps
- [ ] Trigger rebuild/deploy
- [ ] Verify: login page asset hash == public/build/manifest.json app hash; CSP header contains ws-ap2.pusher.com
- [ ] Server env: BROADCAST_CONNECTION=pusher + PUSHER_* ; php artisan config:clear
- [ ] php artisan migrate --force; php artisan whatsapp-web:resubscribe (or Sync now)
- [ ] Persistent volume for storage/app/public; queue/cron running for `whatsapp` queue

## Progress log
- 2026-10-09 16:35 created, blocked
