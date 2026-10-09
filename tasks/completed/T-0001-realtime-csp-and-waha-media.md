---
id: T-0001
title: Live inbox updates blocked by CSP + WAHA media 404
status: completed
priority: high
type: bug
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: []
source: TEMP/intake-2026-10-09-1505.md
created: 2026-10-09 15:30
started: 2026-10-09 15:30
updated: 2026-10-09 15:30
completed: 2026-10-09 17:00
---

# T-0001: Live inbox updates blocked by CSP + WAHA media 404

## What the user asked
> why its not synicing the chats and messages automatically (console: CSP blocks wss://ws-ap2.pusher.com; 404 on messages/<id>/media and branding logo)

## What this means
CSP and frontend Pusher flag use one shared source of truth; WAHA media is proxied/cached by serveMedia; storage:link checked in Docker entrypoint.

## Rules from CLAUDE.md
- [ ] Project CLAUDE.md has only the tracker block; global rules: process skill first, TDD, verification before done
- [ ] public/build is committed: rebuild once at the end (main thread), agents do not run npm build

## Logic / design docs
- Read before work: WAHA-EXPANSION-SPEC.md, docs/waha-api-coverage.md
- Update when done: docs/waha-api-coverage.md rows touched

## Skills
- [x] superpowers:systematic-debugging — root causes in intake file
- [ ] superpowers:test-driven-development
- [ ] superpowers:verification-before-completion

## Prerequisites
- none

## Acceptance criteria
- [ ] CSP allows pusher/reverb hosts whenever the frontend would connect (test)
- [ ] serveMedia serves WAHA `link` media (test)
- [ ] phpunit for touched classes passes

## Progress log
- 2026-10-09 15:30 created from intake; dispatched to a parallel agent

## Result
RealtimeConfig shared by CSP + Inertia + provider; serveMedia proxies WAHA links; storage:link already in entrypoint (logo 404 = no persistent volume). Tests: 19 pass. Combined run (WhatsappWeb|Realtime|Inbox|Webhook|Meta|Automation): 319 tests, 10 failures, all pre-existing at HEAD (Handover, LabelCrud, SlaTracking, MetaInboundWebhook dedupe, TypingEndpoint). Not verified against a live WAHA.
