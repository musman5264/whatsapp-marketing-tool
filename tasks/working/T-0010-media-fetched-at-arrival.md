---
id: T-0010
title: Fetch WhatsApp Web media when the message arrives, not on first view
status: working
priority: high
type: bug
plan: tasks/plans/PLAN-001-inbox-speed-and-media/PLAN.md
phase: tasks/plans/PLAN-001-inbox-speed-and-media/phase-01-speed-and-media.md
depends_on: [T-0014]
source: TEMP/intake-2026-10-13-0049.md
created: 2026-10-13 00:55
started: 2026-10-13 00:59
updated: 2026-10-13 00:59
completed:
---

# T-0010: Fetch WhatsApp Web media when the message arrives, not on first view

## What the user asked
> "why the media/image/voice notes are not smooth? why they aren't being shown properly?"

## What this means
Photos, videos, documents and voice notes from a personal WhatsApp number are downloaded from WAHA when the message arrives and stored, so the bubble can show the file straight away. Today the file is only fetched when someone first opens it.

## Root cause (systematic-debugging phases 1–2, read from the code)
- `app/Modules/WhatsappWeb/Services/WahaEventProcessor.php` never downloads media. The inbound message keeps only the engine link (`app/Modules/WhatsappWeb/Services/InboundNormalizer.php:143`).
- The first time a bubble renders, the browser requests `client.inbox.message-media`. `InboxController::serveMedia` then downloads the file from WAHA inside that request (`cacheWahaMedia`, 30 s timeout), so the image waits on the engine.
- The live `MessageReceived` event is sent before any stored copy exists, so a new photo sits as a spinner until that request finishes (`resources/js/Pages/Inbox/Show.jsx`, `MediaImage`).
- If WAHA has already dropped the file by the time someone opens the chat, the bubble shows "unavailable". Not verified on the live site.

## Rules from CLAUDE.md (REQUIRED)
- [ ] WAHA calls from the inbound pipeline are soft-fail: catch Throwable, log, never block or lose an inbound message.
- [ ] Backfilled/historical messages never fire automations, AI replies or unread bumps (`historical` flag in `WhatsappDriver`). Applies if history sync also fetches media.
- [ ] The engine API key never goes to another host, and no URL from a webhook payload is fetched unchecked (rule already in `cacheWahaMedia`).
- [ ] New user-facing strings go through `useTranslation()` and into `resources/js/locales/en.json`.
- [ ] Check the chat at 360px and 1920px, light and dark.
- [ ] If `resources/js` changed: `npm run build` and commit `public/build` in its own commit.
- [ ] Git: `git add` by name, commit, `git pull --rebase origin main`, `git push origin main`. Deploy confirmed by asset hash, not assumed.

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/waha-sync-and-labels.md`, `app/logic/realtime-config.md`, `app/logic/README.md`
- Update when done: `app/logic/waha-sync-and-labels.md` (media is fetched at arrival)

## Skills (REQUIRED)
- [x] superpowers:systematic-debugging — phases 1–2 done at intake (root cause above)
- [ ] superpowers:brainstorming — design approval needed before code: fetch inline in the after-response step, or queued; how the bubble is updated when the copy is ready
- [ ] superpowers:writing-plans — after design approval
- [ ] superpowers:test-driven-development — failing test first: inbound WAHA image has a stored copy without a browser request
- [ ] superpowers:verification-before-completion — before completing
- [ ] code-reviewer — backend fetch with engine credentials
- [ ] senior-frontend — bubble rendering in `Show.jsx`

## Prerequisites
- [ ] Tasks: T-0014 answered (whether live delivery works on the live server)
- [ ] Needed from user: approval of the design. Proposed option: after the webhook response, download and store the media, then send a follow-up event so the open chat updates the bubble.

## Blocked by
Design approval from the user (brainstorming gate). No code changed yet.

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-001-inbox-speed-and-media/PLAN.md` · Phase: `tasks/plans/PLAN-001-inbox-speed-and-media/phase-01-speed-and-media.md` · Intake: `TEMP/intake-2026-10-13-0049.md`
- Related files: `app/Modules/WhatsappWeb/Services/WahaEventProcessor.php`, `app/Modules/WhatsappWeb/Services/InboundNormalizer.php`, `app/Modules/Inbox/Http/Controllers/InboxController.php` (`serveMedia`, `cacheWahaMedia`), `app/Events/MessageReceived.php`, `resources/js/Pages/Inbox/Show.jsx` (`MediaImage`, `MediaAudio`, `MediaVideo`, `MediaDocument`)

## Steps
- [ ] Brainstorm fetch timing and the bubble update path; get approval
- [ ] Failing test: inbound WAHA image has a stored copy without a browser request
- [ ] Implement fetch at arrival (soft-fail)
- [ ] Bubble updates when the copy is ready, with no spinner on first view
- [ ] Update `app/logic/waha-sync-and-labels.md`

## Acceptance criteria
- [ ] Test: an inbound WAHA image or voice note has a stored file before the chat is opened (phpunit output quoted in Result)
- [ ] Engine download failure does not drop the message (test)
- [ ] Live: a photo sent from a phone shows in an open chat within a few seconds, no refresh, no spinner. Marked unverified until checked on wa.esystematics.com.

## Progress log
- 2026-10-13 00:55 created from intake (blocked: design approval)
- 2026-10-13 01:00 design approved by user; moved to working
- 2026-10-13 01:03 failing test first (`WahaMediaArrivalTest`: image stored and announced at arrival); failed on "Undefined array key preview_url" as expected
- 2026-10-13 01:12 implemented `WahaMediaStore::storeInbound()`, `MessageMediaReady`, call in `WahaEventProcessor::handleInbound()`; both arrival tests pass
- 2026-10-13 01:20 frontend listener for `.MessageMediaReady` added in `Show.jsx`; build passed

## Result
- Changed: `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` (`storeInbound`), `app/Events/MessageMediaReady.php` (new), `app/Modules/WhatsappWeb/Services/WahaEventProcessor.php` (constructor + `handleInbound`), `resources/js/Pages/Inbox/Show.jsx` (listener), tests `tests/Feature/WhatsappWeb/WahaMediaArrivalTest.php` (new).
- Checked (local): `WahaMediaArrivalTest` 2 pass; `InboundWebhookTest` passes. A combined run also listed `MetaInboundWebhookTest::global_webhook_dedupes_duplicate_entry_ids` as failing: that is on the known-baseline list.
- Commits: `5081674` (backend), `902f142` (frontend), `830babb` (build). Pushed to `origin/main` at 01:24.
- Live: **unverified**. The live site still serves `assets/app-COr5lMRs.js`; the new build is `assets/app-DkfOuBLd.js`, so it is not deployed yet. Acceptance on wa.esystematics.com (a photo from a phone shows in an open chat with no refresh) still to do.
- Known risk: the arrival download runs inside PHP's after-response step; with a 30 s engine timeout it can hit `max_execution_time`. The message is still saved and the lazy path covers it.
- 2026-10-13 00:59 approved by user ('all designs are approved'); lazy loading of messages added to T-0012 scope. Moved to working.
