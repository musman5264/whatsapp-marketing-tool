# What esystematics needs from the E-Whatsapp Automation Tool (WhatsMine)

Date: 2026-10-12
Status: request, waiting for written answers
From: esystematics PMS (Laravel app, staff admin panel and client portal)
To: the E-Whatsapp Automation Tool (WhatsMine) project

Saved verbatim from the request pasted into the WhatsApp repo on 2026-10-12. Answers are not filled in here: they must come from WhatsMine in writing.

## 1. Summary

esystematics needs staff to reply to client support tickets from their own WhatsApp. A staff member sends a message to the connected number. esystematics receives it through a webhook, saves the reply on the ticket, sends it to the client, and confirms to the staff member. Staff can also change the ticket status in the same message, and attach images.

To build this, esystematics needs WhatsMine to:
- deliver inbound messages (text, line breaks, images, captions) to a webhook, signed, with a stable message id;
- send text and images to client numbers;
- describe its API precisely where the answers below are marked as needed.

Please answer each item in section 3 in writing. For each item, give one of: **Yes**, **No**, or **Yes, with conditions** (state the conditions). Where a sample is asked for, paste a real payload with secrets and phone numbers redacted (keep the shape, use a test number if you have one).

## 2. The flow we need

Staff message to the connected number:

```
REPLY-TK-0001
Hi, the domain DNS is now updated. Please check and confirm.
STATUS=RESOLVED
[image attached]
```

- Line 1 is the ticket reference. It must be on its own line; the body starts on the next line.
- An optional line `STATUS=<value>` can appear after line 1.
- Optional images can be attached to the same message, with or without a caption.

What esystematics does with it:
1. Receives `message.received` at a public HTTPS URL it registered.
2. Checks the signature with the webhook secret. Rejects anything that does not match.
3. Stores the message once, using the message id, so a retry never runs twice.
4. Saves the reply on the ticket and sets the status, if given.
5. Sends the reply text (and any images) to the client's WhatsApp number.
6. Sends a short confirmation back to the staff member's number.

Only the assigned staff member's number, or the owner's number when a ticket has no assignee, is allowed to reply.

## 3. Items to answer

### 3.1 Inbound: messages from staff to esystematics

| ID | Need | Why esystematics needs it | Answer |
|---|---|---|---|
| IN-1 | `POST /api/v1/webhooks` accepts `url` and `events: ["message.received"]` and returns `id` and a signing `secret`. Please state the exact request and response shape. | To register the inbound webhook. | |
| IN-2 | `GET /api/v1/webhooks` lists webhooks (id, url, events). `DELETE /api/v1/webhooks/{id}` removes one. | Test captures are removed after use, and the real webhook must be the only one left. | |
| IN-3 | Can one account have more than one webhook subscribed to `message.received`? If yes, does each one receive every message? | A temporary capture webhook may run next to the real one. | |
| IN-4 | Can the webhook URL be any public HTTPS host, or is there an allow-list? | The capture runs from a server or tunnel that is not the production domain. | |
| IN-5 | The delivery body shape. Is it `{"event": "message.received", "data": {...}}`? Give a sample. | Parsing. | |
| IN-6 | Which field holds the **sender's number**, and in what format: `+` and digits, digits only, or local? | Matching the sender to the assignee or owner. | |
| IN-7 | Which field holds the **text**? Are line breaks (`\n`) kept exactly as typed by the sender? | The reference is line 1, and the body follows. | |
| IN-8 | Which field holds the **message id**? It must be unique, and the same on a retry. | De-duplication. | |
| IN-9 | Are messages sent by the account itself (through the API) ever delivered as `message.received`? If so, which field marks them? | Prevents a reply loop. | |
| IN-10 | **Signature:** the header name (we expect `X-Signature-256`), its value format (we expect `sha256=` followed by the hex HMAC-SHA256 of the raw body with the webhook secret), and that the HMAC is over the raw bytes exactly as sent. Give one signed sample with the secret redacted and the check result. | Rejects forged calls. Everything depends on this. | |
| IN-11 | Retry rules: which responses trigger a retry (non-2xx, timeout)? How many attempts, and at what intervals? Are retries marked, and do they keep the same message id? | Duplicate handling. | |
| IN-12 | How long does WhatsMine wait for our response before it counts a timeout? | We answer `200` at once and process after the response. | |
| IN-13 | If the webhook **secret** is lost, can it be read again or rotated without deleting the webhook? | The secret is shown once when the webhook is created. | |
| IN-14 | **Inbound images (the main gap):** when staff send an image, is it delivered? If yes, say which of these it is: (a) a media URL in the payload, or (b) a media id plus a download call. For (b), give the endpoint, method and auth. For both, state the MIME types, size limit, and how long the URL or id stays valid. If images are **not** delivered, say so plainly. | Images in a reply are saved on the ticket. | |
| IN-15 | Does an image's **caption** arrive as text, and in which field? | The reference line is usually sent as the caption. | |
| IN-16 | Does an image with **no** caption still arrive as `message.received`? | An image alone is a valid reply with no body. | |
| IN-17 | List the `type` values that can arrive on `message.received` (text, image, document, audio, video, sticker, location, others). For each, give the field names. | We handle text and image. The others must not break the webhook. | |
| IN-18 | A staff phone sends to the connected number. Does `message.received` fire for every sender, including numbers that are not saved as contacts? | Staff may not be saved as contacts. | |

