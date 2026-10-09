---
id: T-0002
title: WAHA drops phone-sent messages, no backfill, unread never cleared
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

# T-0002: WAHA drops phone-sent messages, no backfill, unread never cleared

## What the user asked
> replies are not being fetched. also its not fetching the status like if the message is read by another device its not marking it as read.

## What this means
Subscribe message.any; ingest fromMe messages sent from phone as outbound (skip source=api echoes); clear unread when replied/read elsewhere; history backfill command + schedule + on-connect + Sync now endpoint; verify ack path and webhook config update call.

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
- [ ] fromMe phone message appears as outbound, no duplicate of API sends (test)
- [ ] unread_count cleared on phone reply (test)
- [ ] backfill ingests missing messages without firing automations/AI for old messages (test)
- [ ] webhook config update uses correct WAHA endpoint

## Progress log
- 2026-10-09 15:30 created from intake; dispatched to a parallel agent

## Result
message.any subscription, phone-sent messages ingested as outbound, PUT session update + resubscribe, history sync (command/job/schedule/Sync now), ack matching, unread reset. 18 new tests pass. Combined run (WhatsappWeb|Realtime|Inbox|Webhook|Meta|Automation): 319 tests, 10 failures, all pre-existing at HEAD (Handover, LabelCrud, SlaTracking, MetaInboundWebhook dedupe, TypingEndpoint). Not verified against a live WAHA.
