---
id: T-0045
title: Polls on any WAHA engine (GOWS, NOWEB, WEBJS): send from the API, match votes by bare id, real payload shape
status: working
priority: high
type: feature
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-24-1253.md
created: 2026-10-24 12:53
started: 2026-10-24 12:53
updated: 2026-10-24 12:53
completed:
---

# T-0045: Polls on every WAHA engine

## What this means
- `POST /api/v1/messages/send` accepts `type: poll` (question in `body`, `poll_options` 2-12, `poll_multiple`) on a WhatsApp Web number; refused with 422 on Cloud API numbers.
- A `poll.vote` event finds the poll we sent whether the engine stores the id bare (GOWS) or as `true_<chat>_<id>` (NOWEB/WEBJS); voter read from `payload.vote.from`.
- Tests use the real WAHA payload shape.

## Rules from CLAUDE.md
- [ ] Test first; WAHA calls verified against swagger (done: sendPoll, poll.vote); logic docs updated (api-message-send.md, waha-api-coverage.md); live behaviour reported as "unverified".
- [ ] Commit by file name, push to main, no `public/build` change (no JS touched).

## Logic / design docs
- app/logic/api-message-send.md, app/logic/waha-api-coverage.md

## Skills
- [x] systematic-debugging (phases 1-2), [x] brainstorming (bounded), [ ] test-driven-development, [ ] verification-before-completion

## Progress log
- 2026-10-24 12:53 created from intake; root causes listed in the intake file.

- 2026-10-24 13:20 tests written first (red), then code: API `type: poll`, vote id matched via findOwnMessage, voter from `vote.from`. 54 related tests green. Stays in working: live GOWS/NOWEB check pending.

## Result
Code done, live check pending (unverified on a running engine). Files: MessageApiController.php, WahaEventProcessor.php, MessageSendPollApiTest.php (new), PollVoteInboundTest.php, app/logic/api-message-send.md, app/logic/waha-api-coverage.md.
Check: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='Poll|MessageSend|WahaEvent|AdapterActions'` -> OK (54 tests, 138 assertions).
