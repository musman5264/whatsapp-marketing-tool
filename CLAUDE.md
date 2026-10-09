# WhatsApp Marketing Tool — Claude Instructions

## Read first
This file is read by Claude-guided assistants in this repo. Always read and follow it before making changes.
Project: multi-channel inbox + broadcasting + automation (WhatsApp Cloud API and WhatsApp Web via WAHA, Instagram, Messenger, SMS, email).
Stack: Laravel 12 / PHP 8.2+ backend organised in `app/Modules/*`, Inertia + **React** (`resources/js/Pages/**/*.jsx`) frontend, Pusher/Reverb realtime, MySQL.
Live site: **https://wa.esystematics.com** (Docker image built from `Dockerfile`; see `docker/entrypoint.sh` and `DEPLOYMENT.md`).

## Task tracking (always)
Before doing anything else in a conversation, read `tasks/INSTRUCTIONS.md` and `tasks/INDEX.md` and follow them.
Every new request goes through the task-tracker workflow: TEMP intake file, then task files in `tasks/pending|working|completed`, each listing the CLAUDE.md rules, logic docs and skills it must follow.

## Skills and plugins are mandatory, not optional
For every request, in this order:
1. **task-tracker** — intake, then tasks (never tasks before the process skill).
2. The matching **superpowers** process skill: `brainstorming` (features/changes), `systematic-debugging` (bugs), `writing-plans` (multi-step), `test-driven-development` (writing code), `dispatching-parallel-agents` (2+ independent tracks), `verification-before-completion` (before saying "done"), `requesting-code-review` (after a major feature).
3. Any **domain skill or plugin** whose trigger matches (frontend-design, senior-frontend, senior-dashboard, code-reviewer, planetscale-database, figma/engineering plugins, …) — list them in the task's Skills section and tick them when actually invoked.
Independent tracks run as parallel agents with non-overlapping file ownership; agents never commit — the main thread reviews every diff, runs the real tests, then commits.

## Never guess — verify against the actual code every time
Guessing is strictly banned during coding, review, debugging or brainstorming. Before stating how something behaves, read the actual file/line. Never answer from memory or from what a similar codebase would do. If something cannot be verified, say so explicitly.
Behaviour of the **live WAHA engine** (NOWEB) and of the live site counts as unverified until it has been checked against the running system — say "unverified" in reports, never "fixed".
Check any new WAHA endpoint against https://waha.devlike.pro/docs/ or its swagger (`/swagger/openapi.json`) before calling it — the old `POST /api/sessions/{s}` call silently did nothing because only `PUT` exists.

## Git and deployment
- Branch: **`main`** is the only line. Remote: `origin` (github `musman5264/whatsapp-marketing-tool`). `origin/claude/*` branches are old agent branches — ignore.
- After every completed change: `git add <files by name>` (never `git add -A`), `git commit` with a message that says what and why, `git pull --rebase origin main` if the push is rejected (others push Dockerfile changes), then `git push origin main`. Never force-push, never `reset --hard`/stash/clean, never commit another session's files.
- `public/build` is **committed on purpose**. If anything under `resources/js` changed, run `npm run build` and commit the build output in its own commit before pushing.
- Deploy target is **wa.esystematics.com**. A push to `main` does NOT prove it is live: after pushing, confirm by fetching `https://wa.esystematics.com/login` and checking the `assets/app-*.js` hash against `public/build/manifest.json` (and the `content-security-policy` header for backend changes). Do not report "deployed" until that matches.
- Post-deploy commands for WhatsApp changes: `php artisan migrate --force`, `php artisan config:clear`, `php artisan whatsapp-web:resubscribe` (re-registers the WAHA webhook, number stays linked).
- `wa2.dermavalue.pk` is a retired deploy target; its `deploy.php` returns 404. Do not use it.
- Never write secrets (deploy keys, API keys, `.env` values) into the repo.

