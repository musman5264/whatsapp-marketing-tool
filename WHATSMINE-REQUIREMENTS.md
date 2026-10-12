# E-Whatsapp Automation Tool (WhatsMine): requirements for ticket replies

Status: draft, 2026-10-12. Nothing in the "Unverified" or "Missing" rows has been checked on the live instance yet.

## Why

esystematics (the PMS, repo CRM-Esystematics, branch `master`) sends ticket messages to clients through this tool. Staff reply from their own WhatsApp with a line like `REPLY-TK-0001`. esystematics needs the tool to deliver those replies to it, and to send the reply to the client, with images in both directions.

Source of the plan: `docs/superpowers/specs/2026-10-12-ticket-whatsapp-reply-design.md` in the esystematics repo, section 7.1.

## 1. What the tool must do

Status key: **Confirmed** = seen working in the sibling project `isinternet_beta` (live instance, per its specs). **Partly** / **Unverified** = assumed in code, never captured. **Missing** = no evidence it exists.

| ID | Requirement | Why we need it | Evidence so far | Status |
|---|---|---|---|---|
| W1 | JSON REST API under `/api/v1`, bearer token auth | Every call | `isinternet_beta` `WhatsMineClient` | Confirmed |
| W2 | `GET /api/v1/me` returns the account name | Connection test | `isinternet_beta` `verifyCredentials()` | Confirmed |
| W3 | `POST /api/v1/webhooks` with `url` and `events`, returns `id` and signing `secret` | esystematics registers its own inbound URL | `registerWebhook()`; live accepts `message.sent`, `message.received`, `campaign.completed` and rejects `message.delivered`, `read`, `failed` (sibling spec 2026-09-03) | Confirmed |
| W4 | `message.received` fires for every incoming message to the connected number | Staff replies arrive here | Event name confirmed; payload contents never captured | Partly |
| W5 | Delivery body is `{"event": "...", "data": {...}}` | Parsing | Sibling `WhatsMineWebhookController` reads `event` and `data` | Unverified |
| W6 | Header `X-Signature-256` = `sha256=` + HMAC-SHA256 of the raw body with the secret | Rejects forged calls | Sibling code uses it; sibling spec 2026-09-04 says the header name is still to be confirmed on a real callback | Unverified |
| W7 | `data` has sender number, text, and a unique message id that stays the same on retries | Parsing, duplicate protection | Sibling reads `from`, `text`/`body`, `provider_message_id`/`message_id` | Unverified |
| W8 | Line breaks in the text (`\n`) are kept | Multi-line replies | None | Unverified |
| W9 | **Images from staff are delivered**: either a URL in the payload, or a media id plus a download endpoint | Images on tickets | None. No inbound media is handled anywhere in the sibling project, and no media download call is used | **Missing** |
| W10 | The caption of an image arrives as text | `REPLY-TK-` line written as the caption | None | Unverified |
| W11 | Failed webhook deliveries are retried with the same message id; the receiver answers quickly | Duplicates, timeouts | None. Sibling answers fast and does no heavy work | Unknown |
| W12 | Messages sent from the business's own phone are not reported as `message.received` (or are flagged) | Avoids loops | None | Unknown |
| W13 | `POST /api/v1/messages/send` with `contact_id`, `phone` (E.164 with `+`), `channel`, `body` | Text to clients | Sibling `send()`; recipient rule confirmed live (sibling spec 2026-09-06) | Confirmed |
| W14 | Image send: `type: "image"`, `media_url` (public HTTPS, fetched by the tool), `caption` | Images to clients | Sibling `sendImageMessage()`; sibling docs describe image sends | Likely, not checked on this instance |
| W15 | Each send returns `provider_message_id` and `status` | Logs | Sibling reads both | Confirmed |
| W16 | Invalid number or missing contact gives HTTP 422 with a readable message | Error reporting | Sibling comments and tests | Confirmed |
| W17 | List and delete webhooks (`GET`, `DELETE /api/v1/webhooks/{id}`) | Re-register after a URL change | Sibling `listWebhooks()`, `deleteWebhook()` | Confirmed |

