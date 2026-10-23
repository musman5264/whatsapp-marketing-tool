# WhatsApp Web (WAHA): sync, phone-sent messages, labels, contact save

## Webhook
- Events are `WahaAdapter::WEBHOOK_EVENTS`: `message.any`, `session.status`, `message.ack`, `message.reaction`, `poll.vote`, `call.*`, `label.upsert|deleted|chat.added|chat.deleted`.
- Existing sessions are updated with `PUT /api/sessions/{s}` + start (`WahaAdapter::resubscribe`, `php artisan whatsapp-web:resubscribe`, or **Sync now** in Inbox → Setup). The number is not unlinked.
- Entry: `WhatsappWebWebhookController` → dedup → `WahaEventProcessor::process()`.

## Messages
- `fromMe=false` → inbound via `InboundNormalizer::normalize` → `WhatsappDriver::ingestNormalizedInbound`.
- `fromMe=true` (sent from the phone / linked device) → `normalizeFromMe` → `ingestNormalizedOutbound`: stored as `direction=out`, clears `unread_count`, fires `MessageSent`, no automations. `source=api` (our own sends) is skipped.
- Acks: `handleAck` matches the stored provider id in full or bare form; status never downgrades.

## Inbound from a LID sender (no phone number in the payload)
- WhatsApp often sends the chat as `<digits>@lid`, with no phone number. `InboundNormalizer::resolveSender()` asks WAHA `GET /api/contacts?contactId=<lid>` for the phone number. A reply with an `@c.us` id gives the number. That number is stored in the table `whatsapp_web_lid_mappings` (one row per session and LID), so it survives cache clears and failed lookups. The next message from that LID is resolved from the table without calling WAHA.
- If WAHA cannot map the LID (its reply has no `@c.us` id), or the lookup fails, the message is **kept**. The LID's own digits stand in for the phone number, the contact is created with that number, and the message is stored. Not cached, so the next message from that sender is resolved again. Logged as `whatsapp_web.inbound.lid_unmapped` (info).
- Before this change the message was dropped (`normalize()` returned null) and only the warning `whatsapp_web.inbound.unresolved_sender` was logged. Found on 2026-10-14: inbound messages were missing while outbound worked.
- Repair: `php artisan whatsapp-web:lid-map <lid> <phone> --session=<name>` records a mapping and moves the messages stored under the LID digits into the real contact's conversation. Dry run by default; `--apply` writes. The stray contact is kept, not deleted. Second key on the Deployment page (see deployment-commands.md).
- Number lookup order for a LID (2026-10-23, pushed, not yet run live): (1) the mapping table; (2) the number in the message itself, searched at any depth under `_data`: NOWEB `remoteJidAlt`; GOWS `SenderAlt` (received) or `RecipientAlt` (sent by us). Never `RecipientAlt` on a received DM: that is our own number. The saved result goes to the mapping table; (3) `WahaAdapter::resolveContact()`: WAHA `GET /api/{session}/lids/{lid}` (`{lid, pn}`, listed as supported on GOWS) first, then `GET /api/contacts`. If all fail, the LID digits stand in and the customer's profile name (`pushName` / `PushName`, received messages only) is used as the name.
- Verified from source: whatsmeow `types.MessageSource` has `SenderAlt`, `RecipientAlt`, `AddressingMode`, and `MessageInfo.PushName` with no json tags, and GOWS (`src/server/events.go`) `json.Marshal`s the whatsmeow event as-is. **Not verified:** how WAHA nests that object inside the webhook's `_data`, which is why the search is depth-agnostic. `whatsapp_web.inbound.lid_unmapped` logs `data_keys` (key paths only, no values) so the real shape can be read from the log.
- WAHA only knows the number of people saved in the linked phone's contacts (or group members when it is group admin).
- Until a LID is mapped, a message from it is stored under a contact named `+<lid digits>`. Replies first go to `<digits>@c.us`; WAHA (GOWS) answers `500 no LID found for <digits>@s.whatsapp.net`, and `WahaAdapter::send()` then retries the same call once with `<digits>@lid` (any other error is not retried). Found 2026-10-23 on the Menu Automation run. Whether WhatsApp delivers through the `@lid` address on the live engine is **unverified**. Why numbers are hidden at all: WhatsApp's LID privacy change; WAHA can only map a LID to a number when the person is in the linked phone's contacts or the account is a group admin (WAHA docs, Lids FAQ).
- Tests: `tests/Feature/WhatsappWeb/InboundWebhookTest.php`: `lid_sender_that_waha_cannot_map_is_still_stored`, `lid_sender_is_stored_when_the_lookup_fails` (red before the fix, green after). `tests/Feature/WhatsappWeb/AdapterActionsTest.php`: `send_retries_through_the_lid_address_when_waha_has_no_lid_for_the_number`, `send_does_not_retry_other_waha_errors`.

