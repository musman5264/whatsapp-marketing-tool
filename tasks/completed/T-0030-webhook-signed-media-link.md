---
id: T-0030
title: Webhook: send the WhatsApp photo or file as a signed link that expires (fresh link on each attempt)
status: completed
priority: high
type: feature
plan: tasks/plans/PLAN-002-private-media/PLAN.md
phase: tasks/plans/PLAN-002-private-media/phase-01-private-media.md
depends_on: []
source: TEMP/intake-2026-10-13-1747.md
created: 2026-10-13 17:54
started: 2026-10-13 17:55
updated: 2026-10-14
completed: 2026-10-14
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
- [x] Soft-fail: a failed link lookup never blocks or drops the webhook (catch `Throwable`, log a warning without the link, send with `url: null`). Checked by `test_a_failing_storage_lookup_still_sends_the_webhook_and_logs_a_warning_without_the_link`.
- [x] Tests first: the failing tests were seen to fail before the code (red run 2026-10-13 18:04: 10 of 14 failed on assertions).
- [x] No new WAHA webhook event: `WahaAdapter::WEBHOOK_EVENTS` unchanged (commit `920b127` does not touch `WahaAdapter`).
- [x] Backfilled and historical messages: this task does not touch the `historical` flag or the MessageReceived firing (commit `920b127` does not touch `WhatsappDriver`).
- [x] Never log or store the signed link outside the delivery body. Checked by `test_the_stored_delivery_payload_has_no_signed_link_but_the_sent_body_does`; the warning logs only the message id and error text.
- [x] Never run two test processes at once: the main thread ran phpunit alone.
- [x] Never `git add -A`; agents never commit; the main thread commits by file name (commit `920b127`).
- [ ] Post-deploy (listed for the user): `php artisan config:clear`. No migration, no resubscribe. Not done here; it is a live step.

## Logic / design docs (REQUIRED)
- [x] `app/logic/webhook-deliveries.md` updated: the media object, the per-attempt link (1 hour), the race note, the receiver check.
- `app/logic/private-media.md` links to this file and was not edited by this task.

## Skills (REQUIRED)
- [x] superpowers:brainstorming — design recorded in `tasks/plans/PLAN-002-private-media/PLAN.md`; webhook decision delegated by the user
- [x] superpowers:writing-plans — steps below
- [x] superpowers:test-driven-development — the test author wrote red tests first
- [x] superpowers:dispatching-parallel-agents — two agents for this task (invoked 17:55, before spawning)
- [x] superpowers:verification-before-completion — the green run below
- [ ] code-reviewer — the signed link is a bearer link to a private file; audit the lookup, the signature and the logging. **Not run yet. Still open.**

## Prerequisites
- [x] Tasks: none. 5e7137e is committed on main.
- [x] Environment: MySQL test database works.

## Blocked by
(not blocked)

## References
- Main instructions: `CLAUDE.md`
- Tracker notes: `tasks/INSTRUCTIONS.md`
- Plan: `tasks/plans/PLAN-002-private-media/PLAN.md` · Phase: `tasks/plans/PLAN-002-private-media/phase-01-private-media.md` · Intake: `TEMP/intake-2026-10-13-1747.md`
- Related files: `app/Listeners/DispatchOutboundWebhookListener.php`, `app/Jobs/DispatchWebhookJob.php`, `app/Services/WebhookMediaLinks.php`, `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` (`existingPath`), `app/Models/WebhookEndpoint.php`, `tests/Feature/Webhooks/WebhookMediaLinkTest.php`, `tests/Feature/Webhooks/MessageReceivedPayloadTest.php`

## Interfaces
- Consumes:
  - `WahaMediaStore::existingPath(Message $message, array $payload): ?string` (read only; returns the stored relative path or null).
  - `Storage::disk('local')->temporaryUrl(string $path, DateTimeInterface $expiration): string` (the `local` disk has `serve` => true, so the link is served at `/private-files/{path}`).
- Changes `DispatchOutboundWebhookListener::messageReceivedPayload(Message $message): array`:
  - Media row (`media_id` set, unchanged shape): `media` = `{id, mime_type, filename, url}`, with `filename` and `url` null unless the message stores a filename.
  - No media row, WhatsApp Web channel, and `$message->type` is one of `image`, `video`, `audio`, `document`: `media` = `{id: null, mime_type: null, filename: null, url: null}`. A placeholder, filled in by the job.
  - Otherwise: `media` = null (unchanged).
- Produces:
  - Class `App\Services\WebhookMediaLinks` with `resolve(array $payload): array`. For `event` `message.received` with a media placeholder it looks up `Message` by `message.id`, finds the stored file with `WahaMediaStore::existingPath`, and returns the payload with `media.url` (signed, valid 1 hour), `media.mime_type` and `media.filename` filled from the message payload. If nothing is stored, `url` stays `null`. Any other event returns the input unchanged. It never throws.
  - `DispatchWebhookJob::handle(?WebhookMediaLinks $links = null)`: builds the body from `$links->resolve($this->payload)` (the optional argument falls back to the container). `WebhookDelivery.payload` still stores the unresolved `$this->payload`.

