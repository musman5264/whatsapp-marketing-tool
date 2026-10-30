# EPOS integration: link, manifest, safe client, flow node (slices 1-2)

A remote system (EPOS) links itself to a workspace with one API call. The tool then keeps a cached copy of the manifest (the list of questions EPOS can answer). Built so far: the link API, manifest cache, health tracking, the guarded outbound client and the `epos` flow node (executor). **Not built yet:** the builder UI for the node and the status page (T-0049), the inbox panel (T-0050). Until T-0049 the node can only be placed through saved flow data, not the builder. Requirements: `TEMP/intake-2026-10-24-1408.md` (R1-R9); design: `tasks/plans/PLAN-003-epos-integration/PLAN.md`.
Code read and tests run on 2026-10-24. Nothing here has run against a real EPOS or on the live site.

## Where the code is
| Piece | File |
|---|---|
| Table `external_integrations` | `app/Modules/Integrations/database/migrations/2026_10_24_000001_create_external_integrations_table.php` (run `php artisan migrate --force` after deploy) |
| Model (key is an `encrypted` cast and `$hidden`) | `app/Modules/Integrations/Models/ExternalIntegration.php` |
| Guarded outbound HTTP | `app/Modules/Integrations/Services/External/SafeHttp.php` (+ `HostResolver`, `ExternalCallException`) |
| Manifest fetch, validate, cache | `.../External/ManifestFetcher.php` |
| Ping, failure count, backoff | `.../External/IntegrationHealth.php` |
| API | `app/Http/Controllers/Api/V1/IntegrationApiController.php`, routes in `routes/api.php`, ability `integrations:write` in `app/Support/ApiAbilities.php` |
| Dev switch | `config/external_integrations.php` (`EXTERNAL_INTEGRATIONS_ALLOW_PRIVATE_HOSTS`, default false) |
| Tests | `tests/Feature/Integrations/SafeHttpTest.php`, `tests/Feature/Api/V1/IntegrationApiTest.php` |

## API (`/api/v1/integrations`, Bearer token with `integrations:write`)
| Call | Result |
|---|---|
| `POST /` `{type:"epos", name, base_url, manifest_url, key, manifest_version?}` | Fetches the manifest with that key **before saving**. `201` new / `200` same `type`+`base_url` in the workspace (trailing `/` ignored) updated. Body `{id, status, capabilities, manifest_version}`. Fetch failure: `422 {"error":"manifest_unreachable","detail":...}` and nothing is saved |
| `GET /`, `GET /{id}` | Same fields plus `type, name, base_url, manifest_url, manifest_fetched_at, issues`. Never the key |
| `PUT /{id}` | `name, base_url, manifest_url, key` (key rotation). Re-fetches with the new values first; on failure `422` and the old values stay |
| `DELETE /{id}` | `204`; the row (key and cached manifest) is deleted |
| `POST /{id}/check` | Re-reads the manifest and pings `{base_url}/api/whatsapp/v1/ping`. `200 {ok, ping, status, capabilities, issues, error?}` |
Other workspace's id: `404`. No ability: `403`. Validation: field-level `422`.
`manifest_version` sent by EPOS is accepted but the stored value comes from the manifest it actually serves.

## Safe client rules (`SafeHttp`)
- https only (http only with the dev switch). DNS is resolved every call; **every** address (A and AAAA) must be public. Refused: 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16 (metadata), `::1`, fc00::/7, link-local, IPv4-mapped IPv6 of those. Reason `unsafe_target`.
- The connection is pinned to the checked address (`CURLOPT_RESOLVE`) so DNS cannot change between the check and the call. Pinning is not exercised by the tests (they use `Http::fake`): unverified until a real call.
- No redirects, TLS verification on, 5 s total, headers `Authorization: Bearer`, `Accept: application/json`, `X-Request-Id`.
- Response over the limit (100 KB, manifest 200 KB) is refused (`too_large`). The body is read before the check (limit of the shortcut noted in code).
- Any connection error becomes `ExternalCallException('unreachable')` with a fixed message, so keys and URLs never leak through exception text.

