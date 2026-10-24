# Reply to the EPOS team: requirements v1 (2026-10-24)

**For:** the EPOS developers · **From:** the WhatsMine / E-Whatsapp Automation Tool team
Everything below was read from the tool's code on 2026-10-24. Nothing here is tested against a live EPOS yet.

## Written answers (deliverable 1)

**R6.1 – PDF upload on `/messages/send`: supported.**
- Send `multipart/form-data` with the file in a field named **`attachment`** (not `file` or `document`), `contact_id`, `channel=whatsapp`, `type=document`, optional `filename` and `caption`.
- PDF is accepted; the limit is **20 MB**.
- Cloud-API numbers: the file is uploaded to Meta first, then sent. QR-linked numbers: sent as a link to the stored file.
- The message appears in the contact's own conversation as an outgoing message sent via the API.
- Cloud-API numbers outside the 24-hour window: `422 {"error":"…","window_closed":true}`. Map it to `reason:"window_closed"`. We have not yet sent a PDF through the Cloud path on the live site, so treat that path as unverified until the staging run.

**R7.1 – Signature.** We send **`X-Webhook-Signature: t=<unix>,v1=<hex>`** where `<hex>` = HMAC-SHA256 of `"<t>.<raw body>"` with the endpoint secret. We do **not** send `X-Signature-256`, so a receiver that only checks that header will reject every delivery with 401. Please verify the `t=,v1=` form and reject old `t` values.

**R7.2 – Events.** `message.failed` is a valid event in the current code (list: `contact.created`, `contact.updated`, `message.received`, `message.sent`, `message.failed`, `campaign.completed`, `automation.run.completed`). The 422 you saw came from an instance that did not have this code; make sure the staging instance is on the current build. `delivered` and `read` are **not** subscribable webhook events yet; they are visible by polling `GET /messages/{id}` (status becomes `delivered` / `read` on QR numbers).

## Open questions
1. **Cloud-API numbers only?** No: the same flow node works on Cloud-API and QR-linked numbers. The 24-hour window only applies to Cloud-API numbers (see `window_closed` above). We have not yet run a QR inbound flow end to end for this node.
2. **`{{#each}}` and `*`.** `{{#each epos.orders}}{{ref}} – {{total}}\n{{/each}}` works over a list. `*` works only inside a single token, e.g. `{{epos.orders.*.ref}}` (joined with ", "), not in `#each`. Write `summary_template` with `{{#each orders}}…{{/each}}` and plain `{{field}}`; the tool adds the variable-name prefix when it inserts the text.
3. **`reply_text`.** Yes, optional. If your answer includes `reply_text`, the flow can send `{{epos.reply_text}}` as is. No special support is needed on your side beyond adding the field.
4. **Several EPOS businesses per workspace.** Yes. Each is one integration; the node stores which one, and asks "which business?" only when there is more than one.

## Planned delivery (deliverable 4)
| Release | Contents |
|---|---|
| 1 | Integrations API (R1), manifest fetch/cache/health (R2), safe outbound client (SSRF guard) |
| 2 | Flow executor and the E-POS node, ledger action exits (R3, R6.3/6.4) |
| 3 | Builder UI, status page, English + Urdu (R4) |
| 4 | Inbox panel (R5) |
Deliverables 2 and 3 (staging with an `integrations:write` token and a workspace to link) follow release 1 once it is deployed; dates depend on the deploy.

## Things we will hold you to
- All responses HTTP 200 JSON with `found`; `401` only for auth failure; `X-Epos-Manifest-Version` on every response.
- `sender_phone` is always the WhatsApp number of the chat contact in E.164; the tool never lets a flow author set it.
- Ping at `GET /api/whatsapp/v1/ping` (cheap, authenticated with the same key).
