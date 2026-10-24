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

Saved node data: `integration_id` (optional when the workspace has exactly one EPOS link), `capability` (manifest key), `params` (`{name: value}`, `{{tokens}}` allowed), `result_var` (default `epos`). No URL, header or key exists on the node.

What happens when it runs:
1. Capability not in the cached manifest, or no link: "no data" (run log says "no longer available" / "not linked"), no call.
2. Integration `unreachable` with `next_retry_at` still in the future: "no data" at once, no call. After that time one call is let through as the probe.
3. **Identity:** any manifest param whose type is `phone`, whose source is `sender_phone`, or that is named `phone` is filled with the chat contact's `phone_e164`, only if it is `+` and 7-15 digits. Whatever the author or the chat put there is ignored. No verified number: "no data", no call. (WAHA groups are never ingested, only 1:1 chats, so there is no group case.)
4. Other params: author value (tokens rendered) or the manifest `default`, checked by type (`number`; `date` as YYYY-MM-DD; `choice` must be one of the manifest values; `text` up to 200 chars). A missing required value (`optional` and `required_if` honoured) is "no data". Params the manifest does not list are never sent.
5. Rate limit 60 calls a minute per integration (`epos-calls:{id}`); over it: "no data".
6. Call through `SafeHttp`: query = `GET path?params`, action = `POST` JSON with an `Idempotency-Key` (sha1 of run id, node id, capability). Neither is retried (the spec allows one retry for queries; not done). 5 s, 100 KB.
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

Known gaps: no sidebar link to the status page (reachable from the builder banner, the node panel and the URL); the builder does not warn when an E-POS node has an unconnected exit.

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
