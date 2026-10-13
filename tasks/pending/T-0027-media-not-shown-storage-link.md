---
id: T-0027
title: Images not shown in the inbox: public/storage is not linked on cPanel, so /storage/ returns 404
status: blocked
priority: high
type: bug
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: []
source: TEMP/intake-2026-10-13-1318.md
created: 2026-10-13 16:40
started: 2026-10-13 16:40
updated: 2026-10-13 16:40
---

# T-0027: Images not shown in the inbox: public/storage is not linked on cPanel

## What the user reported
> "why its not showing the images that i'm sending?" (inbox screenshot: outgoing message shows text only; many incoming items show "(media)")

## Evidence
- `php artisan about` (cPanel): `public/storage ... NOT LINKED`.
- From outside: `https://wa.esystematics.com/storage/probe-test.jpg` returns 404.
- `StorageManager::diskName()` returns `public` when no cloud storage is enabled. `WahaMediaStore` saves received media and records `preview_url = disk->url(path)`, which is `APP_URL/storage/...`. Uploads from the composer use the same disk. The inbox uses `preview_url` when it is a same-site path, so those images request `/storage/...` and get 404.
- WAHA must download the same link to send an outgoing image, so an image sent from the app likely failed for the same reason.

## Fix (blocked on the user)
1. cPanel terminal: `cd ~/public_html/subdomains/whatsapp_saas && php artisan storage:link` (or the Deployment Run box, which allows `php artisan storage:link` without a key).
2. Check: `php artisan about` shows `public/storage ... LINKED`.
3. Re-send the image from the inbox and check it shows.

## Open
- Shared hosts sometimes forbid symlinks. If `storage:link` fails, the fallback is to serve media through the app route (`client.inbox.message-media`); that is a code change.
- Older incoming images may have no saved file, because webhooks were broken until T-0026. The app route can download them from WAHA on demand, but only when `preview_url` is empty.
- cPanel deploys don't run `storage:link`, unlike the Easypanel entrypoint. Consider adding it to the deploy action.

## Rules from CLAUDE.md
- [ ] Live changes (storage link) only when the user asks.
- [ ] Verify on the live site after the link.

## Progress log
- 2026-10-13 17:20 `public/storage` is LINKED on cPanel. Found the second cause: WAHA sends a photo as type `chat` with `hasMedia=true` and the caption as the body. The normalizer stored it as empty text (live check: "This is caption" stored as a text message, no image). Fixed in `InboundNormalizer::mapType()` (commit below); tests pass (104). Waiting for the deploy and a new photo from the phone.
- 2026-10-13 16:40 created from the screenshot and the checks above. Waiting for `storage:link` on cPanel.

## Result
(not finished)
