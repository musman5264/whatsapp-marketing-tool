---
id: T-0012
title: Page the conversation list and each chat instead of loading everything
status: working
priority: high
type: change
plan: tasks/plans/PLAN-001-inbox-speed-and-media/PLAN.md
phase: tasks/plans/PLAN-001-inbox-speed-and-media/phase-01-speed-and-media.md
depends_on: []
source: TEMP/intake-2026-10-13-0049.md
created: 2026-10-13 00:55
started: 2026-10-13 00:59
updated: 2026-10-13 00:59
completed:
---

# T-0012: Page the conversation list and each chat instead of loading everything

## What the user asked
> "why it loads all the conversation instantly? isn't should lazy load the conversations? and also it should load the latest ones first?"

## What this means
- Chat list: the newest conversations load first; more load as you scroll down. Every conversation (81 in the screenshot) is reachable.
- Chat: the newest messages load first; older ones load when you scroll up, and the view stays where you were.
- Reactions no longer reload the whole thread.

## Root cause (read from the code)
- `app/Modules/Inbox/Http/Controllers/InboxController.php:79`: `show()` loads every message of the thread into the page data (`->get()`, no limit). A long thread sends its full history on each open.
- `InboxController.php:56`: the list is paged at 30, but `resources/js/Pages/Inbox/Index.jsx` (around lines 351–373) renders only `conversations.data` and has no "load more" control. The header shows the total (81 in the screenshot), so conversations 31 and later can only be reached by filters. The list is already ordered newest first (`orderByDesc('last_message_at')`).
- `resources/js/Pages/Inbox/Show.jsx:722`: reacting to a message runs `router.reload({ only: ['conversation', 'messages'] })`, which reloads the entire thread.

## Rules from CLAUDE.md (REQUIRED)
- [ ] Dates and times come from the workspace timezone, via the same formatter the page already uses (`formatTimeTz`).
- [ ] New user-facing strings go through `useTranslation()` and into `resources/js/locales/en.json`.
- [ ] Check the inbox at 360px and 1920px, light and dark.
- [ ] Any query change: check indexes (planetscale-database skill) and avoid N+1 queries.
- [ ] If `resources/js` changed: `npm run build` and commit `public/build` in its own commit.
- [ ] Git rules as in CLAUDE.md.

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/realtime-config.md`, `app/logic/README.md`
- Update when done: a new `app/logic/inbox-paging.md` (add a row to `app/logic/README.md`)

## Skills (REQUIRED)
- [x] superpowers:systematic-debugging — phases 1–2 done at intake (root cause above)
- [ ] superpowers:brainstorming — design approval needed: page size, cursor (before message id) vs offset, how scroll position is kept when older messages are added, how the list appends
- [ ] superpowers:writing-plans — after design approval
- [ ] superpowers:test-driven-development — failing test first: a 1,000-message thread sends at most one page on open
- [ ] superpowers:verification-before-completion — before completing
- [ ] planetscale-database — query and index for paged messages
- [ ] senior-frontend — list and chat components
- [ ] superpowers:requesting-code-review — after the major change

## Prerequisites
- [ ] Tasks: none
- [ ] Needed from user: approval of the paging design (brainstorming gate)

## Blocked by
Design approval from the user (brainstorming gate). No code changed yet.

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-001-inbox-speed-and-media/PLAN.md` · Phase: `tasks/plans/PLAN-001-inbox-speed-and-media/phase-01-speed-and-media.md` · Intake: `TEMP/intake-2026-10-13-0049.md`
- Related files: `app/Modules/Inbox/Http/Controllers/InboxController.php` (`index`, `show`), `resources/js/Pages/Inbox/Index.jsx`, `resources/js/Pages/Inbox/Show.jsx`

## Steps
- [ ] Brainstorm and get approval
- [ ] Failing test: opening a long thread sends one page of messages
- [ ] Server: paged messages, newest first, with "older" cursor
- [ ] List: load more on scroll (keeps newest-first order)
- [ ] Chat: load older on scroll up, keep the reading position
- [ ] Reactions update only the changed message
- [ ] New `app/logic/inbox-paging.md`

## Acceptance criteria
- [ ] Test: a 1,000-message thread returns at most one page on open (count quoted in Result)
- [ ] Every conversation in the list is reachable by scrolling; the count matches the header total
- [ ] Scrolling up loads older messages without jumping the view
- [ ] Checked at 360px and 1920px, light and dark

## Progress log
- 2026-10-13 00:55 created from intake (blocked: design approval)
- 2026-10-13 00:59 approved by user; "lazy load the messages" added to scope; moved to working
- 2026-10-13 01:04 failing tests first: `ConversationListPagingTest`, `MessagePagingTest`
- 2026-10-13 01:08 server: shared `conversationPage()`, JSON pages, `latestMessagePage()`, `messagesPage()`, route `client.inbox.messages.index`, reaction returns the row
- 2026-10-13 01:17 chat page: older-message loading, reading-place rules, new-messages button, reaction append, list load-more; `Index.jsx` list load-more
- 2026-10-13 01:22 build passed; docs `app/logic/inbox-paging.md` written

## Result
- Changed: `InboxController.php` (`index`, `show`, `conversationPage`, `latestMessagePage`, `messagesPage`, `react`), `routes/web.php`, `resources/js/Pages/Inbox/Show.jsx`, `resources/js/Pages/Inbox/Index.jsx`, `resources/js/Utils/inboxPaging.js` (new), `resources/js/hooks/useLoadWhenVisible.js` (new), `resources/js/locales/en.json` (3 keys), `app/logic/inbox-paging.md` (new).
- Tests (local): `ConversationListPagingTest` → `OK (3 tests, 20 assertions)`; `MessagePagingTest` + `InboxReactionEndpointTest` → `OK (7 tests, 45 assertions)` (includes: opening shows only 50; older pages have no gaps or repeats; same-second ties; other workspace gets 403).
- Wider run `--filter='Inbox|WhatsappWeb|WahaMedia'`: 186 tests, 516 assertions, 7 failures, all known baseline (`HandoverTest` 2, `LabelCrudTest` 3, `SlaTrackingTest` 2).
- Build: `npm run build` built in 1m 53s. ESLint on the changed JSX/JS: no new error categories; existing errors and warnings in the same lines remain (for example the `setConversations` effect in `Index.jsx`).
- Commits: `5081674` (backend), `902f142` (frontend and docs), `830babb` (build). Pushed to `origin/main` at 01:24.
- Live: **unverified**; not deployed yet (see T-0010). Browser checks still to do at 360 px and 1920 px, light and dark: scroll position on older loads, the new-messages button, list load-more.
- Known limits (documented in `app/logic/inbox-paging.md`): search covers loaded conversations only; the list resets to page 1 when the page props refresh.
- PHPStan on the changed backend files: still reports errors on my new code (Eloquent attribute access in `WahaMediaStore.php` and `MessageMediaReady.php`, and baseline count mismatches in `InboxController.php` and `WahaEventProcessor.php`). Not fixed and no baseline entries added; needs a follow-up.
- 2026-10-13 00:59 approved by user ('all designs are approved'); lazy loading of messages added to T-0012 scope. Moved to working.
