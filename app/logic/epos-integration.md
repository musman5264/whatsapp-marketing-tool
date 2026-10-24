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
