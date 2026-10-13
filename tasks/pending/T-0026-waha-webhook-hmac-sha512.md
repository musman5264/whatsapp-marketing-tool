---
id: T-0026
title: WAHA webhooks were rejected (SHA-256 check, WAHA signs SHA-512), so live WhatsApp messages never arrived
status: blocked
priority: high
type: bug
plan: tasks/plans/PLAN-000-general/PLAN.md
phase: tasks/plans/PLAN-000-general/phase-01-ongoing.md
depends_on: [T-0023]
source: TEMP/intake-2026-10-13-1318.md
created: 2026-10-13 16:29
started: 2026-10-13 16:29
updated: 2026-10-13 16:29
---

# T-0026: WAHA webhooks were rejected (SHA-256 check, WAHA signs SHA-512), so live WhatsApp messages never arrived

## What the user reported
> "when i message from mobile is not appearing here. not live sync" / "Last message from WAHA to this app: No webhook has been received"

## Root cause (evidence)
- `storage/logs/laravel.log` on cPanel, 2026-10-13 11:22–11:24: every WAHA request logged `whatsapp_web.webhook.signature_mismatch` (WARNING). Requests came from Cloudflare addresses (the domain is proxied).
- The controller verified `X-Webhook-Hmac` with HMAC-SHA256 only. WAHA's events documentation: `X-Webhook-Hmac` is the HMAC of the raw body, with `X-Webhook-Hmac-Algorithm` naming the algorithm, value `sha512`, default SHA-512.
- A rejected request returns 401 before `WhatsappWebHealth::recordWebhook()`, so the health card never saw a webhook. Messages reached the app only through the 10-minute history sync (`whatsapp-web:sync`), which matches the timing seen in the failed-job log.

## Fix (committed)
- `WhatsappWebWebhookController::verifyHmac()` now uses the algorithm named in `X-Webhook-Hmac-Algorithm` (default `sha512`) and accepts only `sha512`. Commit `3da12df`.
- Tests: `InboundWebhookTest` signs with SHA-512, plus two new tests (SHA-256 rejected; SHA-512 without the algorithm header accepted). `WahaMediaArrivalTest` and `BroadcastFailureDoesNotBreakInboundTest` sign with SHA-512. `tests/Feature/WhatsappWeb` → 103 tests, 243 assertions, all pass. PHPStan on the controller: no errors.
- Docs: `app/logic/waha-api-coverage.md` row updated.

## Still needed (blocked on the user)
1. Deploy: Admin → Deployment → Deploy latest commit (`3da12df` or later). No WAHA re-registration needed; the WAHA webhook config is unchanged.
2. Live check: `storage/logs/laravel.log` should stop logging `signature_mismatch`. Send a message from the phone. "Last message from WAHA to this app" on Channel Setup → Run check should tick, and the message should appear in the open inbox without a refresh.

## Open, separate
- One `whatsapp`-queue job (Sync now) was reserved (attempts 1) and had not finished. Not explained by this fix; look at the drain cron's output after the live check.
- The health card's "Background jobs" warning is wrong for this host: cPanel runs the database queue from the scheduler (`drain-queues`, every minute). It tests only the Docker Redis worker.

## Rules from CLAUDE.md
- [x] Test first; the SHA-256 and SHA-512 tests failed before the change.
- [x] Logic doc updated (`app/logic/waha-api-coverage.md`).
- [ ] Live verification (after deploy).

## Progress log
- 2026-10-13 16:29 created. Fix committed and pushed (`3da12df`). Waiting for the deploy and the live check.

## Result
(not finished: deploy and live check pending)
