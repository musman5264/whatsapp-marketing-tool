# EPOS ⇄ WhatsMine integration spec

**For:** the EPOS project's engineers. **Version:** 1 (2026-10-24). **Live site:** https://wa.esystematics.com

WhatsMine is the WhatsApp marketing / inbox tool. The EPOS owns products, stock, services, business hours and orders.
This document has two parts:

- **Part A — what WhatsMine already gives the EPOS** (built; read from the WhatsMine code, not yet tested live with a real EPOS token).
- **Part B — what the EPOS must build** so a WhatsApp conversation can check availability, show products, answer services and business hours, and report delivery status. WhatsMine calls these endpoints from its *API Call* automation node.

```
Customer ──WhatsApp──▶ WhatsMine flow ──(API Call node, GET)──▶ EPOS   (Part B: products, hours, services, order status)
                            ▲                                    │
                            └──── replies built from EPOS JSON ──┘
EPOS ──(POST /api/v1/messages/send)──▶ WhatsMine ──▶ customer       (Part A: EPOS pushes a message, e.g. "order shipped")
EPOS ◀──(webhook message.sent / message.failed / message.received)── WhatsMine
```

---

## Part A — WhatsMine API for the EPOS

Base URL `https://wa.esystematics.com/api/v1` · JSON · header `Authorization: Bearer <token>` · `Accept: application/json`.

### A1. Token
Created by a WhatsMine user in the client area (API tokens); `POST /tokens` with `{name, abilities[], expires_at?}` also works but needs an existing token. Give the EPOS a token with only the abilities it needs: `contacts:write`, `messages:write`, `webhooks:write` (and `conversations:read` if it will read chats). Treat the token like a password.

### A2. Create or find the customer — `POST /contacts` (`contacts:write`)
Body `{"phone_e164":"03345266444","first_name":"Ali","last_name":"Khan","email":"…"}`. The number is cleaned to E.164 (`03345266444` → `+923345266444`; default country code 92).
`201` new contact, `200` existing (same number) — both return `data.id`. `422` if the number is unusable.
Use `data.id` as `contact_id` below. Do this once per customer and store the id in the EPOS.

### A3. Send a WhatsApp message — `POST /messages/send` (`messages:write`, 60 requests/minute)
| Field | Notes |
|---|---|
| `contact_id` | required, from A2 |
| `channel` | required: `whatsapp`, `sms` or `email` (WhatsApp is the tested path) |
| `type` | `text` (default), `template`, `image`, `video`, `audio`, `document` |
| `body` | the text |
| `media_url`, `caption`, `filename` | for media: a public HTTPS link |
| `template_name`, `template_vars[]` | for an approved Cloud-API template |
| `wait` | seconds to wait for the result, 0–30, default 15 |

Replies:
| Result | HTTP | Body |
|---|---|---|
| Delivered to WhatsApp within `wait` | 200 | `{"status":"sent","message_id":123,"provider_message_id":"…"}` |
| Failed | 422 | `{"status":"failed","message_id":123,"error":"…"}` |
| Still in the queue after `wait` (or `wait=0`) | 202 | `{"status":"queued","message_id":123}` |
| Customer not in this workspace | 404 | `{"error":"Contact not found."}` |
| Cloud-API number and the 24-hour chat window is closed (text) | 422 | `{"error":"…","window_closed":true}` (send a template instead) |

**Save `message_id`** against the order/notification in the EPOS. It is the key for delivery status.
Do not retry a 202 or a timeout blindly — look up the message first (A4), or the customer may get it twice.

### A4. Delivery status — `GET /messages/{message_id}` (`messages:write`)
```json
{"message_id":123,"conversation_id":45,"status":"sent","provider_message_id":"…","error":null,"sent_at":"2026-10-24T10:15:00+00:00"}
```
`status` values: `queued` → `sent` | `failed`. On a QR-linked number (WhatsApp Web) it later becomes `delivered` (two ticks) and `read` (blue ticks) when WhatsApp reports them. Not verified for the Cloud API path.
Only messages of your own workspace are found (`404` otherwise). Poll with backoff (for example 5 s, 30 s, 2 min, then stop); do not poll faster than once per 5 s per message.

### A5. Webhooks instead of polling — `POST /webhooks` (`webhooks:write`)
Body `{"url":"https://epos.example.com/hooks/whatsmine","events":["message.sent","message.failed","message.received"],"description":"EPOS"}`. The reply to the create call contains the endpoint's `secret` — store it then. With no `events` list the endpoint gets every event.

| Event | When | Notes |
|---|---|---|
| `message.sent` | an API send reached WhatsApp | only sends made through `POST /messages/send` |
| `message.failed` | an API send failed | `message.error` has the reason; no automatic retry |
| `message.received` | a customer wrote to you | includes `from` (their number) and `media` |
| `contact.created`, `contact.updated`, `campaign.completed`, `automation.run.completed` | other activity | available |

