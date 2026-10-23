---
id: T-0044
title: Alibaba-style product cards on a QR number: one photo+caption message per product, then a poll
status: pending
priority: medium
type: feature
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: [T-0042]
source: TEMP/intake-2026-10-24-0205.md
created: 2026-10-24 03:10
started:
updated: 2026-10-24 03:10
completed:
---

# T-0044: Product cards (QR number)

## Finding (verified)
Real buttons / swipe carousel cannot be sent from a QR number: WAHA docs mark Send Buttons "DEPRECATED - Buttons do not work at the moment!", lists "fragile", no carousel section. `WhatsappDriver` flattens interactive messages to numbered text.

## Proposed design (waiting for the user's yes)
One new node "Send item cards": list path (`api.products`), image template (`{{image_url}}`), caption template (name, price, min order, link), max items (default 5, cap 10), short pause between sends; then the existing Send Poll node for "Get quotes / Recommend more" and a Condition on `poll_answer`.

## Rules from CLAUDE.md
- [ ] Test first; new strings in `en.json`; QR flag on the node; logic doc updated; build committed separately.

## Skills
- [ ] brainstorming (design approval), [ ] test-driven-development, [ ] senior-frontend