## Steps (two agents, non-overlapping files)
Agent A — test author. Owns `tests/Feature/Webhooks/WebhookMediaLinkTest.php` (new) and `tests/Feature/Webhooks/MessageReceivedPayloadTest.php` (update expectations).
- [x] `MessageReceivedPayloadTest` updated for the new `media` keys and the placeholder case. The text-message case stays `media: null`.
- [x] `WebhookMediaLinkTest` written with the acceptance criteria below.

Agent B — implementer. Owns `app/Services/WebhookMediaLinks.php` (new), `app/Jobs/DispatchWebhookJob.php`, `app/Listeners/DispatchOutboundWebhookListener.php`, `app/logic/webhook-deliveries.md`.
- [x] `WebhookMediaLinks::resolve()` with a `try`/`catch (\Throwable)` that logs `webhook.media_link_failed` with the message id and error only (never the link).
- [x] Listener builds the placeholder or the new keys as in Interfaces.
- [x] `DispatchWebhookJob::handle()` resolves per attempt; `WebhookDelivery.payload` keeps the unresolved payload.
- [x] `app/logic/webhook-deliveries.md` updated.

Main thread:
- [x] Red run (2026-10-13 18:04), then green (2026-10-13 18:19, see Result).
- [x] PHPStan on the changed PHP files (2026-10-14): no errors.
- [x] Diffs read. Committed by file name (`920b127`) and pushed.

## Acceptance criteria
- [x] A WhatsApp photo with a stored file: the delivered body has `message.media` = `{id: null, mime_type, filename, url}`, and `url` returns 200 with the file's bytes while the signature is valid. Checked by `test_a_whatsapp_photo_is_sent_with_a_signed_link_that_serves_the_file`.
- [x] The same link returns 403 after 1 hour (non-production response); the next attempt's link works. Checked by `test_the_link_is_refused_after_one_hour_and_a_new_run_sends_a_working_link`.
- [x] A retry at a later time sends a different signed link. Checked by `test_two_runs_at_different_times_send_two_different_signed_links`.
- [x] A WhatsApp photo with no stored file: `url` is null, the webhook is still sent. Checked by `test_a_photo_with_no_stored_file_is_sent_with_a_null_link`.
- [x] The storage lookup throws: the webhook is still sent with `url: null`, and a warning is logged without the link. Checked by `test_a_failing_storage_lookup_still_sends_the_webhook_and_logs_a_warning_without_the_link`.
- [x] A text message: `media` is null (existing behaviour). Checked by `test_text_message_payload_includes_the_sender_number_and_body` (MessageReceivedPayloadTest).
- [x] A media-row message: `{id, mime_type, filename: null, url: null}` with no link. Checked by `test_a_media_row_message_keeps_id_and_mime_type_and_has_no_filename_or_link`.
- [x] `webhook_deliveries.payload` does not contain the signed link; `request_body` does. Checked by `test_the_stored_delivery_payload_has_no_signed_link_but_the_sent_body_does`.
- [x] `contact.created` and `campaign.completed` bodies are unchanged. Checked by `test_the_contact_created_body_keeps_its_shape_and_has_no_media_or_link` and `test_the_campaign_completed_body_keeps_its_shape_and_has_no_media_or_link`.
- [x] `WahaAdapter::WEBHOOK_EVENTS` is unchanged. Checked by the file list of commit `920b127`.
- [x] `app/logic/webhook-deliveries.md` updated.
- [x] phpunit for the webhook test classes: OK. phpstan: no errors on the changed files.

## Progress log
- 2026-10-13 17:54 created from intake `TEMP/intake-2026-10-13-1747.md` and `PLAN-002`.
- 2026-10-13 17:55 started. Two agents: test author (red tests) and implementer (code and docs).
- 2026-10-13 18:04 red run by the main thread: `WebhookMediaLinkTest` and `MessageReceivedPayloadTest`, 14 tests, 10 failures on assertions (media null, no url, no warning logged), 4 pass by design (text, contact.created, campaign.completed regression guards). Failures are not class-not-found, so the tests reach the behaviour.
- Test deviations (reported by the test author, accepted): the tests redirect the `local` disk root to a temporary folder so the real media folder is never touched; the lookup failure is simulated by mocking `WahaMediaStore`.
- 2026-10-13 18:19 green run by the main thread: `OK (34 tests, 150 assertions)` for `MakePrivateMediaCommandTest|WebhookMediaLinkTest|MessageReceivedPayloadTest|WebhookDeliveryBodyTest|WebhookEndpointTest`. Committed as `920b127` after the fixes listed in that commit.
- 2026-10-14 `WebhookMediaLinkTest` green again in a 36-test run (`OK (36 tests, 143 assertions)`), on the code as committed.

## Result
Done as code on 2026-10-14: the webhook media link, its tests and docs are committed (`920b127`) and pushed. Not verified live: the race (a webhook attempt that runs before the file is saved sends `url: null`), the link on the live server, and the receiver. The isinternet receiver returns 401 on every delivery and cannot use the link until its own check is fixed (outside this repo).

Open: the code-reviewer audit of the signed link (lookup, signature and logging).
