---
id: T-0054
title: Registering the same webhook URL again created duplicate endpoints (EPOS saw "Bad signature" on the old copies)
status: completed
priority: high
type: bug
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-24-1408.md
created: 2026-10-24 19:10
started: 2026-10-24 19:10
updated: 2026-10-24 19:20
completed: 2026-10-24 19:20
---

# T-0054: Duplicate webhook endpoints per URL

## Root cause
`OutboundWebhookApiController::store()` always created a new endpoint. Every copy gets every event and has its own secret; EPOS only knows the newest, so older copies fail its signature check (401 "Bad signature") while the newest is accepted. Evidence: EPOS "Tool API Logs" screenshot (same message refused and accepted); code read. Not confirmed on the live Webhooks page.

## Rules from CLAUDE.md
- [x] Systematic debugging (root cause first), test first (2 red, then green), logic doc (webhook-deliveries.md), commit by name and push, live behaviour unverified.

## Result
Same URL for the same user now updates one endpoint, issues a new secret, answers 200, and deletes older duplicates. `OutboundWebhookApiTest` OK (11). Related and still open: T-0037 (failed deliveries are not retried).
