---
id: T-0067
title: Inbox times wrong (user timezone defaults to UTC) and no AM/PM setting
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

# T-0067: Inbox times wrong (user timezone defaults to UTC) and no AM/PM setting

Root cause: shared prop timezone = user->timezone ?? 'UTC', column default UTC, the || Asia/Dhaka fallback never fires; Intl called with no hour12 so 12/24h follows the browser. Fix: users.time_format (12h default/24h) in Settings, helpers apply it; while timezone is the untouched UTC default use the device timezone; Settings button 'use this device timezone'. Doc: new app/logic/date-time-display.md.

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
Time fix built: migration add_time_format_to_users_table (needs migrate --force), User fillable, SettingsController, HandleInertiaRequests timeFormat, Utils/datetime.js (setTimeFormat, userTimezone, hour12), lib/date.js, ClientLayout, Inbox Index/Show, Settings page + en.json strings, app/logic/date-time-display.md. NO tests (user said skip tests). Not looked at in a browser. Only en.json has the new strings.

## Progress log (end)
- 2026-10-29 18:10 built; see Result
