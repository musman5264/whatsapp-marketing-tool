---
id: T-0058
title: Send a WhatsApp message to EPOS as an alert: manifest max_length for long text, per-pass idempotency key, EPOS developer spec
status: completed
priority: high
type: feature
plan: PLAN-000 (general)
phase: phase-01-ongoing
source: TEMP/intake-2026-10-26-1318.md
created: 2026-10-26 13:45
updated: 2026-10-26 14:30
---

# T-0058: Send a WhatsApp message to EPOS as an alert: manifest max_length for long text, per-pass idempotency key, EPOS developer spec

## Result
Design: EPOS publishes an action (`alert.push`), the existing E-POS node calls it; no new endpoint here. Tool: manifest param `max_length` (kept, validated, default 200, cap 2000), resizable text box in the node panel, Idempotency-Key now includes how many times the run passed the node (test first: red, then green). EPOS spec: `EPOS-ALERT-SPEC.md`. Logic doc: `app/logic/epos-integration.md`. Tests: `tests/Feature/Automation tests/Feature/Integrations IntegrationApiTest` OK (158 tests, 432 assertions). Not run against a real EPOS or live.
