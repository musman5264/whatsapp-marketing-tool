---
id: T-0046
title: Poll button in the inbox composer (QR numbers) and votes shown under the poll
status: working
priority: high
type: feature
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: [T-0045]
source: TEMP/intake-2026-10-24-1253.md
created: 2026-10-24 13:15
started: 2026-10-24 13:15
updated: 2026-10-24 13:15
completed:
---

# T-0046: Poll button in the inbox composer

## What this means
- `InboxController::reply()` accepts `type: poll` with `payload.poll {question, options 2-12, multiple}`; refused (422) unless the number is WhatsApp Web (QR).
- Composer toolbar gets a poll button (QR numbers only) opening a small form.
- A `poll.vote` stores `payload.votes[voter] = [options]` on the poll message; the bubble shows a count per option.
- The poll bubble title uses `poll.question` (it only read `poll.title`, so automation polls showed the generic word "Poll").

## Rules from CLAUDE.md
- [ ] Test first; every new string in `resources/js/locales/en.json`; check 360px and 1920px, light and dark; `npm run build` and commit `public/build` in its own commit; logic doc updated; live behaviour reported "unverified".

## Logic / design docs
- app/logic/api-message-send.md (poll section), app/logic/waha-api-coverage.md

## Skills
- [x] brainstorming (user approved scope), [ ] test-driven-development, [ ] senior-frontend, [ ] frontend-design, [ ] verification-before-completion

## Progress log
- 2026-10-24 13:15 created from the user's "go ahead".

- 2026-10-24 13:40 reply() poll branch, votes stored in payload.votes, PollPicker + toolbar button + bubble counts, 10 en.json strings. Tests green on private DB wm3_test_t0046 (the shared wm3_test was blocked by another session's run and MySQL had stopped; I restarted XAMPP MySQL). Build done. Not checked in a browser (Playwright MCP disconnected): 360px/1920px and dark mode unverified.

## Result
Code done, live and visual check pending. Files: InboxController.php, WahaEventProcessor.php, Show.jsx, en.json, InboxPollReplyTest.php (new), api-message-send.md.
Check: `DB_PASSWORD= DB_DATABASE=wm3_test_t0046 php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='InboxPollReplyTest|PollVoteInboundTest|InboxReaction|PollSendTest|MessageSend'` -> OK (52 tests, 153 assertions). eslint: no new errors (4 existing in Show.jsx).
