# Draft answers to the esystematics questionnaire (from our own code)

Date: 2026-10-12
Source questionnaire: [WHATSMINE-QUESTIONS-2026-10-12.md](WHATSMINE-QUESTIONS-2026-10-12.md)

**What this is:** an answer sheet built only from code and notes that exist in our workspace. It is not a statement from WhatsMine. No WhatsMine server code or documentation is in the workspace, and the web search found none.

**Evidence sources**
- `isinternet_beta` (sibling project, a live WhatsMine client): `app/Services/WhatsMine/WhatsMineService.php`, `WebhookRegistrar.php`, `WebhookProcessor.php`, `WhatsMineClient.php`, `app/Http/Controllers/WhatsMineWebhookController.php`, and its specs in `docs/superpowers/specs/`.
- `esystematics` (the PMS): `app/Services/WhatsMineClient.php` (its comments name the same rules).

**Status key**
- **Live (notes):** the sibling's specs say this was confirmed against a live WhatsMine instance. We have not re-checked it.
- **Code:** the code assumes this. Nothing records a live confirmation.
- **Unknown:** no evidence either way. Only WhatsMine, or a live test, can settle it.

## 3.1 Inbound

| ID | Draft answer | Evidence | Status |
|---|---|---|---|
| IN-1 | Request: `POST /api/v1/webhooks` with `url` and `events`. Response is read for `id` and `secret`. | `WhatsMineService::registerWebhook()` | Live (notes) for the events; the response shape is Code |
| IN-2 | `GET /api/v1/webhooks` lists webhooks. `DELETE /api/v1/webhooks/{id}` removes one. A 404 on delete is treated as already gone. | `WhatsMineService::listWebhooks()`, `deleteWebhook()` | Code |
| IN-3 | Unknown. | None | Unknown |
| IN-4 | Unknown. The sibling registers its own tenant URL. Nothing shows an allow-list. | `WebhookRegistrar` | Unknown |
| IN-5 | Code reads `event` and `data` from the body, i.e. `{"event": "...", "data": {...}}`. No captured sample exists. | `WhatsMineWebhookController::handle()` | Code |
| IN-6 | Code reads the sender from `from`, then `phone_e164`, then `phone`, and normalises it. Format not confirmed. | `WebhookProcessor::handleInbound()` | Code (field names); format Unknown |
| IN-7 | Code reads the text from `text`, then `body`. Whether line breaks survive is Unknown. | `WebhookProcessor::handleInbound()` | Code (field names); line breaks Unknown |
| IN-8 | Code reads `provider_message_id`, then `message_id`. Uniqueness and retry behaviour are Unknown. | `WebhookProcessor::applyDeliveryStatus()`, `handleInbound()` | Code (field names); rest Unknown |
| IN-9 | Unknown. The sibling only subscribes to `message.received` and never checks whether a message was sent by the account. | `WebhookRegistrar` | Unknown |
| IN-10 | Code expects header `X-Signature-256` with value `sha256=` + HMAC-SHA256 of the raw body with the secret. A mismatch gets 401. The sibling's own spec says the header name still needs a live check. | `WhatsMineWebhookController::handle()`; spec 2026-09-04 | Code; header name not confirmed |
| IN-11 | Unknown. | None | Unknown |
| IN-12 | Unknown. The sibling answers after processing, with no queue. | `WhatsMineWebhookController` | Unknown (WhatsMine's wait time) |
| IN-13 | Unknown. The sibling stores the secret when registration returns it, and falls back to a locally made secret if registration fails. | `WebhookRegistrar::sync()` | Unknown |
| IN-14 | **No evidence.** The sibling handles no inbound media at all, and calls no media download endpoint. | All sibling WhatsMine code | Unknown (main gap) |
| IN-15 | Unknown. | None | Unknown |
| IN-16 | Unknown. | None | Unknown |
| IN-17 | Unknown beyond text. The sibling only reads text and does nothing with other types. | `WebhookProcessor::handleInbound()` | Unknown |
| IN-18 | Probably yes. The sibling stores inbound messages even when no customer matches (`customer_id` is null), so unknown senders reach the handler. | `WebhookProcessor::handleInbound()` | Code |

## 3.2 Outbound

| ID | Draft answer | Evidence | Status |
|---|---|---|---|
| OUT-1 | **No.** `contact_id` is required. Phone-only sends get HTTP 422. | `esystematics` `WhatsMineClient` comment; `WhatsMineService` comment | Live (notes, 2026-09-06) |
| OUT-2 | `GET /api/v1/contacts?search=<E.164>` returns a list; the first id is used. If none, `POST /api/v1/contacts` with `phone_e164`, `first_name`, `opt_in_whatsapp: true`. What `opt_in` means and whether consent is needed is Unknown. | `WhatsMineService::resolveContactId()` | Live (notes) for the calls; opt-in meaning Unknown |
| OUT-3 | Code sends `type: "image"`, `media_url` and `caption` on `messages/send`. Allowed MIME types and size are Unknown. | `WhatsMineService::sendImageMessage()` | Code; limits Unknown |
| OUT-4 | Unknown when WhatsMine fetches `media_url`. The sibling sends a signed link. | `WhatsMineService` | Unknown |
| OUT-5 | Code reads `provider_message_id` (or `message_id`) and `status` from the response. | `WhatsMineService::send()` | Code |
| OUT-6 | Code sends `Accept: application/json`. Without it, a failed validation comes back as an HTML 302, not JSON. Error body shapes for 401, 404, 429 and 500 are Unknown. | `esystematics` `WhatsMineClient` comment; `WhatsMineClient::request()` | Code (JSON with the header); other shapes Unknown |
| OUT-7 | Unknown on the server. The sibling client has its own throttle, but no server limit is recorded. | `WhatsMineClient::throttle()` | Unknown |
| OUT-8 | **Yes, with conditions.** The sibling subscribes only to `message.sent`, `message.received` and `campaign.completed`. Its instance rejects `delivered`, `read` and `failed`. Its docs say only the delivered and read badges depend on those events, so sends are not blocked by leaving them unsubscribed. | `WebhookRegistrar`; spec 2026-09-03; sibling docs `17-whatsapp-automation.md` | Live (notes) for the rejection; send behaviour from docs |
| OUT-9 | Unknown. | None | Unknown |

## 3.3 Account and operations

| ID | Draft answer | Evidence | Status |
|---|---|---|---|
| OPS-1 | **Yes.** `GET /api/v1/me` returns the account, and the admin "Test Connection" uses it. | `WhatsMineService::verifyCredentials()`; `esystematics` `WhatsMineClient::testConnection()`; sibling docs | Live (notes) |
| OPS-2 | Unknown. The sibling uses a bearer token only. | `WhatsMineClient` | Unknown |
| OPS-3 | Unknown. | None | Unknown |
| OPS-4 | Unknown. | None | Unknown |
| OPS-5 | Unknown. | None | Unknown |
| OPS-6 | Unknown. | None | Unknown |

## Summary

- **Answered from notes (live):** OUT-1, OUT-2 (calls), OUT-8, OPS-1.
- **Answered from code only:** IN-1, IN-2, IN-5 to IN-8, IN-10, IN-18, OUT-3, OUT-5, OUT-6.
- **Unknown:** the rest. The most important is **IN-14 (inbound images)**: nothing in our workspace shows whether WhatsMine delivers them.

Anything in "Code" or "Unknown" needs WhatsMine's own answer or a live test before esystematics builds on it. The four live test messages in the questionnaire (section 6) are the way to settle the inbound items.
