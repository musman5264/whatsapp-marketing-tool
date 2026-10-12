# PLAN-001: Inbox speed, live updates and media
Status: active
Created: 2026-10-13

## Goal
The inbox should feel like WhatsApp: it opens fast, shows the newest messages first, loads older ones on demand, shows new messages the moment they arrive, and shows photos and voice notes without a delay.

## Scope
- In: conversation list paging, message paging in a chat, the live-update path check, fetching WhatsApp Web media at arrival, media serving, scroll and layout behaviour in the chat.
- Out: redesign of the inbox look, new features. The Cloud API (Meta) media path is out unless shared code forces a change (then flag it).

## Detailed plan / spec
- Not written yet. Waiting for the user to approve the design (brainstorming gate) for T-0010 and T-0012.
- Root causes: see the "Process skill run first" section of `TEMP/intake-2026-10-13-0049.md`.

## Rules from CLAUDE.md that shape this plan
- WAHA calls from the inbound pipeline are soft-fail: catch Throwable, log, never block or lose an inbound message.
- Backfilled/historical messages never fire automations, AI replies or unread bumps.
- Live behaviour counts as unverified until checked on wa.esystematics.com.
- New user-facing strings go in `resources/js/locales/en.json`; check the inbox at 360px and 1920px, light and dark.
- Behaviour changes update the matching file in `app/logic/`.

## Phases
| Phase | File | Status | Tasks |
|-------|------|--------|-------|
| 1 | phase-01-speed-and-media.md | not started | T-0010, T-0011, T-0012, T-0013, T-0014 |

## Decisions
- 2026-10-13: Split into five tasks by root cause. No code changed until the design is approved.

## Risks / open questions
- Whether live delivery works on the live server (BROADCAST_CONNECTION and Pusher secret) is unverified. See T-0014.
- Voice notes (ogg/opus) may not play in every browser. Not tested.
- Media that WAHA has already dropped cannot be recovered, so the fetch timing matters.
