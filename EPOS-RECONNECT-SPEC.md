# EPOS spec: "Reconnect WhatsApp" alert and live QR code

**For:** the EPOS engineers. **Version:** 1 (2026-10-26). **WhatsMine site:** https://wa.esystematics.com
**Builds on:** the link you already have (manifest + Bearer key `wak_...`), and on `EPOS-ALERT-SPEC.md` (same style of manifest action).

## What happens

1. The shop's WhatsApp number drops (phone logged out, phone offline too long, session failed). WhatsMine sees it within seconds.
2. WhatsMine calls your manifest action `whatsapp.reconnect_alert` **once per drop**. You show a red alert to the people allowed to fix it.
3. Those people press **Scan QR**. Your page asks WhatsMine for the QR every 3 seconds and shows it live. They scan it with the shop phone (WhatsApp → Linked devices → Link a device).
4. When the answer says `status: "active"`, close the pop-up and clear the alert. WhatsMine sends nothing else.

WhatsMine alerts only when a **working** number drops. It does not alert on a first-time pairing, and not again for the QR refresh that follows a drop.

## Part 1. The alert you receive

Add this to `capabilities` and raise `manifest_version`. If it is missing, WhatsMine skips the alert quietly (nothing breaks).

```json
{
  "key": "whatsapp.reconnect_alert",
  "kind": "action",
  "group": "WhatsApp",
  "label": { "en": "WhatsApp needs to be reconnected" },
  "method": "POST",
  "path": "/api/whatsapp/v1/connection-alerts",
  "params": [
    { "name": "status",  "type": "text", "from": "fixed" },
    { "name": "title",   "type": "text", "from": "fixed", "optional": true },
    { "name": "message", "type": "text", "from": "fixed", "max_length": 500 }
  ],
  "outputs": []
}
```

Request from WhatsMine (same headers as `alert.push`: `Authorization: Bearer wak_...`, `Idempotency-Key`, `X-Request-Id`):
```
POST /api/whatsapp/v1/connection-alerts
{"status":"scan_qr","title":"Reconnect WhatsApp","message":"The WhatsApp number (+923001234567) is disconnected. Please scan the QR code again to receive and send messages."}
```
`status` is one of `scan_qr` (needs a scan), `failed`, `disconnected`. Show `message` as **plain text** (never HTML).

Answer HTTP 200 JSON: `{"found":true,"ok":true}`. The same `Idempotency-Key` again gets the same answer and creates no second alert. A non-2xx answer, a timeout over 5 s or non-JSON counts as not delivered; WhatsMine does not retry.

What EPOS builds: a red banner/pop-up and a bell badge for users with the permission below, with a **Scan QR** button, kept until the status turns `active` (or someone dismisses it). If one is already open, update it instead of stacking a second.

## Part 2. The live QR (you call WhatsMine)

Needs a WhatsMine API token with the scope **`whatsapp:connect`** (or a full-access token). Keep it on your **server only**; the browser never sees it. A WhatsMine admin creates it for the shop's workspace. Use a token separate from the one with `integrations:write`.

| Call | Use |
|---|---|
| `GET https://wa.esystematics.com/api/v1/whatsapp-web/qr` | Poll every 3 s while the pop-up is open |
| `POST https://wa.esystematics.com/api/v1/whatsapp-web/connect` | Press once if `status` is `disconnected`, `failed` or `pending` (starts the session so a QR appears) |

Both need `Authorization: Bearer <token>` and `Accept: application/json`. Answer of `/qr`:
```json
{ "qr": "data:image/png;base64,iVBOR...", "status": "scan_qr" }
```
- `status` `scan_qr`: show `qr` as `<img src="...">`. It changes about every 20 s: always show the latest one, never cache it.
- `status` `connecting`: show "Connecting…", keep polling.
- `status` `active`: connected. Stop polling, show "WhatsApp connected", close the alert.
- `status` `disconnected` / `failed` / `pending` (and `qr` null): call `/connect` once, then keep polling.
- Limits: `/qr` 60 per minute, `/connect` 10 per minute (429 when over). 403 = the token lacks `whatsapp:connect`. 401 = wrong or revoked token.

## Part 3. Only authorized people may see the QR (EPOS must enforce this)

Whoever sees the QR can link **their own phone as a device on the shop's WhatsApp**, read every chat and send as the shop. Treat it like a password.

1. Add a permission, for example `scan whatsapp qr`, and give it only to the owner/manager roles. Do not tie it to the existing `view whatsapp alerts`.
2. **Alert:** you may show "WhatsApp disconnected" to everyone who can see alerts, but the **Scan QR button and the QR** only to users with the permission.
3. **Never send the token to the browser.** Build two EPOS routes that check the permission, then call WhatsMine from your server and relay the JSON:
   - `GET /whatsapp/connection/qr` calls WhatsMine `/qr`
   - `POST /whatsapp/connection/connect` calls WhatsMine `/connect`

   A user without the permission gets 403 from your routes and WhatsMine is never called.
4. Require a fresh login: ask for the user's password again (or 2FA) before the QR first appears.
5. Do not store or log the QR. Send `Cache-Control: no-store` on the relay answer, show it only while the pop-up is open, and stop polling when it closes.
6. Write an audit line each time the QR is opened: who, when, from which IP.
7. Do not show it on shared or public screens; no screenshots in chat or email.
8. Keep the token in server config (env), not in the database or front-end code. If a user leaves, remove the permission. If the token leaks, ask WhatsMine to revoke it and issue a new one.

## Check before telling us it is ready

1. Add the manifest entry and press "Check now" on `https://wa.esystematics.com/app/integrations/epos`: "WhatsApp needs to be reconnected" appears, with no issue.
2. Log the shop phone out of Linked devices. Within about a minute your alert endpoint is called once (see "Recent calls" on the same page).
3. A user **without** the permission cannot open the QR (403 from your route; WhatsMine sees no call).
4. A user with the permission sees the QR, scans it, the pop-up changes to "connected" and the alert clears.
5. Calling `/qr` with a token that lacks `whatsapp:connect` gives 403.
