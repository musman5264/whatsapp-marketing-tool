---
id: T-0013
title: Stop the chat jumping to the bottom and reserve space for media
status: working
priority: medium
type: bug
plan: tasks/plans/PLAN-001-inbox-speed-and-media/PLAN.md
phase: tasks/plans/PLAN-001-inbox-speed-and-media/phase-01-speed-and-media.md
depends_on: []
source: TEMP/intake-2026-10-13-0049.md
created: 2026-10-13 00:55
started: 2026-10-13 00:59
updated: 2026-10-13 11:21
completed:
---

# T-0013: Stop the chat jumping to the bottom and reserve space for media

## What the user asked
> "why the inbox is not so smooth??? like whatsapp?" and "why the media/image/voice notes are not smooth?"

## What this means
- While you read older messages, incoming messages, status ticks and reloads no longer pull you to the bottom. A "new messages" button appears instead.
- Photos take their final size before they load, so the chat does not shift under the reader.

## Root cause (read from the code)
- `resources/js/Pages/Inbox/Show.jsx:1570–1580`: `scrollToBottom` uses a smooth scroll and runs on every change to `messages`. Each live event, status update and reload therefore moves the reader to the bottom, even when they are scrolled up.
- `MediaImage` (`Show.jsx` ~174–207): the image is `opacity-0` with `min-h-[80px]` until it loads, then grows to its real size, which pushes the messages below it down.

## Rules from CLAUDE.md (REQUIRED)
- [ ] New user-facing strings go through `useTranslation()` and into `resources/js/locales/en.json`.
- [ ] Check the chat at 360px and 1920px, light and dark.
- [ ] If `resources/js` changed: `npm run build` and commit `public/build` in its own commit.
- [ ] Git rules as in CLAUDE.md.

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/realtime-config.md`, `app/logic/README.md`
- Update when done: `app/logic/inbox-paging.md` if T-0012 has created it, otherwise "none"

## Skills (REQUIRED)
- [x] superpowers:systematic-debugging — phases 1–2 done at intake (root cause above)
- [ ] superpowers:test-driven-development — where a test is practical; otherwise a browser check recorded in Result
- [ ] superpowers:verification-before-completion — before completing
- [ ] senior-frontend — React component behaviour
- [ ] frontend-design — the "new messages" control matches the existing style

## Prerequisites
- [ ] Tasks: none. Best done after T-0012 (paging changes the same scroll code), but not required.
- [ ] Needed from user: go-ahead to start.

## Blocked by
Nothing. Waiting for the go-ahead.

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-001-inbox-speed-and-media/PLAN.md` · Phase: `tasks/plans/PLAN-001-inbox-speed-and-media/phase-01-speed-and-media.md` · Intake: `TEMP/intake-2026-10-13-0049.md`
- Related files: `resources/js/Pages/Inbox/Show.jsx` (`scrollToBottom`, `MediaImage`, `MediaVideo`, `ImageGallery`)

## Steps
- [ ] Scroll to bottom only if the reader is already near the bottom, or the message is their own
- [ ] Show a "new messages" control when scrolled up
- [ ] Give media a reserved size from the payload, or a fixed aspect box, before load
- [ ] Check no layout jump when images load

## Acceptance criteria
- [ ] Reader scrolled up stays in place when a message arrives (browser check, recorded in Result)
- [ ] No visible jump when an image loads (before/after positions recorded)
- [ ] Checked at 360px and 1920px, light and dark

## Progress log
- 2026-10-13 00:55 created from intake
- 2026-10-13 00:59 approved by user; moved to working
- 2026-10-13 01:18 scroll rules and media boxes in `Show.jsx`; build passed

## Result
- Changed: `resources/js/Pages/Inbox/Show.jsx` (`useLayoutEffect` scroll rules, `onMessagesScroll`, "N new message(s)" button, `MediaImage` and `MediaVideo` fixed boxes w-64 × h-48), `resources/js/locales/en.json` (`inbox.new_messages`, `inbox.loading_older`, `inbox.loading_more`).
- Checked (local): `npm run build` passes; ESLint shows no new error categories. No automated test for the browser scroll behaviour.
- Commits: `902f142` (source), `830babb` (build). Pushed to `origin/main` at 01:24.
- Live: **unverified**; not deployed yet. Browser checks still to do (scrolled-up reader is not moved; no jump when an image loads; 360 px and 1920 px, light and dark).
- 2026-10-13 00:59 approved by user ('all designs are approved'); lazy loading of messages added to T-0012 scope. Moved to working.
- 2026-10-13 11:21 browser check: photos render in fixed 256 × 192 boxes; the 'Loading older' line moved the chat by about 36 px until it became an overlay (commit 6b3bffc); now the same message stays at 20 px from the top before and after the load. Dark mode checked. 360 px: the inbox has three fixed columns, the chat pane sits off-screen; that layout is not changed by this work (see the report).
