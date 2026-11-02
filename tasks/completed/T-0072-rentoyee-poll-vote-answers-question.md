---
id: T-0072
title: A poll vote answers a RentOyee question (customer-sharing consent)
status: completed
plan: PLAN-000
phase: phase-01
priority: high
depends_on: none
started: 2026-11-02 17:16
updated: 2026-11-02 17:40
completed: 2026-11-02 17:40
---

## What this means
RentOyee sends a text and a poll with the same `reply_context`. A vote on that poll must reach `customer.update_info` exactly like a text reply does: `value` = the selected option's text, idempotency key from the vote id, `reply_text` sent to the chat, context closed on `applied` / `request_closed`, kept on `invalid`.

## Acceptance criteria
- [x] Vote "Yes, allow" with an open RentOyee context calls customer.update_info (request_id, value, verified phone) and sends reply_text. Check: PHPUnit test.
- [x] `invalid` keeps the context; `applied` and `request_closed` clear it. Check: test.
- [x] No context / not RentOyee / empty or multiple selection: nothing is sent, normal poll automation path unchanged. Check: test + existing PollVoteInbound tests.
- [x] Same vote delivered twice does not call RentOyee twice. Check: test.

## Rules from CLAUDE.md
- [x] Never guess: verified against the code (handlePollVote, ReplyContextHandler, MessageApiController).
- [x] WAHA calls from the inbound pipeline soft-fail (catch Throwable, log): handled in ReplyContextHandler::handleVote.
- [x] App logic documented in app/logic/ (epos-integration.md, README index).
- [x] Tests run with `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=...`, one process at a time.
- [x] Commit by file name, no `git add -A`; push only when the user asks; no frontend change so no build.
- [x] Live RentOyee / WAHA behaviour reported as "unverified".

## Logic / design docs
- app/logic/epos-integration.md (reply-context section), app/logic/README.md

## Skills
- [x] task-tracker
- [x] superpowers:brainstorming (folded into the intake: spec complete)
- [x] superpowers:test-driven-development
- [x] superpowers:verification-before-completion

## Progress log
- 2026-11-02 17:16 created.
- 2026-11-02 17:40 done. ReplyContextPollVoteTest 5/5 green (red first: 4 failed before the code); PollVoteInboundTest + InboxPollReplyTest green (14 tests total). phpstan: 3 errors, all on lines I did not touch.

## Result
- ReplyContextHandler::handleVote + apply($vote, $voteKey); WahaEventProcessor::handlePollVote calls it after storing the vote. Doc: app/logic/epos-integration.md.
- Found, not fixed: RiderReplyTest fails 8 of 17 at baseline since T-0071 (per-client switch defaults off for workspaces with a client; the tests do not set it). Needs its own task.
- Unverified: real RentOyee and live WAHA vote delivery.