There is **no webhook for `delivered` / `read`** yet — use A4 polling for those.

Body for send results: `{"event":"message.sent","message":{"id":123,"conversation_id":45,"channel":"whatsapp","to":"+923001234567","status":"sent","provider_message_id":"…","error":null},"timestamp":"…"}`.

**Verify every webhook.** Header `X-Webhook-Signature: t=<unix time>,v1=<hex>` where `<hex>` = HMAC-SHA256 of the string `<t>.<raw request body>` with your endpoint secret. Recompute on the **raw bytes** you received, compare in constant time, and reject if `t` is older than 5 minutes. Answer `2xx` within a few seconds; a non-2xx is retried (up to 5 tries: 1 min, 5 min, 1 h, 1 day — the retry is currently under repair, so make your handler idempotent and do not depend on it). Use `message.id` to ignore repeats. Header `X-Webhook-Event` names the event.

### A6. Customer replies
`message.received` carries the customer's text in `message.body` and the number in `message.from`. The EPOS can use it to attach a reply to a ticket/order (put a reference such as `TK-0004` in the outgoing text and match it on the way back).

### A7. Not available today (do not build against these)
- Starting a WhatsMine flow from the EPOS **with data** (`POST /automations/{id}/trigger` only accepts `contact_id`; it cannot pass order fields). To notify about an order, call A3 with the text built in the EPOS.
- Webhooks for `delivered` / `read`.

---

## Part B — Endpoints the EPOS must provide

WhatsMine's *API Call* node calls these during a chat, using the customer's words as input, and puts the JSON reply into variables (`{{api.products.0.name}}`, loops, conditions).

### B0. Rules for every endpoint
1. **HTTPS, JSON, UTF-8.** Request header from WhatsMine: whatever the flow author sets, normally `Authorization: Bearer <EPOS key>`. Give WhatsMine a **read-only key**, restricted to these endpoints.
2. **Always answer `200` with JSON, even when nothing is found.** WhatsMine continues the flow on a 404/500 and would read an error page as data. Signal "not found" in the body (`"found": false`, `"count": 0`).
3. **Answer within 5 seconds** (WhatsMine gives up at 10 s and stops the chat flow for that customer).
4. **Keep it small:** at most 10 products per call (default 5), replies under 100 KB. A WhatsApp text message is limited to 4096 characters.
5. **Query values arrive as typed by the customer** (for example `category=Chicken sandwiches`, not encoded). Match case-insensitively, trim spaces, accept unknown values without error.
6. **Times:** ISO-8601 with offset (`2026-10-25T09:00:00+05:00`) and an explicit `timezone` (IANA name, for example `Asia/Karachi`).
7. **Money:** numbers, not strings; always send `currency`.
8. **Booleans are real JSON booleans** (`true`/`false`), not `"yes"`.
9. Do not put secrets or customer card/personal data in replies — they are shown in WhatsApp.
10. Field names below are the contract; extra fields are allowed and ignored. Do not rename or remove a field without telling WhatsMine (add a `v2` path instead).

### B1. Product availability by category — `GET /api/whatsapp/products`
Query: `category` (text), `q` (search text, optional), `branch` (optional code), `limit` (default 5, max 10).
```json
{
  "found": true,
  "category": "Sandwiches",
  "count": 2,
  "products": [
    {"sku":"SW-001","name":"Club sandwich","price":500,"currency":"PKR","in_stock":true,"qty_available":14,
     "min_order":1,"image_url":"https://epos.example.com/img/sw-001.jpg","url":"https://epos.example.com/p/sw-001",
     "short_description":"Chicken, egg, fries"},
    {"sku":"SW-002","name":"Zinger","price":650,"currency":"PKR","in_stock":false,"qty_available":0,
     "min_order":1,"image_url":"https://epos.example.com/img/sw-002.jpg","url":"https://epos.example.com/p/sw-002",
     "short_description":"Crispy chicken burger"}
  ]
}
```
Required per product: `sku`, `name`, `price`, `currency`, `in_stock`. Optional but wanted: `qty_available`, `min_order`, `image_url` (public, JPEG/PNG, under 5 MB — WhatsApp must be able to download it), `url`, `short_description`.
Nothing found: `{"found":false,"category":"Sandwiches","count":0,"products":[]}`.
Optional convenience: a ready-made `menu_text` string (one line per product) lets simple flows work without loops.

### B2. One product's stock — `GET /api/whatsapp/products/{sku}`
Query `branch` optional. Reply: `{"found":true,"sku":"SW-001","name":"Club sandwich","price":500,"currency":"PKR","in_stock":true,"qty_available":14,"available_from":null,"branch":"MAIN"}`. When out of stock: `in_stock:false` and, if known, `available_from` (ISO time of restock).

