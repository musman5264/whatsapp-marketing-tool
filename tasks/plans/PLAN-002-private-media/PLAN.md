# PLAN-002: Private media (chat photos off the public disk, safe webhook media)
Status: active
Created: 2026-10-13

## Goal
Chat photos and files can no longer be opened by guessing a public URL. Old public files are moved by a command the user runs. Outgoing `message.received` webhooks carry a WhatsApp photo or file as a signed link that expires.

## Scope
- In: `media:make-private` command (T-0029); webhook media link (T-0030); logic docs; tests; umbrella T-0028 kept open until the live steps are done.
- Out: running `--apply` or deploying on the live server (the user does both, see Blocked by in T-0028); Cloud API and Meta media; campaign and template images (public by design); WAHA fetch links (15 minutes, unchanged).

## Detailed plan / spec
Facts verified in the code on 2026-10-13:
- Commit 5e7137e: new received and sent media is stored on the `local` disk (`storage/app/private`). The inbox streams it to a logged-in user through `client.inbox.message-media`. WAHA gets a signed link valid for 15 minutes to fetch an outgoing file.
- Laravel serves the `local` disk at `/private-files/{path}`. `vendor/laravel/framework/src/Illuminate/Filesystem/ServeFile.php` returns 404 in production unless the URL has a valid signature (403 outside production). Private media is not reachable without a signed link.
- `MessageReceived` is dispatched inside `WhatsappDriver::processInboundMessage()` (`app/Modules/Whatsapp/Services/WhatsappDriver.php`). `WahaEventProcessor::handleInbound()` calls `WahaMediaStore::storeInbound()` only after that returns. So when the webhook payload is built, the file is not stored yet.
- `DispatchOutboundWebhookListener::handleMessageReceived()` is not queued. It calls `WebhookDispatchService::dispatch()`, which queues `DispatchWebhookJob`. The job builds its body in `handle()` on every attempt. Retries wait 1 minute, 5 minutes, 1 hour and 1 day.
- Before this plan, `message.received` always sent `media: null` for WhatsApp photos, because the payload reads only the `Media` table through `media_id`.

Decisions:
1. Private storage: in place since 5e7137e. New media is not public.
2. Webhook media (decided by Claude; the user wrote "the webhook media decision is still yours"):
   - Chosen: the job creates a signed link (`Storage::disk('local')->temporaryUrl`, valid 1 hour) on each attempt, so a retry always gets a fresh link. The payload change is additive.
   - Rejected: building the link when the payload is built (the file is not stored yet, verified above); sending the bytes inside the JSON (large bodies, the job's 10-second timeout); moving the webhook after the media store (changes when automations and webhooks fire, and a failed download would lose the webhook).
3. Old files: `php artisan media:make-private` is a dry run by default. `--apply` moves the files and rewrites the links. It is not added to the no-second-key allowlist (`app/Modules/Deployment/Services/CommandAllowlist.php`), because it moves and deletes files. It runs with the second key from the Deployment Run box, or from the cPanel terminal.

Payload contract for `message.received` (`media` key, additive):
- Text: `"media": null` (unchanged).
- Media from the `Media` table (unchanged id, now with two more keys): `{"id": 7, "mime_type": "image/jpeg", "filename": null, "url": null}`.
- WhatsApp Web photo, file, audio or video: `{"id": null, "mime_type": "image/jpeg", "filename": null, "url": "https://<APP_URL>/private-files/message-media/1567.jpg?expires=...&signature=..."}`. When the file is not stored at attempt time: `url` is `null` (and `mime_type` is `null` if the file is unknown).

Known limits:
- If a webhook attempt runs before WAHA's file is saved (possible with several queue workers), that attempt sends `url: null`. Unverified until a live check.
- The receiver must download the file within the hour of that attempt. The isinternet receiver returns 401 for every delivery (see `app/logic/webhook-deliveries.md`), so it will not see the new field until its own check is fixed.

## Rules from CLAUDE.md that shape this plan
- Ask before moving or deleting stored data: the command is a dry run by default. Claude does not run `--apply` on live data.
- Live changes (deploy, env, running commands on live) only when the user asks: the user deploys 5e7137e and T-0030 from Admin → Deployment and runs the command.
- WAHA calls are soft-fail: the link lookup never blocks or drops a webhook (catch `Throwable`, log, send with `url: null`).
- Tests first. Agents do not commit; the main thread runs the tests and commits by file name.
- Logic docs: `app/logic/private-media.md` (new), `app/logic/webhook-deliveries.md` (updated), `app/logic/deployment-commands.md` (note), and the index in `app/logic/README.md`.

## Phases
| Phase | File | Status | Tasks |
|-------|------|--------|-------|
| 1 | phase-01-private-media.md | in progress | T-0028 (umbrella), T-0029, T-0030 |

## Decisions
- 2026-10-13: webhook media = signed link resolved per attempt, 1 hour, additive payload (Claude, delegated by the user).
- 2026-10-13: `media:make-private` dry run by default; not in the no-key allowlist.
- 2026-10-13 18:01: outgoing composer files are named by their message id (`message-media/{messageId}.{ext}`), not a random hash. Found while checking T-0029: 5e7137e saved them under a hash name the login route cannot find, so the inbox would show 404 for new outgoing images after deploy (T-0031). Fix goes in `reply()` after the message is created. Rejected: letting `existingPath()` accept a hash name from the request payload.
- 2026-10-13 18:01: the command (T-0029) names a file that a message links to as `message-media/{messageId}.{ext}`; orphans keep their own name.
- 2026-10-13 18:01: deploy 5e7137e together with T-0030 and T-0031. Deploying 5e7137e alone breaks outgoing WhatsApp Web images.

## Risks / open questions
- Unverified live: `/private-files/...` reachable through cPanel's web server (routing), the webhook race, and the move itself on the live data.
- Old Messenger and Instagram image messages: `MessengerDriver::send()` and `InstagramDriver::send()` read `payload['link']`. Sent messages are not affected. A failed image send that is run again after the move will fail, because Meta cannot open the login link. The only way to keep it working is to keep the public copy, which this plan removes on purpose. Accepted, and stated in the Result of T-0029.
