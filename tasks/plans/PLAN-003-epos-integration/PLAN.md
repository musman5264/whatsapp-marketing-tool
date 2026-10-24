# PLAN-003: EPOS integration (manifest-driven)

**Goal:** an EPOS admin links EPOS to a workspace with one call; "E-POS" then shows in the automation builder and the inbox, built from a manifest EPOS publishes. Source of truth: the EPOS requirements R1-R9 saved word for word in `TEMP/intake-2026-10-24-1408.md`. Supersedes Part B of `EPOS-INTEGRATION-SPEC.md` (Part A stays).
**Status:** design approved by the user 2026-10-24 (slice 1 first). Written spec below waits for the user's review before code.

## Decomposition (one spec, plan and cycle each)
| Slice | Covers | Task |
|---|---|---|
| 1 | R1 Integrations API, R2 manifest fetch/cache/health, SSRF-safe client, R8 key handling, R9 limits, written answers to EPOS | T-0047 |
| 2 | R3 executor + `epos` engine node, R6.3/6.4 action node, R7 caching rule | T-0048 (blocked by T-0047) |
| 3 | R4 builder node, admin status page + banner, English + Urdu strings | T-0049 (blocked by T-0048) |
| 4 | R5 inbox side panel | T-0050 (blocked by T-0048) |

## Slice 1 design

### Data (Integrations module, `app/Modules/Integrations`)
- Table `external_integrations`: `id`, `workspace_id`, `type` (enum string, `epos`), `name`, `base_url`, `manifest_url`, `key` (Laravel `encrypted` cast, `$hidden`), `manifest_version`, `manifest` (JSON cache, <=200 KB), `manifest_fetched_at`, `manifest_issues` (JSON: skipped capabilities + reason), `status` (`connected` | `unreachable`), `consecutive_failures`, `next_retry_at`, `last_ping_at`, timestamps. Unique `(workspace_id, type, base_url)` gives idempotent create.
- Model `ExternalIntegration`. No separate capabilities table: capabilities live inside the validated `manifest` JSON (YAGNI).
- Deliberately not `IntegrationConfig` (that holds provider credentials with a different shape).

### Services
- `SafeHttp` (one class, also reused by slice 2): resolves the host with DNS, refuses 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, ::1, fc00::/7 and link-local/metadata; re-checks on every call; no redirects; TLS verify on; 5 s total; response cap 100 KB (manifest 200 KB); header `Authorization: Bearer`, `Accept`, `X-Request-Id`. `allow_private_hosts` only from config `integrations.allow_private_hosts` (env, default false). Resolves once and pins the IP to the request (curl `resolve`) so DNS cannot change between check and call.
- `ManifestFetcher`: GET `manifest_url` through `SafeHttp`, validates the §5 schema per capability, skips invalid ones into `manifest_issues`, ignores unknown fields, stores manifest + `manifest_version`.
- `IntegrationHealth`: ping `GET {base_url}/api/whatsapp/v1/ping` at most once per minute while in use; 3 consecutive failures set `unreachable` and `next_retry_at` (1 -> 5 -> 30 min); success resets. Refresh triggers: create/PUT/check, `X-Epos-Manifest-Version` mismatch (hook for slice 2), cache older than 10 minutes when the builder or panel asks (`refreshIfStale()`).
- Log lines mask phones and never contain keys or response bodies; the audit line is integration id, capability key, status, latency, found.

### API (`/api/v1`, ability `integrations:write`)
`POST /integrations`, `GET /integrations`, `GET /integrations/{id}`, `PUT /integrations/{id}`, `DELETE /integrations/{id}`, `POST /integrations/{id}/check`. Exactly the request/response shapes in R1. Create fetches the manifest BEFORE saving; on failure `422 {"error":"manifest_unreachable","detail":...}` and nothing is saved. Same `type`+`base_url` in the workspace updates (200), a new one returns 201. Responses never contain `key`. Workspace scoping via the existing `WorkspaceScopedController`. Add `integrations:write` to `app/Support/ApiAbilities.php`, the token screen and `Docs.jsx` scope list (en.json label).
Ids from other workspaces return 404.

### Not in slice 1
Executor, node, builder UI, inbox panel, status page UI, Urdu strings (slices 2-4). Slice 1 exposes `ExternalIntegration::active()` and `refreshIfStale()` for them.

### Tests (write first)
Create ok -> 201 with `capabilities` count; same call again -> 200, one row; bad key (EPOS answers 401) -> 422 `manifest_unreachable`, zero rows; GET never contains `key` and the DB value is not the plaintext; token without ability -> 403; other workspace's id -> 404; PUT key rotation then manifest re-fetch; DELETE removes row; invalid capability skipped and listed in `manifest_issues`; `SafeHttp` refuses `http://169.254.169.254/`, `127.0.0.1`, `10.0.0.5`, an IPv6 `::1`, and a hostname resolving to private; no redirect followed; 3 failures -> `unreachable` with backoff steps; logs contain no key (assert on the log).

### Answers sent to EPOS (see `EPOS-REPLY-2026-10-24.md`)
R6.1, R7.1, R7.2 and open questions 1-4 as verified in the code; deliverables 2-4 (staging) need the user's deploy.

### Risks / unverified
Live behaviour of the Cloud API PDF upload; whether the EPOS-tried instance runs the current `ALLOWED_EVENTS`; QR-number inbound flows end to end. DNS pinning is the part most likely to need a fix on the real server.
