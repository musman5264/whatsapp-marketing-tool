# WhatsApp Web (WAHA): sync, phone-sent messages, labels, contact save

## Webhook
- Events are `WahaAdapter::WEBHOOK_EVENTS`: `message.any`, `session.status`, `message.ack`, `message.reaction`, `poll.vote`, `call.*`, `label.upsert|deleted|chat.added|chat.deleted`.
- Existing sessions are updated with `PUT /api/sessions/{s}` + start (`WahaAdapter::resubscribe`, `php artisan whatsapp-web:resubscribe`, or **Sync now** in Inbox → Setup). The number is not unlinked.
- Entry: `WhatsappWebWebhookController` → dedup → `WahaEventProcessor::process()`.

## Messages
- `fromMe=false` → inbound via `InboundNormalizer::normalize` → `WhatsappDriver::ingestNormalizedInbound`.
- `fromMe=true` (sent from the phone / linked device) → `normalizeFromMe` → `ingestNormalizedOutbound`: stored as `direction=out`, clears `unread_count`, fires `MessageSent`, no automations. `source=api` (our own sends) is skipped.
- Acks: `handleAck` matches the stored provider id in full or bare form; status never downgrades.

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
