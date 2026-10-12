---
id: T-0009
title: message.received payload carries the sender number and media type (for PMS ticket replies)
status: completed
priority: high
type: feature
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: [T-0008]
source: TEMP/intake-2026-10-12-2050.md
created: 2026-10-13 00:20
started: 2026-10-13 00:20
updated: 2026-10-13 00:21
completed: 2026-10-13 00:21
---

# T-0009: message.received payload carries the sender number and media type

## What the user asked
> now the main issue is in pms.esystematics.com. the pms is not receiving the message that has to be read that its a reply and call the webhook

## What this means
The PMS needs to know who sent an inbound message (to check the sender is the assigned staff member or the owner) and whether it carries an attachment. The `message.received` payload had neither. This task adds `from` (sender number) and `media` (id and MIME type) to the payload, and records the shape in the logic doc.

What is NOT done here: the PMS receiving route, its signature check and its dispatch of the reply job live in the esystematics repo. That work is not started, because it needs your go-ahead (see the Result).

## Findings that led here
- The PMS registers its WhatsMine webhook at the capture route only (`WhatsMineWebhookRegistrar`).
- Nothing dispatches `ProcessInboundWhatsAppMessage` in the esystematics code, so the reply job never runs.
- `https://pms.esystematics.com/webhooks/whatsmine` returns 404 (no route). `/webhooks/whatsmine-capture/x` returns 405, so only the capture route exists.
- No WhatsMine signature check exists in the esystematics code.

## Rules from CLAUDE.md (REQUIRED)
- [x] TDD: tests written first; seen failing (undefined method) before the code.
- [x] Static analysis: PHPStan clean on the listener and the message model.
- [x] Logic doc updated: `app/logic/webhook-deliveries.md`.
- [x] Commit by file name. `public/deploy.php` was modified by someone else and is not included.
- [ ] No deploy (needs approval).

## Skills (REQUIRED)
- [x] superpowers:test-driven-development
- [x] superpowers:verification-before-completion (test and PHPStan output in Result)

## Blocked by
Deploy approval for WhatsMine; esystematics go-ahead for the receiving route.

## Steps
- [x] Failing tests (sender and media in the payload) written and run: 3 errors, undefined method
- [x] `messageReceivedPayload()` added; `handleMessageReceived()` uses it
- [x] Sender looked up by `contact_id`; PHPStan errors fixed with narrow docblocks on `Message` only
- [x] Tests pass; PHPStan clean
- [x] Logic doc updated

## Acceptance criteria
- [x] Text message payload includes `message.from` (sender number) and `message.body` (test)
- [x] Image message payload includes `message.media` with id and MIME type (test)
- [x] Message with no contact has `from` null (test)
- [ ] Live: a delivery after deploy shows `from` and `media` in its body (Show body on the Deliveries page)

## Progress log
- 2026-10-13 00:20 created from intake; work under way
- 2026-10-13 00:21 completed (time from the date command)

## Result
- Changed: `app/Listeners/DispatchOutboundWebhookListener.php` (`messageReceivedPayload()`), `app/Modules/Shared/Models/Message.php` (docblock only: `@property int|null $media_id`), `tests/Feature/Webhooks/MessageReceivedPayloadTest.php` (3 tests, new), `app/logic/webhook-deliveries.md` (payload section).
- Tests: `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='MessageReceivedPayloadTest|WebhookDeliveryBodyTest|WebhookEndpointTest'` → `OK (11 tests, 39 assertions)`.
- PHPStan on the listener and message model → `[OK] No errors`.
- Side note: a PHPStan fix attempted on `Conversation.php` was reverted (only my five added lines, confirmed by the diff before the revert). Nothing from another session was in that file.
- Not done: deploy to WhatsMine; the PMS receiving route, signature check and reply-job dispatch in esystematics (needs go-ahead and coordination, because another session is committing there).
