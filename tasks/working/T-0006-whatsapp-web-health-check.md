---
id: T-0006
title: Health check that pinpoints why chats/messages don't auto-fetch on the live server
status: working
priority: high
type: bug
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: [T-0005]
source: TEMP/intake-2026-10-09-1505.md
created: 2026-10-09 16:55
started: 2026-10-09 16:55
updated: 2026-10-09 16:55
completed:
---

# T-0006: WhatsApp Web health check

## What the user asked
> its still not autofetching the chats/messages.

## What this means
Phase 1 evidence: live site now serves the new build and CSP allows Pusher, so the browser side is fixed. Remaining suspects are server-side and cannot be seen from outside: (a) webhook URL registered in WAHA wrong/old (APP_URL), (b) WAHA not delivering (last webhook time), (c) server not broadcasting (BROADCAST_CONNECTION/secret), (d) jobs on a queue with no matching worker (QUEUE_CONNECTION vs `queue:work redis`), (e) scheduler not running. Build a read-only health check (service + artisan + endpoint + button) that reports each, then fix the proven cause as T-0007.

## Rules from CLAUDE.md
- [ ] Never guess: every check reads real state; unverified items say so
- [ ] Commit by file name, pull --rebase, push; rebuild public/build (UI changed)
- [ ] New UI strings in en.json; logic doc in app/logic/

## Skills
- [x] superpowers:systematic-debugging — phase 1 evidence gathering (instrumentation, no fixes)
- [ ] superpowers:test-driven-development
- [ ] superpowers:verification-before-completion

## Acceptance criteria
- [ ] `php artisan whatsapp-web:diagnose` prints pass/warn/fail per check with a fix hint
- [ ] GET /app/whatsapp-web/health returns the same; button in Inbox → Setup shows it
- [ ] Webhook controller records last-received time/event

## Progress log
- 2026-10-09 16:55 created

## Result
(pending)