## 2. Webhook shape we are building against

`POST https://<pms-host>/webhooks/whatsmine/{token}`, with header `X-Signature-256: sha256=<hex>`.

Example shape only. The real field names replace this after the capture in section 4:

```json
{
  "event": "message.received",
  "data": {
    "message_id": "string, unique, stable on retry",
    "from": "+923001234567",
    "type": "text | image",
    "text": "REPLY-TK-0001\nThanks, the domain is fixed.",
    "caption": "string or null, for images",
    "media": { "url": "string", "mime_type": "image/jpeg", "size": 245760 },
    "timestamp": "2026-10-12T20:00:00Z"
  }
}
```

We need the receiver to answer `200` within a few seconds. The tool's own timeout is unknown (see section 5).

## 3. Send shape we are building against

Text:

```json
{ "contact_id": 123, "phone": "+923001234567", "channel": "whatsapp", "body": "TK-0001 ..." }
```

Image:

```json
{ "contact_id": 123, "phone": "+923001234567", "channel": "whatsapp",
  "type": "image", "media_url": "https://<pms-host>/...signed...", "caption": "TK-0001 ..." }
```

Response: `{ "provider_message_id": "...", "status": "..." }`.

## 4. Checks to run on the live instance

Run these against the connected number. Each needs a pass result recorded.

1. `GET /api/v1/me` returns 200 with the account name.
2. `GET /api/v1/webhooks` shows our webhook with `message.received` in its events.
3. From a staff phone, send four messages to the connected number and capture the full request body and the `X-Signature-256` header for each:
   - (a) one line of text;
   - (b) `REPLY-TK-0001`, a line break, then two lines of text;
   - (c) an image alone;
   - (d) an image with the caption `REPLY-TK-0001` and a line break, then text.
   
   Pass: each body has a sender, a text (with line breaks intact), and a unique message id. Images give a URL or media id that can be downloaded. Record the real field names, which replace section 2.
4. Recompute `sha256=HMAC(raw body, secret)` for capture (b). It must equal the header. This settles W6.
5. Send a text to a test client number with a line break. Pass: 200, a `provider_message_id`, and the phone shows the line break.
6. Send an image with `media_url` and a caption. Pass: the phone shows the image with the caption. This settles W14.
7. Send to an invalid number. Pass: HTTP 422 with a readable message (W16).
8. Re-send a captured webhook body to our endpoint. Pass: esystematics ignores the duplicate. (This runs on esystematics, not the tool.)

Who runs what: the connected number, a staff phone and a test client number are needed. The base URL and token are kept in esystematics production settings, not in this repo or in chat.

## 5. Questions for the tool's provider or admin

1. **W9:** Are inbound images delivered? As a URL, a media id, or base64? If a media id, which endpoint downloads it, and with which auth?
2. **W11:** How long does the tool wait for our answer, and how many times does it retry a failed delivery? Does a retry keep the same message id?
3. **W12:** Are messages sent from the connected phone itself reported to the webhook?
4. **Images:** maximum size and allowed types, for inbound and outbound.
5. **Media URL:** how long does the tool wait when fetching a `media_url`, and does it need the URL to be public with no login?
6. **Limits:** rate limits per token, and per number.
7. **Groups:** are messages from WhatsApp groups reported to `message.received`? They should be excluded.
8. **Outbound window:** does this channel have any messaging window restriction (like the 24-hour rule on Meta Cloud API)? Expected: none, but confirm.
9. **Signature:** confirm the header name and the exact signing string (raw body, or a timestamp plus body).

## 6. What esystematics does if a requirement fails

- **W9 (inbound images) fails:** staff send images another way, or ticket replies go out as text only. This is a decision for the project owner before the image work starts.
- **W6 (signature) differs:** esystematics rejects the calls until the header rule is fixed. Nothing is accepted unsigned.
- **W14 (outbound images) fails:** clients get the text only, with a note that the image is on the ticket.
