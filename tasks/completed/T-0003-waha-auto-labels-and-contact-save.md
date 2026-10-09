---
id: T-0003
title: Auto-label conversations (inbox + WhatsApp labels) and save contacts to phone via WAHA
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

# T-0003: Auto-label conversations (inbox + WhatsApp labels) and save contacts to phone via WAHA

## What the user asked
> can we make it like it should label the messages automatically? and save the contact to whatsapp/device automatically? can we do it via waha?

## What this means
Label rules auto-apply inbox labels and mirror to WhatsApp labels (soft-fail if not Business); new contacts are saved to the phone via PUT /contacts/{chatId}, soft-fail with logging; toggles on the WhatsApp Web settings card.

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
- [ ] rules apply labels on inbound (test)
- [ ] WA label mirror merges existing labels (PUT replaces) (test)
- [ ] contact save called once per new contact, failures non-fatal (test)

## Progress log
- 2026-10-09 15:30 created from intake; dispatched to a parallel agent

## Result
Label rules + evaluator + UI, WhatsApp label mirror (WahaLabelSync), contact saver, 3 settings toggles. 32 tests pass. Main thread wired label.* events into WEBHOOK_EVENTS + WahaEventProcessor. Combined run (WhatsappWeb|Realtime|Inbox|Webhook|Meta|Automation): 319 tests, 10 failures, all pre-existing at HEAD (Handover, LabelCrud, SlaTracking, MetaInboundWebhook dedupe, TypingEndpoint). Not verified against a live WAHA.
