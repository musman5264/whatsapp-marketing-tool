---
id: T-0028
title: Private media storage (photos are public with guessable names) and media in the outgoing webhook
status: blocked
priority: high
type: security
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: [T-0027]
source: TEMP/intake-2026-10-13-1318.md
created: 2026-10-13 17:35
started: 2026-10-13 17:35
updated: 2026-10-13 17:35
---

# T-0028: Private media storage and media in the outgoing webhook

## Evidence
- `https://wa.esystematics.com/storage/message-media/1567.jpg` opens from outside with no login (200 image/jpeg). Files are stored on the public disk, named after the message id, so they can be guessed.
- Outgoing images from the composer are stored the same way (`InboxController::reply`, `public` disk, `link` = public URL sent to WAHA).
- The outgoing webhook (`DispatchOutboundWebhookListener::messageReceivedPayload()`) sends `media: null` for WhatsApp photos. It only reads the `Media` table through `media_id`, which WhatsApp photos don't use. The webhook docs state it never includes the file or a link.

## Decisions needed from the user
1. Make media private: store on a private disk and serve through a login check, or a signed link that expires (one hour suggested).
2. Webhook media: add `media: { url (signed, expiring), mime_type, filename }`. Does WhatsMine accept a `media.url` and download it?
3. Old files: move the files already on the public disk to the private disk, or leave them.

## Rules from CLAUDE.md
- [ ] Ask before changing stored data (moving files).
- [ ] Tests first for the link check and the webhook payload.

## Progress log
- 2026-10-13 17:35 created. Waiting for the decisions above.

## Result
(not finished)
