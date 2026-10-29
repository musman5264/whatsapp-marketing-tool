---
id: T-0070
title: Send the customer's WhatsApp profile photo to EPOS (contact.photo_update)
status: completed
priority: medium
type: feature
plan: tasks/plans/PLAN-003-epos-integration/PLAN.md
phase: tasks/plans/PLAN-003-epos-integration/phase-01-integrations-api.md
depends_on: []
source: TEMP/intake-2026-10-29-2306.md
created: 2026-10-29 23:06
started: 2026-10-29 23:06
updated: 2026-10-29 23:20
completed: 2026-10-29 23:20
---

# T-0070: contact.photo_update

## What the user asked
> can yo uplease build this also? (d:\Dev\projects\epos\Modules\Whatsapp\docs\TOOL-contact-photo.md)

## What this means
When a WhatsApp Web contact writes in and the workspace's EPOS manifest lists `contact.photo_update`, a queued job asks WAHA for the contact's profile picture, downloads it, and POSTs it base64 to EPOS with the contact's verified number. Soft-fail, background only, at most once a day per contact, refreshed after 30 days.

## Design (decided)
- New `SendProfilePhotoToEposJob` (WhatsappWeb/Jobs), queued from `WahaEventProcessor` inbound path next to `queueAvatar`.
- Gate: `Cache::add('epos-photo:<contact>', 1 day)` after checking an EPOS link publishes the capability; a successful send sets `epos-photo-sent:<contact>` for 30 days.
- Sent through `EposExecutor` (identity, rate limit, health, call log). The photo param is `from: profile_photo`; its `max_length` cap is raised from 2000 to 900000 for that source only (executor; the manifest check already accepts any whole number), call log still cuts the payload at 2 KB.
- Photo over 600 KB, not https URL, download failure, or no photo: nothing sent.

## Rules from CLAUDE.md
- [x] Never guess: WAHA endpoint already verified (T-0066); EPOS contract from the spec
- [x] WAHA calls soft-fail (catch Throwable, log, never block an inbound message)
- [x] Historical/backfilled messages never trigger it
- [x] Test first; real test run quoted; phpstan level 6 on touched files
- [x] Logic doc updated: app/logic/epos-integration.md
- [x] Commit by file name; pull --rebase if rejected; push main; no frontend change so no build (push only when asked, see Result)
- [x] Live EPOS / WAHA / site stays "unverified"

## Logic / design docs
- Read: app/logic/epos-integration.md, app/logic/waha-sync-and-labels.md, EPOS spec TOOL-contact-photo.md
- Update: app/logic/epos-integration.md

## Skills
- [x] superpowers:brainstorming: bounded design above
- [ ] superpowers:test-driven-development (skill not invoked; tests were written before the code)
- [x] superpowers:verification-before-completion

## Prerequisites
- [x] none

## Steps
- [x] tests, executor cap, job, hook, doc, phpstan, commit

## Acceptance criteria
- [x] photo sent once with the contact's number; second message the same day sends nothing
- [x] no photo / hidden / over 600 KB / EPOS error: nothing breaks, no retry
- [x] capability not in the manifest: no WAHA call at all
- [x] historical message never triggers it

## Progress log
- 2026-10-29 23:06 created from intake
- 2026-10-29 23:25 built, tests green, committed locally

## Result
New `SendProfilePhotoToEposJob`, hook `WahaEventProcessor::queuePhotoForEpos`, photo-length cap in `EposExecutor::validValue`, logic doc section in app/logic/epos-integration.md, tests `EposContactPhotoTest` (8 green). Checked: `OK (205 tests, 567 assertions)` for tests/Feature/Integrations, tests/Feature/WhatsappWeb, IntegrationApiTest. phpstan: only the same `->contact` property notes the neighbouring queueAvatar already gets. Live EPOS / WAHA / site: unverified. Red-green checked for the photo length cap (test fails with the old 2000 cap, passes with the new); the hook tests were not run red.