## Media (images, video, audio/voice notes, documents)
- Arrival: right after `WhatsappDriver::ingestNormalizedInbound` saves a live inbound message, `WahaEventProcessor::handleInbound` calls `WahaMediaStore::storeInbound()`. It downloads the WAHA link through the configured engine only (API key attached, host rebased to the engine origin), stores it as `message-media/{id}.{ext}` and records `preview_url`, `mime_type` and `media_path` on the payload. Then `MessageMediaReady` tells an open chat (private channel `conversation.{id}`) to show it.
- Type rule (2026-10-13): WAHA sends a photo, video or file as type `chat` (or `text`) with `hasMedia=true` and the caption as the body. `InboundNormalizer::mapType()` takes the kind from `media.mimetype` (image/video/audio, else document) so the file is downloaded. Before this, such messages were stored as empty text and shown as "(media)".
- Soft-fail: any failure is logged (`whatsapp_web.media.arrival_failed`); the message stays, and the copy is fetched lazily when someone opens it.
- Lazy fallback: `InboxController::serveMedia` (route `client.inbox.message-media`) uses the stored copy when there is one, otherwise downloads it through `WahaMediaStore::store()` and redirects. Errors map to 404 (gone on the engine), 502 (engine unreachable or failed), 503 (engine not configured).
- Stored-copy lookup: `WahaMediaStore::existingPath()` checks the recorded `media_path` (or, for older copies, the name derived from `mime_type`). It never lists the media folder.
- Historical (backfilled) media is not fetched at arrival; it keeps the lazy path.
- Not verified on the live site: whether the arrival download finishes before PHP's time limit on the host (30 s engine timeout), and playback of WhatsApp voice notes (ogg/opus) in every browser.

## History sync
- `WahaHistorySync` (`whatsapp-web:sync {--workspace} {--chats=50} {--messages=30} {--queue} {--resubscribe}`), job `SyncWhatsappWebHistoryJob` (queue `whatsapp`), scheduled every 10 minutes in `routes/console.php`, run once after the session becomes active, and by `POST /app/whatsapp-web/sync`.
- Backfilled inbound older than 5 minutes: no `MessageReceived`, no unread bump, thread never moves backwards.
- Needs the queue drain cron.

## Labels
- Rules: table `inbox_label_rules`, `LabelRuleEvaluator` (keyword any/all, regex, first_message, new_contact; Unicode-safe), listener `ApplyInboxLabelRules` on `MessageReceived`, UI `resources/js/Pages/Inbox/Labels/LabelRulesSection.jsx`.
- Mirror to WhatsApp: `WahaLabelSync` (merges with the chat's existing remote labels because the WAHA PUT replaces them; `inbox_labels.wa_label_id`). 4xx/501 parks the session for 1 hour (`meta_json.labels_supported=false`). WAHA documents labels for WhatsApp Business only.

## Contact save
- `WahaContactSaver` → `PUT /api/{session}/contacts/{chatId}` `{firstName,lastName}`; once per contact (`custom_fields.saved_to_phone_at` / `phone_save_error`); never throws. Listener `SaveContactToPhone`.
- Toggles on `whatsapp_web_sessions`: `auto_label_enabled`, `mirror_wa_labels`, `auto_save_contacts` (Inbox → Setup card).

## Unverified (no live WAHA test yet)
- Whether NOWEB exposes an unread count in `chats/overview` (read defensively).
- ack id format and `source` value NOWEB actually sends.
- Whether the label endpoints work on the live number, and whether the contact PUT succeeds on NOWEB.

**Tests:** `tests/Feature/WhatsappWeb/WahaSyncCoreTest.php`, `WahaLabelSyncTest.php`, `WahaContactSaverTest.php`, `tests/Feature/Inbox/LabelRuleEvaluatorTest.php`.

## Health check (why chats/messages are not arriving)
- `WhatsappWebHealth` (`app/Modules/WhatsappWeb/Services/WhatsappWebHealth.php`): read-only checks for engine status, webhook URL + event list registered in WAHA (`GET /api/sessions/{s}` → `config.webhooks`), last webhook received (cache key `whatsapp_web:last_webhook:{session}`, written by `WhatsappWebWebhookController`), latest stored in/out messages, realtime (config + one test broadcast on channel `whatsapp-health-check`), queue (`queue.default` vs the `queue:work redis` workers in `docker/supervisor/whatsmine.conf`) and scheduler heartbeat.
- Entry points: `php artisan whatsapp-web:diagnose [--workspace=]`, `GET /app/whatsapp-web/health`, button **Run check** in Inbox → Setup.
- The webhook token is masked in all output. Tests: `tests/Feature/WhatsappWeb/WhatsappWebHealthTest.php`.
