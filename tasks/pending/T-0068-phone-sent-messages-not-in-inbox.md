---
id: T-0068
title: Messages sent from the physical phone are not in the inbox (not root-caused: add visible reasons, live check)
status: blocked
priority: high
type: bug
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-29-1650.md
created: 2026-10-29 17:10
started: 2026-10-29 17:10
updated: 2026-10-29 18:10
completed:
---

# T-0068: Messages sent from the physical phone are not in the inbox (not root-caused: add visible reasons, live check)

Not root-caused. handleOwnMessage exists and message.any is subscribed, but it returns silently at 4 points; sessions need whatsapp-web:resubscribe; groups are skipped by design. Plan: log a reason at each silent exit (test first), then the user runs resubscribe and sends one phone message and reports the log line. Do NOT report fixed. Doc: app/logic/waha-sync-and-labels.md.

## Rules from CLAUDE.md
- [ ] Never guess: verify against the code; live behaviour stays "unverified" until checked on the running system
- [ ] Test first; PHPUnit run with the private DB / empty password / memory_limit=1G; never two test processes at once
- [ ] New UI strings in resources/js/locales/en.json; UI checked at 360px and 1920px, light and dark; times from user/workspace settings
- [ ] Logic written to app/logic/ (update in place)
- [ ] git add by file name; commit; if resources/js changed run npm run build and commit public/build separately; push only when the user asks; no deploy claim without the asset-hash check
- [ ] WAHA calls soft-fail; new WAHA endpoint checked against the WAHA docs

## Logic / design docs
- app/logic/README.md, plus the file named in the task body

## Skills
- [x] superpowers:brainstorming / systematic-debugging (intake step 2)
- [x] superpowers:test-driven-development (T-0065, T-0068 test first; T-0066/67 skipped on the user's instruction)
- [ ] superpowers:verification-before-completion
- [ ] code-reviewer / senior-frontend where UI or backend security is touched

## Progress log
- 2026-10-29 17:10 created and started after the user approved the design in chat

## Result
Added whatsapp_web.own_message.skipped log (reason, source, from/to kind) at the four silent exits of WahaEventProcessor::handleOwnMessage; OwnMessageSkipLogTest written (4 red before the code; WhatsappWeb group 140 tests OK after). ROOT CAUSE NOT FOUND. Blocked on the user: run whatsapp-web:resubscribe, send one message from the phone, report grep "own_message.skipped\|process_failed" storage/logs/laravel.log.

## Progress log (end)
- 2026-10-29 18:10 built; see Result