## Manifest rules (`ManifestFetcher`)
- Needs `manifest_version` (non-empty string) and a `capabilities` list, otherwise the whole fetch fails (`invalid_manifest`).
- Each capability is checked on its own; an invalid one is skipped and listed in `manifest_issues` (`{key, reason}`). Checks: key format, `kind` query/action with method GET/POST, `path` a single-slash path (a full URL is refused so the manifest cannot steer calls to another host), `label.en`, params (`type`, `from`, choice needs choices), outputs (`path`, `type`).
- Unknown fields are dropped when stored. Duplicate keys: the second is skipped.
- `refreshIfStale()` re-reads when the cache is older than 10 minutes and the integration is not backing off. It never throws.

## Health
`IntegrationHealth`: each failed manifest fetch or ping adds 1 to `consecutive_failures`. At 3 the status becomes `unreachable` and `next_retry_at` is set; later failures push the retry out 1 min, 5 min, 30 min. The next success resets it to `connected`. `ping()` runs at most once a minute unless forced (`/check` forces it).
The executor reads `unreachable` (see below); the banner is the builder (T-0049).

## Logging
`external.manifest` lines carry integration id, status and milliseconds. Never the key, the manifest body or a phone number (test `test_key_never_reaches_the_logs`).

## How to test
```
DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit tests/Feature/Integrations tests/Feature/Api/V1/IntegrationApiTest.php
```
Result 2026-10-24 on private DB `wm3_test_t0047`: `OK (31 tests, 98 assertions)`; with `tests/Feature/ApiTokenTest.php tests/Feature/Api tests/Feature/WebhookEndpointTest.php` `OK (128 tests, 359 assertions)`. phpstan level 6 on the new files: no errors.

## Known gaps
- No scheduler or job calls `ping()`/`refreshIfStale()` yet: an unreachable integration recovers only when a chat lets the one probe call through after `next_retry_at`, or when EPOS calls `/check`. The builder and panel (T-0049/T-0050) will call `refreshIfStale()`.
- The `integrations:write` scope is not in the token-screen docs list in `resources/js/Pages/client/Api/Docs.jsx` (frontend build avoided in this slice); it is in `ApiAbilities::all()` and `labels()`.

## The `epos` flow node (slice 2)
Node type `epos` in `AutomationEngine::executeEpos()`; the work is in `.../External/EposExecutor.php`. Tests: `tests/Feature/Automation/EposNodeTest.php`.

Saved node data: `integration_id` (optional when the workspace has exactly one EPOS link; an id that is not in this workspace, e.g. from an imported flow, is ignored and the only link is used, as the builder shows. With two or more links and an unknown id the node says "E-POS is not linked."), `capability` (manifest key), `params` (`{name: value}`, `{{tokens}}` allowed), `result_var` (default `epos`). No URL, header or key exists on the node.

