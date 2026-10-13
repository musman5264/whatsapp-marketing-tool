---
id: T-0030
title: Webhook: send the WhatsApp photo or file as a signed link that expires (fresh link on each attempt)
status: working
priority: high
type: feature
plan: tasks/plans/PLAN-002-private-media/PLAN.md
phase: tasks/plans/PLAN-002-private-media/phase-01-private-media.md
depends_on: []
source: TEMP/intake-2026-10-13-1747.md
created: 2026-10-13 17:54
started: 2026-10-13 17:55
updated: 2026-10-13 17:55
completed:
---

# T-0030: Webhook: send the WhatsApp photo or file as a signed link that expires

## What the user asked
> "The webhook media decision is still yours."

The open question was in T-0028 decision 2: add `media: { url (signed, expiring), mime_type, filename }` to the outgoing `message.received` webhook.

## What this means
- The `message.received` webhook carries `media.url`, a signed link for the stored WhatsApp file, valid for 1 hour.
- The link is created when the webhook is sent, on every attempt. A retry always gets a fresh link.
- The change is additive: `id` and `mime_type` keep their meaning; `filename` and `url` are new keys.
- The link is never saved in the delivery row's `payload` column. The exact bytes sent (`request_body`) do contain it, as before for every signed body.
- Nothing is sent to WAHA. No new webhook event: `WahaAdapter::WEBHOOK_EVENTS` does not change, so `whatsapp-web:resubscribe` is not needed.

Why not the other options: building the link when the event fires fails because `MessageReceived` fires before WAHA's file is saved (verified in `WhatsappDriver::processInboundMessage`). Base64 in the body makes large payloads that can exceed the job's 10-second timeout. Moving the webhook after the media save changes when webhooks fire and loses the webhook if the download fails.

## Rules from CLAUDE.md (REQUIRED)
- [ ] Soft-fail: a failed link lookup never blocks or drops the webhook (catch `Throwable`, log a warning without the link, send with `url: null`).
- [ ] Tests first: the failing tests exist and were seen to fail before the code (`superpowers:test-driven-development`).
- [ ] No new WAHA webhook event: `WahaAdapter::WEBHOOK_EVENTS` unchanged, no resubscribe needed. Checked by `git diff`.
- [ ] Backfilled and historical messages do not change behaviour (this task does not touch the `historical` flag or the MessageReceived firing).
- [ ] Never log or store the signed link outside the delivery body.
- [ ] Never run two test processes at once: agents do not run phpunit; the main thread runs it.
- [ ] Never `git add -A`; agents never commit; the main thread commits by file name.
- [ ] Post-deploy (listed for the user): `php artisan config:clear`. No migration, no resubscribe.

## Logic / design docs (REQUIRED)
- Read before work: `app/logic/webhook-deliveries.md` (message.received payload section), `app/logic/waha-sync-and-labels.md` (arrival and media), `tasks/plans/PLAN-002-private-media/PLAN.md`.
- Update when done: `app/logic/webhook-deliveries.md` (media object, per-attempt link, race note, the receiver check). `app/logic/private-media.md` is written by T-0029 and links to this file; this task does not edit it.

## Skills (REQUIRED)
- [x] superpowers:brainstorming — design recorded in `tasks/plans/PLAN-002-private-media/PLAN.md`; webhook decision delegated by the user
- [x] superpowers:writing-plans — steps below
- [x] superpowers:test-driven-development — the test author writes red tests first
- [x] superpowers:dispatching-parallel-agents — two agents for this task (invoked 17:55, before spawning)
- [ ] superpowers:verification-before-completion — before done
- [ ] code-reviewer — the signed link is a bearer link to a private file; audit the lookup, the signature and the logging

## Prerequisites
- [x] Tasks: none. 5e7137e is committed on main.
- [x] Environment: MySQL test database works.

