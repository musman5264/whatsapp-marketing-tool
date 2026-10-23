---
id: T-0043
title: Write the EPOS integration spec (delivery status, product availability, services, business hours)
status: completed
priority: medium
type: docs
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-24-0205.md
created: 2026-10-24 02:40
started: 2026-10-24 02:40
updated: 2026-10-24 03:10
completed: 2026-10-24 03:10
---

# T-0043: EPOS integration spec

## What the user asked
> "write the api/webhook documentation so that i could tell the EPOS project to build according to it ... status of the deliveries ... product availability, service, reply within business days shift"

## Rules from CLAUDE.md
- [x] Never guess: Part A read from `routes/api.php`, `MessageApiController`, `OutboundWebhookApiController`, `SendQueuedWhatsappMessageJob`, `WahaEventProcessor`, `AutomationApiController`; Part B is a requirement, labelled as such.
- [x] No secrets in the file.

## Logic / design docs
- `EPOS-INTEGRATION-SPEC.md` (repo root, for the EPOS team); `app/logic/README.md` links it.

## Skills
- [x] task-tracker

## Result
Created `EPOS-INTEGRATION-SPEC.md`. Not tested against a real EPOS or the live site. Part B field names are a proposal for the EPOS team to accept. `api-message-send.md` corrected: status can also be `delivered`/`read` on a QR number.