What happens when it runs:
1. Capability not in the cached manifest, or no link: "no data" (run log says "no longer available" / "not linked"), no call.
2. Integration `unreachable` with `next_retry_at` still in the future: "no data" at once, no call. After that time one call is let through as the probe.
3. **Identity:** any manifest param whose type is `phone`, whose source is `sender_phone`, or that is named `phone` is filled with the chat contact's `phone_e164`, only if it is `+` and 7-15 digits. Whatever the author or the chat put there is ignored. No verified number: "no data" with `{{epos.reason}}` = `no_phone`, no call. (WAHA groups are never ingested, only 1:1 chats, so there is no group case.)
4. Other params: author value (tokens rendered) or the manifest `default`, checked by type (`number`; `date` as YYYY-MM-DD; `choice` must be one of the manifest values; `text` up to 200 chars, or up to the param's `max_length` when the manifest declares one, never more than 2000 (`EposExecutor::MAX_TEXT`); a bad `max_length` skips the whole capability in `ManifestFetcher`). Values are trimmed; line breaks inside are kept. The builder shows a resizable text box instead of a one-line input for a text param with `max_length` over 200 (`EposNode.jsx`). A missing required value (`optional` and `required_if` honoured), or a value that fails its check (including text over its `max_length`), is "no data" with `{{epos.reason}}` = `invalid` and no call, so a flow can answer "too long or empty" before anything is sent. Params the manifest does not list are never sent.
5. Rate limit 60 calls a minute per integration (`epos-calls:{id}`); over it: "no data".
6. Call through `SafeHttp`: query = `GET path?params`, action = `POST` JSON with an `Idempotency-Key` (sha1 of run id, node id, how many times this run has already passed this node, capability: a flow that asks again and comes back to the same step sends a new key, a queue retry of the same pass sends the same one). Neither is retried (the spec allows one retry for queries; not done). 5 s, 100 KB.
7. Connection error, non-2xx or non-JSON: "no data" and one failure on the integration health (3 in a row = `unreachable`). An `unsafe_target` is not counted.
8. A response header `X-Epos-Manifest-Version` different from the cached one triggers a manifest refresh.
9. Queries with no phone param are cached 60 s per integration, capability and params. Anything carrying the phone is never cached.

Exits (builder handle ids): `true` = found (for an action: `ok` was true), `false` = not found, no data, or action not done.
Variables written: `{{epos}}` (the JSON answer, so `{{epos.name}}`, `{{#each epos.orders}}` and conditions work as for the API Call node), `{{epos_status}}` (`found` / `not_found` / `no_data`), `{{epos_found}}` (`true` / `false`). For no data the body is `{"found":false,"ok":false,"reason":"no_data"}`, so `{{epos.reason}}` is always set. For an action EPOS's own reason (`rate_limited`, `window_closed`, ...) is kept.
The run never fails because of EPOS. Log line `external.call` has integration id, capability, status, ms, found; no phone, key or body.

Checked 2026-10-24: `OK (134 tests, 349 assertions)` for `tests/Feature/Automation`, `ProductionHardening/AutomationEngineTest`, `Integrations`, `IntegrationApiTest`; phpstan level 6 clean on `app/Modules/Integrations/Services/External`. Real checks on this machine: a server that sleeps 6 s gives `unreachable` after 5.2 s; `CURLOPT_RESOLVE` pinning connects to the pinned address and honours a wrong pin. HTTPS to a public host fails here only because this PHP has no CA bundle (a plain request fails the same way); not checked on the server image.

## Builder node and status page (slice 3)
Code read, tests run and screens looked at in a browser on 2026-10-24 (local, private test database, a seeded link; no real EPOS).

| Piece | File |
|---|---|
| Builder data endpoint `GET /app/automations/epos` (`client.automations.epos`) | `app/Modules/Automation/Http/Controllers/AutomationController.php` `epos()` |
| E-POS node UI: context, panel fields, exit labels | `resources/js/Pages/Automation/EposNode.jsx`, wired in `Builder.jsx` (`NODE_DEFS.epos`, `BaseNode`, `FIELD_COMPONENTS`, provider, palette filter, banner) |
| Helpers (`pickLabel`, `prefixTemplate`, `isCustomerPhoneParam`, `outputVariables`) | `resources/js/lib/epos.js`, tests `resources/js/__tests__/epos.test.js` |
| Admin status page `GET /app/integrations/epos`, check `POST /app/integrations/epos/{id}/check` | `app/Http/Controllers/Client/EposIntegrationController.php`, `resources/js/Pages/client/Integrations/Epos.jsx` |
| Backend tests | `tests/Feature/Client/EposIntegrationScreensTest.php` (the page test needs `public/build` to contain the page) |
| Strings | `resources/js/locales/en.json` (`automation.epos_*`, `epos_status.*`), `ur.json` (only these keys) |

How it behaves:
- The builder loads the E-POS list after it opens (not with the page), so a slow EPOS never delays the builder. The endpoint re-reads a manifest older than 10 minutes. It returns capabilities with params and outputs, never the key or the host. `status` and `issues` are only sent to workspace administrators (`isClientAdministrator()`); everyone else gets none, so only administrators see the unreachable banner.
- **Palette:** "E-POS" (Integrations group) appears only while the workspace has a link. A node already on the canvas stays visible without one and shows the badge "E-POS not linked".
- **Node panel:** business picker (only if more than one link), question picker grouped by `group` (label in the user's language, English fallback), then one control per manifest param: customer phone params (type `phone`, source `sender_phone`, or named `phone`) are a disabled box "Customer's WhatsApp number"; a `choice` param is a dropdown of the manifest choices plus earlier answers (`{{var}}`); other types are an input plus an "answer from the chat" picker. The result variable (default `epos`), the answer variables (`{{epos.path}}` with type, plus `{{epos.reason}}` for actions), the exits hint, and a read-only copy of `summary_template` (with the variable name put in front of top-level fields and each-lists) are shown. No URL, header or key field exists on this node.
- **Exits:** handles `true` / `false`, labelled Found / No data, or Sent / Not sent for an action.
- **Removed question:** a node whose question is no longer in the manifest shows "Question removed" on the canvas and a warning in the panel; at run time it takes the "No data" exit (slice 2).
- **Status page** (administrators; staff get 403): per link the address host, list version, last read, question count, next retry and failed attempts, the skipped-question list with reasons, a "Check now" button, and the red banner while unreachable. Nothing on the page can change or remove the link: linking, key rotation and unlinking happen from EPOS through the API.
- **RTL:** manifest labels, descriptions and the reply text use `dir="auto"` so Urdu text lays out right to left. The page and builder chrome follow the document direction (a locale's `is_rtl` flag).
- **Urdu:** `ur.json` holds only the new strings. The language is not offered until an admin adds a locale with code `ur` (and RTL on) in the admin Locales screen; other keys then fall back to English.

Checked: `OK (258 tests, 735 assertions)` for `tests/Feature/{Client,Automation,Integrations,Api}`, `ApiTokenTest`, `AutomationEngineTest`; vitest `21 passed` (this also needed `resources/js/__tests__/setup.js` renamed to `setup.jsx` because it contains JSX and the whole vitest suite could not start before). eslint clean on the new files (the existing `Builder.jsx` has 1 error and 3 warnings in code I did not touch). Looked at in a browser: status page at 360px and 1920px in light and dark; builder at 1920px in light (node, exits, removed badge, panel, banner). **Not looked at:** the builder at 360px or in dark mode (the builder canvas uses fixed inline light colours and was not made responsive before this change), an Urdu locale on screen, an RTL builder.

Sidebar: administrators see "E-POS" in the account group (after Audit Log), `resources/js/Layouts/useClientNav.jsx`, key `nav.epos`; staff do not get the link. It is shown to every administrator, linked or not (an unlinked workspace sees the empty-state instructions). Known gap: the builder does not warn when an E-POS node has an unconnected exit.

## Where the E-POS logs are (recent calls table and debug switch)
Code written, tested and looked at in a browser on 2026-10-24 (local, private test database). Not run on the live site.

Three places, each with a different level of detail:
| Where | What it holds |
|---|---|
| Flow "View Runs" page (Automations → the flow) | One line per E-POS step: its message ("E-POS answered.", "E-POS call failed: unreachable.", "no longer available"). Shown from `automation_run_logs`; no request or answer. |
| Server file `storage/logs/laravel.log` | `external.call` and `external.manifest` lines: integration id, question key, status, milliseconds, found. No phone, key or body. |
| **Admin page `/app/integrations/epos` → "Recent calls"** (table `external_call_logs`) | The last 50 calls per link: time, question, result (Found / Not found / No data), HTTP status or failure reason, milliseconds, message. Manifest reads appear as "Question list". Admins only. |

**Debug switch** (same page, per link, off by default): "Switch on for 1 hour" sets `external_integrations.debug_until` to now + 1 hour; it can be switched off at any time and switches itself off. While on, each call row also keeps `request` (method, path and params, e.g. `GET /api/whatsapp/v1/profile?phone=%2B9233•••6444`; for an action also the JSON params) and `response` (HTTP status and the first 2 KB of the body, or `ERROR <reason>`), shown under "Details". The key is never recorded. Phone numbers are masked in both (`+923345266444` and `03345266444` become `+9233•••6444` / `0334•••6444`) before the text is cut, so a cut can never leave a half number. The body can still contain names and balances: use it only while testing.

Retention (`ExternalCallLog::prune()`, run on about 1 write in 50): request/response are wiped after 1 day; rows are deleted after 30 days. A link's rows are deleted with the link.

Where: `app/Modules/Integrations/Models/ExternalCallLog.php` (`record`, `prune`, `payload`, `mask`), migration `2026_10_24_000002_create_external_call_logs_and_debug_switch.php` (run `php artisan migrate --force` after deploy), recording in `EposExecutor::execute()` and `ManifestFetcher::refresh()`, switch route `POST /app/integrations/epos/{id}/debug` (`client.integrations.epos.debug`), page `resources/js/Pages/client/Integrations/Epos.jsx`. A failure to write a log row is caught and never breaks a chat.
Tests: `tests/Feature/Integrations/ExternalCallLogTest.php`, `EposNodeTest.php` (recording, masking, key never present, expired switch), `EposIntegrationScreensTest.php` (newest first, 50 cap, admin-only switch, other workspace 404): `OK (46 tests, 154 assertions)`. Only these files were run for this change.
Not checked: dark mode and 1920px of the new table; the Urdu strings on screen.

## Sending a WhatsApp message to EPOS as an alert (T-0058)
No new node or endpoint in this tool. EPOS publishes an action question (example `alert.push`, `POST /api/whatsapp/v1/alerts`, params `phone` from `sender_phone`, `message` from `chat_answer` with `max_length`, optional `title`), the E-POS node lists it, and the flow maps `message` to the customer's answer (`{{answer}}`). What EPOS builds is in `EPOS-ALERT-SPEC.md` (for the EPOS developers). The tool sends the customer's text unparsed; "received" must be said only from the node's Found exit (`ok: true`). Tests: `EposNodeTest` (long text, 200 default, 2000 cap, a new key per pass), `IntegrationApiTest` (`max_length` kept / bad value skipped). Not run against a real EPOS.

### Example flow for the order alert (T-0061)
`app/logic/examples/epos-order-alert.flow.json`: import it with the builder's Import / Export button (it becomes a draft). Trigger keyword `order request` (change it, or call the flow from a menu with Run Sub-flow), Ask Question for the 6 lines, E-POS step `alert.push` (`message` = `{{answer}}`, `title` = "New order request"), then a reply for each EPOS result using the sample texts of the EPOS team's guide: saved (`ok: true`, with `{{epos.alert_id}}`), `disabled`, `rate_limited`, `invalid`, and one reply for everything else (`no_phone`, `not_available`, `unknown_capability`, any non-2xx or timeout). Each reply is English followed by Urdu in one message because the tool does not know the customer's language; the Urdu is the guide's sample and needs a native check. The E-POS step has no link chosen, so it uses the workspace's only E-POS. Test: `tests/Feature/Automation/EposOrderAlertFlowTest.php` (runs this file against each answer), `AutomationImportTest` (the file imports). Not run against a real EPOS.

### Marking a question as an alert (T-0062)
Ask Question has a switch "Send this answer to E-POS as an alert" (saved as `alert: true` on the node; the engine ignores it). It only guides the author: (1) the node shows an "Alert → E-POS" badge on the canvas; (2) in an E-POS step, the "answer from the chat" list shows answers marked as alerts first with " — alert"; (3) the flow check warns (`alert_not_sent`) when no E-POS step has the answer's `{{variable}}` in one of its parameters. The answer is sent only by an E-POS step, as before. Code: `Builder.jsx` (`AskQuestionFields`, `BaseNode`, flow check, `alertVars` in the E-POS context) and `EposNode.jsx` (`Param`); strings `automation.field_alert_answer`, `alert_answer_hint`, `alert_badge`, `alert_answer_option` in `en.json`. Not looked at in a browser. The bundled example flow has it switched on.

### E-POS answer fields in the Condition's field list (T-0063)
A Condition's "Check field" list now has a group "E-POS answers" with the fields of each E-POS step in the flow: the capability's outputs (`{{epos.name}}` …), `{{epos.reason}}` (also for questions, where it is `no_data`, `invalid` or `no_phone` for problems before the call) and `{{epos_status}}`. They are the same paths the engine already read (`context.epos.reason`); before, only the whole `{{epos}}` was offered and a typed path showed as an extra last entry. `Builder.jsx` (`eposFields`, `ConditionFields`), string `automation.epos_answer_fields`. Not looked at in a browser.

### Reconnect-WhatsApp alert and live QR in EPOS (T-0064)
Code written and feature tested on 2026-10-26 (private test DB). **Not run against a real EPOS, a real WAHA drop or the live site: unverified.** Developer guide for EPOS: `EPOS-RECONNECT-SPEC.md`.
- **Alert:** `WahaEventProcessor::handleSessionStatus()` dispatches `NotifyEposReconnectJob` (queue `whatsapp`) when a session whose stored status was `active` becomes `scan_qr`, `failed` or `disconnected`. Not on a first pairing or on the QR refresh after the drop (the stored status is no longer `active`). The job runs `EposExecutor::execute()` with no phone for the manifest action `whatsapp.reconnect_alert` (params `status`, `title`, `message`) on each EPOS link of the workspace, so rate limit, health, call log and the "no longer available" skip all apply. EPOS not publishing the action means nothing is sent. Not retried. The inbox Disconnect button can also raise the alert if WAHA then sends `STOPPED` while the stored status was `active`.
- **Live QR:** `GET /api/v1/whatsapp-web/qr` and `POST /api/v1/whatsapp-web/connect`, ability `whatsapp:connect` (`ApiAbilities::WHATSAPP_CONNECT`), `app/Http/Controllers/Api/V1/WhatsappWebConnectApiController.php` (extends the inbox `WhatsappWebSessionController`, same code; workspace = the token owner's `workspace_id`). `/qr` returns `{qr: data-URI or null, status}`. Throttle 60/min and 10/min.
- Who may see the QR is enforced by EPOS (permission, server-side relay), rules in the spec Part 3. WhatsMine only checks the token scope.
- Tests: `tests/Feature/WhatsappWeb/EposReconnectAlertTest.php` (4 tests: drop-only rule, alert body, no action published, ability check).
- Gap: the token screen (`Tokens.jsx`) does not list `whatsapp:connect` (same as `integrations:write`); until it does, such a token is made by an admin outside the screen, or EPOS uses a full-access token.

### Complaint or order: `kind` on alert.push (T-0065)
Code written and tested on 2026-10-29 (private test DB). **Not run against a real EPOS or on the live site: unverified.** EPOS side of the contract: `TOOL-complaint-alert.md`.
- **Manifest:** a param may carry `enum` (a non-empty list of non-empty text values). `ManifestFetcher` keeps it; a malformed enum skips the whole capability (listed in `manifest_issues`). `complaints_enabled` is not stored (nothing in the tool uses it).
- **Run time:** `EposExecutor::validValue()` refuses a value outside the enum: no call, `{{epos.reason}}` = `invalid`, same as a bad `choice`. A blank optional `kind` is left out of the call, so EPOS runs its own keyword check. EPOS's answer `kind` is readable as `{{epos.kind}}` (the manifest lists it as an output).
- **Builder:** `EposNode.jsx` shows a text param with an `enum` as a dropdown of its values (plus "from the chat" answers), the same control as a `choice`.
- **Who decides complaint or order:** the tool has no intent classifier. The flow author decides: `kind` is a fixed value on the E-POS step. The complaint flow is entered by its own keyword (`complaint`, `shikayat`, `شکایت`, whole message), the order flow sets `kind: order`. If the author leaves `kind` blank, EPOS decides.
- **Shop has complaints switched off:** EPOS still answers `ok: true` with `kind: order`; the complaint flow gives the same "received" reply (test `test_the_complaint_reply_is_the_same_when_the_shop_has_complaints_switched_off`).
- **Example flow:** `app/logic/examples/epos-complaint-alert.flow.json` (Ask Question, E-POS step with `kind: complaint` and `title: Complaint`, "received" reply with `{{epos.alert_id}}` only from the Found exit, one "not saved, contact the shop" reply for every refusal and non-2xx). The order example now sets `kind: order`. The Urdu texts are the spec's samples and need a native check.
- **Tests:** `EposNodeTest` (kind sent and readable, outside the enum not sent, blank left out), `IntegrationApiTest` (enum kept, malformed skipped), `EposOrderAlertFlowTest` (complaint flow, refusals never "received", order flow sends `kind: order`).

### Delivery riders answer an order on WhatsApp: `delivery.rider_reply` (T-0069)
Code written and tested on 2026-10-29 (private test DB). **Not run against a real EPOS or on the live site: unverified.** EPOS side of the contract: `epos/Modules/Whatsapp/docs/TOOL-rider-delivery-reply.md`.
- **Where:** `RiderReplyHandler` (`app/Modules/Integrations/Services/External/`), called from `WhatsappDriver::storeNormalizedMessage()` right before the unread bump and `MessageReceived::dispatch`. Both the Cloud API and the WAHA path end there. It sits at the source, not in a listener, because listeners are auto-discovered and their order cannot be controlled.
- **When EPOS is asked:** inbound, not historical, type `text`, the text has a digit (any script), the contact has a valid `+` number, and the workspace has an EPOS link whose cached manifest lists `delivery.rider_reply`. Everyone else costs one DB read and no HTTP. Messages we sent ourselves (phone-sent) never come this way.
- **The call:** through `EposExecutor`, so the usual rules apply: the verified number is the contact's, never read from the text; 5 s; no retry; health, 60 calls a minute per link, and a row in the Recent calls table. `message` is the manifest param with `from: incoming_message` (fallback: named `message`), sent raw (line breaks and inner spaces kept) and cut at its `max_length` (1500). The `Idempotency-Key` seed is `rider:<provider message id>`; a message the webhook delivers twice is dropped earlier by the existing `whatsapp_msg` guard, so EPOS is asked once.
- **`handled: true`:** the `reply` is saved as an outgoing text (`sent_by` automation) and sent through the channel driver; the rider's message stays in the inbox, but the unread count does not go up and `MessageReceived` is not dispatched, so no automation, AI reply, alert.push, outbound webhook, label rule or notification runs. This holds for every handled `reason` (`order_not_found`, `ambiguous`, `unclear`, `order_cancelled`, ...) even with `ok: false`. A failed send is marked `failed` and logged; the normal flow still does not run.
- **Anything else is "not handled" and silent:** `handled: false` (`not_a_rider`, `no_order_number`), HTTP error, 401, timeout, non-JSON, `not_available`, `no_phone`, capability not in the manifest. The message continues as before and the sender sees nothing. Any exception in the handler is caught and logged (`external.rider_reply_failed`).
- **Manifest:** the param source `incoming_message` is accepted by `ManifestFetcher` (`PARAM_SOURCES`). Without it the whole capability was skipped as "param message: unknown source" and never reached the tool (found on the first real link, 2026-10-29). Test: `test_the_manifest_entry_exactly_as_the_epos_spec_gives_it_is_accepted`.
- **Tests:** `tests/Feature/Integrations/RiderReplyTest.php` (17 tests, real `WhatsappDriver` ingestion and real automation runs; they fail with the hook switched off).
- **Known gaps:** every digit-containing text from a customer makes one EPOS call and one row in Recent calls (it counts against the 60 a minute shared with flows). The reply is sent as one text with no 24 h window check (the rider has just written, so the window is open). Not looked at on a real phone.

### The customer's WhatsApp profile photo goes to EPOS: `contact.photo_update` (T-0070)
Code written and tested on 2026-10-29 (private test DB). **Not run against a real EPOS, a real WAHA or the live site: unverified.** EPOS side of the contract: `epos/Modules/Whatsapp/docs/TOOL-contact-photo.md`.
- **Trigger:** `WahaEventProcessor::handleInbound()` → `queuePhotoForEpos()` after a live customer message (history sync and phone-sent messages never come this way). It queues `SendProfilePhotoToEposJob` (queue `whatsapp`) only when the workspace has an EPOS link whose cached manifest lists `contact.photo_update`, the photo was not saved to EPOS in the last 30 days, and `Cache::add('epos-photo:<contact id>')` (1 day) succeeds. Only WhatsApp Web contacts: the Cloud API gives no profile photo.
- **Job:** `app/Modules/WhatsappWeb/Jobs/SendProfilePhotoToEposJob.php`. WAHA picture link (`profilePictureUrl`, as for the inbox avatar), download (10 s), then `EposExecutor::execute()` with the photo as base64 in the manifest param with `from: profile_photo` and `type: text` (fallback name `photo`). The number is the contact's verified one, bound by the executor. Rate limit, health and the Recent calls row apply as for any call.
- **Nothing is sent** when WAHA has no readable photo (private, none, error), the link is not https, the download fails, or the image is over 600 KB (`MAX_BYTES`, no downscaling). `removed: true` is never sent: WAHA cannot tell "removed" from "cannot read", and the spec says send nothing when unsure. A photo-change event is not handled either; a changed photo is picked up by the 30-day refresh.
- **Remembered for 30 days** (`epos-photo-sent:<contact id>`) only when EPOS answers `ok: true`. Any refusal (`invalid_photo`, `rate_limited`, `not_found`, `not_available`) or failure is not remembered, so the next message (at most one try a day per contact) tries again. A number EPOS does not know therefore costs one EPOS call a day while it keeps writing in.
- **Long text param:** `EposExecutor::validValue()` caps text at 2000 characters, except a param with `from: profile_photo`, which may go to `EposExecutor::MAX_PHOTO` (900000) as the manifest declares. The call log still cuts request/response at 2 KB. The 5 s call timeout also covers the upload.
- **Tests:** `tests/Feature/Integrations/EposContactPhotoTest.php` (8 tests: 100 KB photo sent base64 with the verified number, no action in manifest, hidden photo, over 600 KB, refusal not remembered, already sent, queued once a day from a real webhook, not queued without the action).
- **Known gaps:** no downscaling of big pictures, no removal, no photo-change event; not looked at against a real phone.

## RentOyee as a second linked system + per-client switches (T-0071)
Code written 2026-10-30. **Not run against a real RentOyee or the live site: unverified. Tests, phpstan and review were skipped on the user's instruction.** Only done: `php -l` on all PHP, eslint on the three JSX files, and a `Http::fake` check that the multipart upload really sends `phone`, `request_id` and an `image` file part. RentOyee side of the contract: `rentalLaravel/docs/integrations/e-whatsapp-tool/README.md`.

**One machine, two types.** RentOyee links through the same `/api/v1/integrations` API with `type: "rentoyee"` (`ExternalIntegration::TYPES`). Manifest cache, `SafeHttp`, health, the Recent calls table and the flow step are shared. The flow step is the same one (palette name "E-POS / RentOyee"); with both linked, the step's business picker chooses. The status page `/app/integrations/epos` lists both, with a type badge.

**Client switches.** `client_settings` keys `integration_epos_enabled` / `integration_rentoyee_enabled` (`ExternalIntegration::enabledFor()`, `switchKey()`). Off unless set; a workspace without a client is not gated. Client administrators set them in Settings → "Linked systems". Migration `2026_10_30_000001_switch_on_epos_for_clients_that_already_use_it` sets EPOS on for every client that already had a link and never touches a value again. Off means: `POST/PUT /integrations` answers `403 integration_disabled` (nothing stored; `DELETE` and `/check` still work), the executor makes no call ("no data", reason `not_enabled`) so flows, rider replies, photo and reconnect alerts all stop, the 5-minute poll skips it, and the builder does not offer it.

**What RentOyee needed on top of EPOS (all in `External/`):**
- `ManifestFetcher`: param type `file`; sources `reply_context`, `chat_attachment`; keeps `choice_labels`, `accept`, `max_mb`. `pollVersion()` = `GET /api/whatsapp/v1/ping`, re-reads the manifest only when its `manifest_version` differs.
- `EposExecutor`: `{ref}` (any `{name}`) in the path is filled with the param's value, URL-encoded, and not sent again; `required_if` as `"period=custom"` (EPOS' array form still works); `choices` as plain strings; a capability with a `file` param is sent `multipart/form-data` (fields + file part, `Idempotency-Key`) and refused with reason `invalid` if no file or over `max_mb` (5). `reply_text` stays in the answer, so a flow replies with `{{var.reply_text}}`.
- **401 = unlinked:** `IntegrationHealth::markUnlinked()` (status `unlinked`); the executor then makes no call. A passing "Check now", poll, or a new key (`PUT`) makes it `connected` again.
- **Poll:** `routes/console.php` `poll-rentoyee`, every 5 minutes, RentOyee links of switched-on clients only (EPOS is not polled; it keeps the `X-Epos-Manifest-Version` header).
- **Questions RentOyee sends:** `POST /api/v1/messages/send` takes `reply_context {source: "rentoyee", request_id, field?, expires_at}` and keeps it per contact in the cache (`ReplyContextHandler::key()`, until `expires_at`, at most 30 days, latest wins). `ReplyContextHandler` (called from `WhatsappDriver` before the rider check): the contact's next **text** → `customer.update_info {request_id, value}` raw; next **image** (WhatsApp Web only, stored through `WahaMediaStore`) → `customer.update_cnic_photo` multipart. `reply_text` is sent back and the normal flow does not run for that message. Context is cleared on `applied` / `pending_approval` / `no_change` / `request_closed`, kept on `invalid`. Not handled (message goes on normally, context kept): contact is waiting on a flow's Ask Question, no verified number, capability not in the manifest, any failure.
- Menu: the customer menu is whatever flows the business builds with the step (question picker is grouped by manifest `group`); there is no automatic WhatsApp menu.

**Known gaps:** pending questions live in the cache (a flush forgets them). A Cloud-API photo reply is not forwarded (no WAHA link). The `chat_attachment` / `reply_context` params are not fillable from a flow (the builder shows "filled in automatically"), so `customer.update_cnic_photo` only works through the reply path. Other locales only have English for the new strings. Existing EPOS tests that link without a client switch may need the switch set for workspaces that have a client.