### 3.2 Outbound: messages from esystematics to clients

| ID | Need | Why esystematics needs it | Answer |
|---|---|---|---|
| OUT-1 | `POST /api/v1/messages/send`: is a `phone` in E.164 (`+` and digits) accepted **without** a `contact_id`? Our earlier notes say `contact_id` was required and a phone-only send returned HTTP 422. Please confirm the current rule. | Sends to client numbers. | |
| OUT-2 | If a contact is needed: how do `GET /api/v1/contacts?search=` and `POST /api/v1/contacts` work? What does `opt_in_whatsapp: true` mean, and is a consent record required? | We create contacts for clients on first send. | |
| OUT-3 | Send an image: is `type: image` with `media_url` and `caption` supported? Give the required fields, allowed MIME types (we expect jpeg, png, gif, webp), and the size limit. | Images on a reply go to the client. | |
| OUT-4 | When is `media_url` fetched: at send time, once, or later? What is the maximum time the URL must stay valid? Can the URL be on any public HTTPS host? | Our links are signed and expire after a configured number of days. | |
| OUT-5 | What does a successful send return (message id, status)? | We store the id on the outgoing log. | |
| OUT-6 | Error responses: give the body shape for 401, 404, 422, 429 and 500, with examples. Confirm that `Accept: application/json` returns JSON errors (not an HTML redirect) for validation failures. | We report the error to the staff member in plain words. | |
| OUT-7 | Rate limits: per-account limits for sends and for API calls, and the response headers that show them. | Staff may send several replies in a row. | |
| OUT-8 | Status callbacks (`message.delivered`, `read`, `failed`): can we leave them unsubscribed and still send normally? | We do not need them now. | |
| OUT-9 | Formatting: do `*bold*` and `_italic_` pass through unchanged? Are line breaks in `body` shown on the phone as sent? | The client message uses a separator line of 32 dashes and a footer. | |

### 3.3 Account and operations

| ID | Need | Why esystematics needs it | Answer |
|---|---|---|---|
| OPS-1 | `GET /api/v1/me` returns the account name and status. Confirm. | Connection check in the admin settings. | |
| OPS-2 | Bearer token: how it is rotated, and which permissions it needs for webhooks, messages, contacts and media. | Least privilege. | |
| OPS-3 | Data retention: how long inbound messages, media and send logs are kept, and how a deletion is requested or done by API. | The stored data includes phone numbers and message text. | |
| OPS-4 | Can we see delivery attempts for a webhook (time, status code, response)? | Debugging missed or failed deliveries. | |
| OPS-5 | Is there a test number or sandbox we can use for the live check, separate from production clients? | Live testing without messaging real clients. | |
| OPS-6 | Notice period before a change to the webhook payload, signature or auth. | We must update the parser and the checks. | |

## 4. Out of scope for now

We do not need: WhatsApp Cloud API mode, group messages, interactive buttons, template messages, voice or video, and status callbacks. Please do not design anything for them on our behalf.

## 5. What we will do with your answers

- Update the esystematics spec (`docs/superpowers/specs/2026-10-12-ticket-whatsapp-reply-design.md`, sections 3 and 7) with the confirmed names and rules.
- Build the webhook check (signature, dedupe, parsing) and the outgoing sends from the confirmed rules only. Anything marked "No" or unanswered stays unbuilt until it is answered.
- If IN-14 is "No", ticket replies go out as text only, or staff send images another way. We will confirm the choice with the esystematics owner before building the image path.

## 6. The live check we need (after your answers)

1. Temporary capture: we register a webhook for `message.received` that points at a capture endpoint on our side, and send four test messages from a staff phone: a line of text; `REPLY-TK-0001` plus two body lines; an image alone; an image with the caption `REPLY-TK-0001` and a line of text. We save what arrives.
2. We remove the capture webhook (`DELETE /api/v1/webhooks/{id}`) and delete the captured files, which hold phone numbers and message text.
3. The real webhook is registered only after the capture is removed.

Please tell us whether this is allowed on the account in use, and whether a test number (OPS-5) should be used instead of the production number.

## 7. How to reply

Reply item by item using the IDs above: the answer, a short explanation, and any sample JSON (redacted). Keep secrets, tokens and real client phone numbers out of the reply. Use a test number in samples where you can.