### B3. Services — `GET /api/whatsapp/services`
Query: `q` (optional), `limit`. Reply:
```json
{"found":true,"count":1,"services":[
  {"id":"SRV-10","name":"Home delivery","description":"Within 10 km","price":150,"currency":"PKR",
   "duration_minutes":null,"available":true,"available_days":["Mon","Tue","Wed","Thu","Fri","Sat"],"next_slot":"2026-10-25T11:00:00+05:00"}
]}
```
Used to answer "do you do X, what does it cost, when".

### B4. Business hours and shift — `GET /api/whatsapp/business-hours`
Query: `branch` optional. This is what lets the chat answer "reply within business days / shift" and decide *now* between "an agent will reply shortly" and "we reopen Monday 09:00".
```json
{
  "timezone": "Asia/Karachi",
  "now": "2026-10-24T22:40:00+05:00",
  "open_now": false,
  "today": {"date":"2026-10-24","open":"09:00","close":"21:00","closed_all_day":false},
  "next_open_at": "2026-10-25T09:00:00+05:00",
  "reply_within_minutes": 30,
  "shifts": [
    {"day":"Mon","open":"09:00","close":"21:00"},
    {"day":"Sun","closed":true}
  ],
  "holiday": null
}
```
`open_now` and `next_open_at` are computed **by the EPOS** (it knows holidays and shifts); WhatsMine does not calculate business hours itself. `holiday` is a name string when today is a holiday, else `null`. `reply_within_minutes` is the promise to show customers during open hours.

### B5. Order delivery status — `GET /api/whatsapp/orders/{order_ref}/delivery`
Query: `phone` (E.164, the customer's number) — **the EPOS must refuse to answer unless the phone matches the order** (return `found:false`), so one customer cannot read another's order.
```json
{"found":true,"order_ref":"ORD-1042","status":"out_for_delivery","status_text":"On the way",
 "updated_at":"2026-10-24T18:05:00+05:00","eta":"2026-10-24T19:00:00+05:00",
 "rider":{"name":"Imran","phone":"+923001112233"},"tracking_url":"https://epos.example.com/track/ORD-1042"}
```
`status` is one of: `received`, `preparing`, `ready`, `out_for_delivery`, `delivered`, `cancelled`, `failed`. `status_text` is the customer-facing wording (WhatsMine shows it as is).

### B6. Pushing updates from the EPOS (optional, uses Part A)
When an order changes state the EPOS may send the customer a message itself: A2 (once) → A3 with a text built in the EPOS → store `message_id` → confirm with A4 or the `message.sent` / `message.failed` webhook.

---

## How WhatsMine will use the replies (so you know why the fields matter)

| Flow step | Uses |
|---|---|
| "Which category?" → customer answers → API Call `GET …/products?category={{answer}}`, saved as `api` | B1 |
| Message: `{{#each api.products}}{{@n}}. {{name}} – Rs {{price}}{{/each}}` | B1 `products[].name`, `price` |
| Poll with one option per product: `{{api.products.*.name}}` | B1 `name` |
| Product photo with caption | B1 `image_url`, `name`, `price`, `min_order` |
| Condition `api.hours.open_now equals true` → "agent replies within {{api.reply_within_minutes}} min" or "we reopen {{api.next_open_at}}" | B4 |
| "Where is my order ORD-1042?" → status text | B5 `status_text`, `eta` |

The product cards with swipe/buttons seen in Alibaba chats are **not possible on a QR-linked WhatsApp number** (WAHA marks button messages deprecated); WhatsMine sends photo + caption messages and polls instead. The API shapes above work for either kind of number.

---

## Acceptance checklist for the EPOS team

Run each with `curl` and keep the output:
1. B1 `?category=sandwiches` (lower case), `?category=Sandwiches%20` (trailing space), an unknown category → all `200`, last one `found:false`.
2. B1 with a product that is out of stock → `in_stock:false`, still listed.
3. B4 at an open time and a closed time → `open_now` true/false, `next_open_at` set when closed; on a holiday `holiday` set.
4. B5 with the right and a wrong `phone` → second returns `found:false`, no order data.
5. Every endpoint answers in under 5 seconds with the read-only key; wrong key → `401` with JSON (WhatsMine will treat any non-2xx as "no data").
6. From the EPOS: A2 → A3 → A4 against `https://wa.esystematics.com/api/v1` with a test number; confirm `message_id` is stored and the status ends `sent`.
7. Register A5 and verify the signature on a real `message.sent` delivery (recompute HMAC over the raw body).

## Open points to agree with WhatsMine
- Which key/header the EPOS wants WhatsMine to send (default `Authorization: Bearer`).
- Branch handling (`branch` code per WhatsApp number?).
- Whether `delivered` / `read` webhooks are needed (not built).
- Whether the EPOS needs to start a flow with data (`context` on the trigger endpoint, not built).