## Blocked by
(not blocked)

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-002-private-media/PLAN.md` · Phase: `tasks/plans/PLAN-002-private-media/phase-01-private-media.md` · Intake: `TEMP/intake-2026-10-13-1747.md`
- Related files: `app/Listeners/DispatchOutboundWebhookListener.php`, `app/Jobs/DispatchWebhookJob.php`, `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` (`existingPath`), `app/Models/WebhookEndpoint.php`, `tests/Feature/Webhooks/MessageReceivedPayloadTest.php`, `tests/Feature/Inbox/PrivateMediaTest.php`

## Interfaces
- Consumes:
  - `WahaMediaStore::existingPath(Message $message, array $payload): ?string` (read only; returns the stored relative path or null).
  - `Storage::disk('local')->temporaryUrl(string $path, DateTimeInterface $expiration): string` (the `local` disk has `serve` => true, so the link is served at `/private-files/{path}`).
- Changes `DispatchOutboundWebhookListener::messageReceivedPayload(Message $message): array`:
  - Media row (`media_id` set, unchanged): `media` = `{id, mime_type, filename: null, url: null}`.
  - No media row, and `$message->type` is one of `image`, `video`, `audio`, `document`: `media` = `{id: null, mime_type: null, filename: null, url: null}`. A placeholder, filled in by the job.
  - Otherwise: `media` = null (unchanged).
- Produces:
  - Class `App\Services\WebhookMediaLinks` with `resolve(array $payload): array`. For `event` `message.received` with a media placeholder it looks up `Message` by `message.id`, finds the stored file with `WahaMediaStore::existingPath`, and returns the payload with `media.url` (signed, valid 1 hour), `media.mime_type` and `media.filename` filled from the message payload. If nothing is stored, `url` stays `null`. Any other event returns the input unchanged. It never throws.
  - `DispatchWebhookJob::handle(WebhookMediaLinks $links)`: builds the body from `$links->resolve($this->payload)`. `WebhookDelivery.payload` still stores the unresolved `$this->payload`.

## Steps (two agents, non-overlapping files)
Agent A — test author. Owns `tests/Feature/Webhooks/WebhookMediaLinkTest.php` (new) and `tests/Feature/Webhooks/MessageReceivedPayloadTest.php` (update expectations).
- [ ] Update `MessageReceivedPayloadTest` for the new `media` keys (`filename`, `url`) and add the placeholder case. Keep the text-message case as `media: null`.
- [ ] Write `WebhookMediaLinkTest` with the acceptance criteria below. Use `Http::fake()` for the receiver and the real `local` disk as in `PrivateMediaTest`. Use `Carbon::setTestNow` for expiry and retry timing. Do not run phpunit; the main thread confirms red.

Agent B — implementer. Owns `app/Services/WebhookMediaLinks.php` (new), `app/Jobs/DispatchWebhookJob.php`, `app/Listeners/DispatchOutboundWebhookListener.php`, `app/logic/webhook-deliveries.md`.
- [ ] Implement `WebhookMediaLinks::resolve()` with a `try`/`catch (\Throwable)` that logs `webhook.media_link_failed` with the message id and error only (never the link).
- [ ] Change the listener to build the placeholder or the new keys as in Interfaces.
- [ ] Change `DispatchWebhookJob::handle()` to resolve per attempt, and keep storing the unresolved payload in `WebhookDelivery.payload`.
- [ ] Update `app/logic/webhook-deliveries.md`: the new `media` object, the per-attempt link (1 hour), the race note, and that the isinternet receiver still returns 401 (unchanged, not in this repo).

Main thread:
- [ ] Run `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='MessageReceivedPayloadTest|WebhookMediaLinkTest|WebhookDeliveryBodyTest|WebhookEndpointTest'` (red, then green).
- [ ] Run `vendor/bin/phpstan analyse` on the changed PHP files.
- [ ] Read every diff. Commit by file name and push.

## Acceptance criteria
- [ ] A WhatsApp photo with a stored file: the delivered body has `message.media` = `{id: null, mime_type: "image/jpeg", filename, url}`, and `url` returns 200 with the file's bytes while the signature is valid. Checked by a test.
- [ ] The same link returns 403 after 1 hour (non-production response); the next attempt's link works. Checked by a test with `Carbon::setTestNow`.
- [ ] A retry at a later time sends a different signed link. Checked by a test that runs the job twice.
- [ ] A WhatsApp photo with no stored file: `url` is null, the webhook is still sent. Checked by a test.
- [ ] The storage lookup throws: the webhook is still sent with `url: null`, and a warning is logged without the link. Checked by a test.
- [ ] A text message: `media` is null (existing behaviour). Checked by `MessageReceivedPayloadTest`.
- [ ] A media-row message: `{id, mime_type, filename: null, url: null}`. Checked by `MessageReceivedPayloadTest`.
- [ ] `webhook_deliveries.payload` does not contain the signed link; `request_body` does. Checked by a test.
- [ ] `contact.created` and `campaign.completed` bodies are unchanged. Checked by a test.
- [ ] `WahaAdapter::WEBHOOK_EVENTS` is unchanged. Checked by `git diff`.
- [ ] `app/logic/webhook-deliveries.md` updated.
- [ ] phpunit for the four test classes: OK (main thread). phpstan: no new errors on changed files.

## Progress log
- 2026-10-13 17:54 created from intake `TEMP/intake-2026-10-13-1747.md` and `PLAN-002`.
- 2026-10-13 17:55 started. Two agents: test author (red tests) and implementer (code and docs).
- 2026-10-13 18:04 red run by the main thread: `WebhookMediaLinkTest` and `MessageReceivedPayloadTest`, 14 tests, 10 failures on assertions (media null, no url, no warning logged), 4 pass by design (text, contact.created, campaign.completed regression guards). Failures are not class-not-found, so the tests reach the behaviour.
- Test deviations (reported by the test author, accepted): the tests redirect the `local` disk root to a temporary folder so the real media folder is never touched; the lookup failure is simulated by mocking `WahaMediaStore`.
- Implementer started at 18:05 (`app/Services/WebhookMediaLinks.php`, the job, the listener, `webhook-deliveries.md`).

## Result
(not finished)