## Module layout
New backend code goes under `app/Modules/<Module>/` (`Http`, `Models`, `Services`, `Jobs`, `Listeners`, `database/migrations`, `routes/web.php`) and is registered by that module's service provider. WAHA / WhatsApp Web code lives in `app/Modules/WhatsappWeb`; Cloud API code in `app/Modules/Whatsapp`; inbox/labels in `app/Modules/Inbox`.
Shared cross-cutting classes go in `app/Services` (e.g. `RealtimeConfig`, the single source of truth for Pusher/Reverb used by the CSP middleware, Inertia props and the broadcast provider — never duplicate that decision).

## WhatsApp Web (WAHA) rules
- Webhook events are subscribed only through `WahaAdapter::WEBHOOK_EVENTS`. A new event needs: the constant, a branch in `WahaEventProcessor::process()`, a test, and a note in the logic doc. Existing sessions only pick it up after `whatsapp-web:resubscribe`.
- WAHA calls from the inbound pipeline are **soft-fail**: catch `Throwable`, log, never block or lose an inbound message.
- Backfilled/historical messages must never fire automations, AI replies or unread bumps (`historical` flag in `WhatsappDriver`).
- Messages sent from the phone arrive as `message.any` with `fromMe=true`; our own API sends have `source=api` and are skipped.
- WhatsApp labels need a WhatsApp Business number; saving contacts to the phone may be rejected by the engine. Both are best-effort and must degrade silently.

## Frontend rules
- Pages are React JSX under `resources/js/Pages`; use `useTranslation()` and add every new user-facing string to `resources/js/locales/en.json` (17 locale files exist; English is the required baseline).
- Check new UI at 360px and 1920px wide, in light and dark mode.
- Dates/times shown to users must come from the user's/workspace's configured timezone and locale settings, never raw DB timestamps — read how the page you are touching already formats dates before adding one.
- `HandleInertiaRequests.php` is excluded from phpstan; keep shared props small and never leak secrets into them (Pusher key is public, secret is not).

## Testing
- PHPUnit uses MySQL database `wm3_test` (see `phpunit.xml`). The password in `.env` is rejected locally; run with an empty one and a bigger memory limit:
  `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter='<name>'` (`php artisan test` spawns a child PHP without the memory flag and fatals on the full suite).
- **Never run two test processes at once** — `RefreshDatabase` collides on the shared schema ("table already exists"). Agents that need parallel runs use a private database (`wm3_test_<task>`) via a scratch copy of `phpunit.xml`.
- Known failing at baseline (not caused by recent work; do not "fix" them silently, and do not mask them): `HandoverTest` (2), `LabelCrudTest` (3), `SlaTrackingTest` (2), `MetaInboundWebhookTest::global_webhook_dedupes_duplicate_entry_ids`, `TypingEndpointTest` (2). Compare any failure against this list before blaming your change.
- Static analysis: `vendor/bin/phpstan analyse <files>` (level 6, baseline in `phpstan-baseline.neon`); frontend: `npx eslint <files>`.
- Never use `git worktree` with directory junctions into `vendor/` or `node_modules/` — `git worktree remove` follows them and deletes the real folders (this happened 2026-10-09; recovered with `composer install` and `npm ci`).

## App logic documentation is mandatory — write it to app/logic/
Whenever you build a feature (or a working piece of one), write its logic and implementation to a markdown file in **[`app/logic/`](app/logic/README.md)** before considering the task done. Read `app/logic/README.md` first — it indexes every file. Update an existing file in place so it never goes stale; document only what is built and working now (plans belong in `tasks/plans/` and `WAHA-EXPANSION-*.md`). The repo's `/docs` folder is git-ignored, so nothing there is shared — `app/logic/` is the tracked home.

## Working style
- Explain results in simple, non-technical language; report failures and skipped steps honestly with the real command output.
- Ask before deleting data or anything that cannot be undone; state-changing calls to the live server (deploy, env changes) are done only when the user asks for them.
- Never show SQL/stack traces to end users; log them instead.
