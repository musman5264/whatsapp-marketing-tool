# PLAN-002 · Phase 1: Private media
Status: in progress
Plan: ./PLAN.md

## Goal of this phase
New chat media is private (done in 5e7137e). Old public chat media moves to the private disk through a command. The outgoing `message.received` webhook carries a signed, expiring link for WhatsApp media.

## Before starting this phase
- 5e7137e committed on main (verified: 26 tests in the private-media and webhook set pass on 2026-10-13 17:55).

## Tasks
- [ ] T-0029 Move old public chat photos to the private disk with a command (dry run first)
- [ ] T-0030 Webhook: send the WhatsApp photo or file as a signed link that expires
- [ ] T-0028 Umbrella: private media and webhook media (stays open until the live steps are done)

## Done when
- The tests for T-0029 and T-0030 pass (run by the main thread, one test process at a time), `vendor/bin/phpstan analyse` reports no new errors on the changed files, and the logic docs are updated.
- Both tasks are committed and pushed to `origin main` by file name.
- The user has deployed 5e7137e and T-0030 from Admin → Deployment, has run `php artisan media:make-private` (dry run, then `--apply`), and the live checks in T-0028 pass.
