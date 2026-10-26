# EPOS spec: show a WhatsApp message as an alert in EPOS

**For:** the EPOS engineers. **Version:** 1 (2026-10-26). **WhatsMine site:** https://wa.esystematics.com
**Builds on:** the manifest link you already have (`GET /api/whatsapp/v1/manifest`, Bearer key `wak_...`). Nothing new to link.

## What the customer and staff see

1. A WhatsApp customer asks for an order. A WhatsMine flow asks them to answer in 6 lines:
   ```
   👤 *Name:* Ali Khan
   📍 *Delivery Address:* House 4, Street 9, F-8
   📞 *Phone Number:* 0300 1234567
   🧁 *Items Requested:* Chocolate cake
   🔢 *Quantity:* 2
   ⏰ *Preferred Delivery/Pick-up Time:* Friday 6pm
   ```
2. The flow sends that text to EPOS as one **alert** (a new action question in your manifest).
3. EPOS shows it to staff at once (bell, pop-up, optional sound) and keeps it in a list until someone handles it.
4. WhatsMine tells the customer "received" only if EPOS answers `ok: true`.

WhatsMine does **not** read the 6 lines. It sends the customer's text as it arrived. If EPOS wants fields (name, address, items), EPOS parses the text itself, or staff read it.

## What you build (checklist)

| # | Piece | Notes |
|---|---|---|
| 1 | The endpoint `POST /api/whatsapp/v1/alerts` | Section 2 |
| 2 | One new entry in the manifest | Section 1 |
| 3 | A table that stores alerts | Section 3 |
| 4 | A live alert for staff and a list page | Section 4 |
| 5 | A setting to switch alerts off | Section 5 |
| 6 | Safety rules | Section 6 |

## 1. Manifest entry (add to `capabilities`)

Raise `manifest_version` when you add it. Do not send the real path from anywhere else: WhatsMine calls exactly the `path` published here, on your own host only.

```json
{
  "key": "alert.push",
  "kind": "action",
  "group": "Orders",
  "label": { "en": "Send an alert to the shop", "ur": "دکان کو الرٹ بھیجیں" },
  "description": { "en": "Shows the customer's message as an alert in EPOS." },
  "method": "POST",
  "path": "/api/whatsapp/v1/alerts",
  "params": [
    { "name": "phone",   "type": "phone", "from": "sender_phone" },
    { "name": "message", "type": "text",  "from": "chat_answer", "max_length": 1500 },
    { "name": "title",   "type": "text",  "from": "fixed", "optional": true }
  ],
  "outputs": [ { "path": "alert_id", "type": "text" } ]
}
```
Rules WhatsMine enforces on the manifest (an entry that breaks them is skipped and shown as an issue on the admin page):
- `kind: action` must use `method: POST`; `path` starts with a single `/`.
- `max_length` is a whole number, 1 or more. **WhatsMine never sends more than 2000 characters**, whatever you declare. Without `max_length` a text value is limited to 200.
- `phone` is filled by WhatsMine with the **verified** WhatsApp number of the chat. The flow author cannot change it.

## 2. The endpoint

Request from WhatsMine:
```
POST /api/whatsapp/v1/alerts
Authorization: Bearer wak_...        (your key)
Idempotency-Key: <40 hex characters>
X-Request-Id: <id>
Content-Type: application/json

{"phone":"+923001234567","message":"👤 *Name:* Ali Khan\n📍 ...","title":"New order request"}
```
- `message` has line breaks (`\n`) and emoji. Store and show them as they are. **Use utf8mb4** (or your database's full Unicode type), or the emoji turn into `????`.
- `title` is optional: if it is missing, use "WhatsApp message".

Answer **HTTP 200 with JSON for every business result**, as for your other questions:

| Situation | Body |
|---|---|
| Saved | `{"found":true,"ok":true,"alert_id":"AL-1042"}` |
| Same `Idempotency-Key` again | the **same** body as the first time, and do **not** create a second alert |
| Alerts are switched off in EPOS | `{"found":true,"ok":false,"reason":"disabled"}` |
| Too many from this phone or in total | `{"found":true,"ok":false,"reason":"rate_limited"}` |
| Text empty or too long | `{"found":true,"ok":false,"reason":"invalid"}` |

A non-2xx answer, a timeout over 5 seconds, or an answer that is not JSON counts as "not delivered": the flow tells the customer "not available right now". WhatsMine **never retries this call automatically**. A new customer confirmation sends a new `Idempotency-Key`.
Keep the answer under 100 KB. Send the header `X-Epos-Manifest-Version` with the current version so WhatsMine refreshes the list when it changes.

## 3. What to store (suggested table `whatsapp_alerts`)

`id`, `alert_ref` (the `AL-1042` you return), `received_at`, `phone` (E.164), `customer_id` (nullable: link when the phone matches a customer), `title`, `message` (long text, raw), `status` (`new` / `seen` / `handled`), `handled_by`, `handled_at`, `idempotency_key` (**unique**, used for the repeat rule above), `source` (`whatsapp`).

## 4. What staff see

- **Live alert:** when a row is created, show a pop-up and a badge on the bell for every user who has the permission (`view whatsapp alerts`). Use your existing real-time channel; if you have none, poll every 15 seconds.
- **List page "WhatsApp alerts":** newest first; shows time, phone, customer name if matched, and the message with its line breaks kept (`white-space: pre-wrap`). Buttons: Mark seen, Mark handled, and if you have it, "Create order" with the customer pre-selected.
- Unhandled alerts stay on the bell badge until handled.

## 5. Settings

One switch "Accept WhatsApp alerts" (default on) and a per-hour limit. Suggested limits: 20 alerts per phone per hour, 300 in total per hour. Over the limit gives `rate_limited`.

## 6. Safety (do not skip)

1. **The message is written by a stranger.** Show it as plain text. Never as HTML, never in an unescaped template. A message such as `<script>...</script>` must appear as that text.
2. Trust only `phone` (WhatsMine fills it from WhatsApp). Do not trust a number written inside `message`.
3. Cut anything over 2000 characters and remove control characters except line breaks.
4. Never put customer data or the key in your logs.
5. Do not auto-create orders from the text without a staff click.

## 7. Check it before telling us it is ready

1. Add the manifest entry, then press "Check now" on `https://wa.esystematics.com/app/integrations/epos`: the question "Send an alert to the shop" must appear (and no issue for `alert.push`).
2. Call the endpoint with `curl` and the 6-line text above (with `\n`): `ok:true`, the alert appears for staff within 15 seconds, line breaks and emoji intact.
3. Repeat the **same** call with the **same** `Idempotency-Key`: same answer, still one alert.
4. Send `message` as `<b>x</b><script>alert(1)</script>`: it shows as text.
5. Switch alerts off: `reason: "disabled"`.
6. Send 21 alerts for one phone inside an hour: the 21st gives `rate_limited`.
7. Wrong key: `401` with JSON.

## 8. Later, optional

When staff press "Mark handled" (or "Create order"), EPOS can message the customer with `POST /api/v1/messages/send` from `EPOS-INTEGRATION-SPEC.md` Part A3 ("Your order is confirmed"). Not needed for the first version.

## What WhatsMine already does (nothing for you to build)

- Reads your manifest and offers "Send an alert to the shop" in the E-POS step of the flow builder.
- Fills `phone` itself, sends `message` as the customer's text, and calls you once.
- Takes the "Sent" exit only when `ok` is `true`. Otherwise it takes the "Not sent" exit, and the flow can use `{{epos.reason}}`.
- Records every call on the admin page under "Recent calls" (with a one-hour debug switch).
