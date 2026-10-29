---
id: T-0066
title: Inbox shows WhatsApp profile photos from WAHA when the contact has no saved photo
status: completed
priority: high
type: bug
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-29-1650.md
created: 2026-10-29 17:10
started: 2026-10-29 17:10
updated: 2026-10-29 18:10
completed: 2026-10-29 18:10
---

# T-0066: Inbox shows WhatsApp profile photos from WAHA when the contact has no saved photo

Root cause: inbox list draws initials only; the WhatsappWeb module never calls WAHA GET /api/contacts/profile-picture (verified in WAHA docs). Fix: WahaAdapter method, queued soft-fail job (once a day per contact), ContactService::downloadAndStoreAvatar, never override a saved photo; show avatar_url in list, contact list and chat header. Doc: app/logic/waha-sync-and-labels.md.

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
Profile photos built: WahaAdapter::profilePictureUrl (WAHA docs checked), FetchWahaAvatarJob, queueAvatar in WahaEventProcessor, ContactAvatar.jsx used by Inbox/Index.jsx and Show.jsx, app/logic/waha-sync-and-labels.md. NO tests (user said skip tests). Not looked at in a browser (360/1920px, dark) and not run against a real WAHA.

## Progress log (end)
- 2026-10-29 18:10 built; see Result
