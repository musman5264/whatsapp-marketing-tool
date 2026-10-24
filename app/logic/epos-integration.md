# EPOS integration: link, manifest, safe client (slice 1)

A remote system (EPOS) links itself to a workspace with one API call. The tool then keeps a cached copy of the manifest (the list of questions EPOS can answer). Built so far: the link API, manifest cache, health tracking and the guarded outbound client. **Not built yet:** the flow node that calls EPOS during a chat, the builder UI, the inbox panel (T-0048 to T-0050). Requirements: `TEMP/intake-2026-10-24-1408.md` (R1-R9); design: `tasks/plans/PLAN-003-epos-integration/PLAN.md`.
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
Nothing yet reads `unreachable` to stop live calls or show a banner: that is the executor (T-0048) and builder (T-0049).

## Logging
`external.manifest` lines carry integration id, status and milliseconds. Never the key, the manifest body or a phone number (test `test_key_never_reaches_the_logs`).

## How to test
```
DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit tests/Feature/Integrations tests/Feature/Api/V1/IntegrationApiTest.php
```
Result 2026-10-24 on private DB `wm3_test_t0047`: `OK (31 tests, 98 assertions)`; with `tests/Feature/ApiTokenTest.php tests/Feature/Api tests/Feature/WebhookEndpointTest.php` `OK (128 tests, 359 assertions)`. phpstan level 6 on the new files: no errors.

## Known gaps
- Retry/backoff is only recorded; no scheduler or job calls `ping()`/`refreshIfStale()` yet (the executor and panels will).
- The `integrations:write` scope is not in the token-screen docs list in `resources/js/Pages/client/Api/Docs.jsx` (frontend build avoided in this slice); it is in `ApiAbilities::all()` and `labels()`.
- `X-Epos-Manifest-Version` mismatch refresh (R2b) needs the executor; not built.
