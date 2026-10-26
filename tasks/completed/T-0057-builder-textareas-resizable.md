---
id: T-0057
title: Builder text boxes can be resized; Ask Question and WhatsApp message start taller
status: completed
priority: high
type: feature
plan: PLAN-000 (general)
phase: phase-01-ongoing
source: TEMP/intake-2026-10-26-1318.md
created: 2026-10-26 13:45
updated: 2026-10-26 14:30
---

# T-0057: Builder text boxes can be resized; Ask Question and WhatsApp message start taller

## Result
Shared `textareaCls` had `resize-none`; now `resize-y min-h-[4.5rem]`. Ask Question text and WhatsApp message boxes start at 8 rows. eslint: Builder.jsx 1 error + 3 warnings, same as before; build ok. Not looked at in a browser at 360/1920px or dark mode; live check pending.
