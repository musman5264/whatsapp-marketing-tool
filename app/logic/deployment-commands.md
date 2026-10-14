# Deployment commands (allowlist and deploy key)

## What it does
The **Run** box on the Deployment page (Admin → Deployment → Advanced) sends one command to the server. The app checks it, then `DeploymentController::runCommand()` forwards it to `public/deploy.php`, which runs it in the site folder and returns the output. The app never runs deploy commands itself.

## The rule (one rule, two places)
A command runs **without the second key** only if it passes `CommandAllowlist::allows()` (`app/Modules/Deployment/Services/CommandAllowlist.php`):
- The text (trimmed) contains only letters, digits, space and `_ . : = / -`. Shell characters, quotes and newlines are refused outright, so nothing can be chained onto an allowed command.
- and either it equals an entry in `CommandAllowlist::EXACT` (`git status`, `git log`, `git log --oneline -20`, `git branch`, `composer install --no-dev`, `composer install --no-dev --optimize-autoloader`),
- or it is `php artisan <name>` where `<name>` is in `CommandAllowlist::ARTISAN` (`optimize:clear`, `migrate`, `migrate:status`, `config:clear`, `cache:clear`, `queue:restart`, `storage:link`, `whatsapp-web:status`, `about`, `reverb:launch`, `queue:failed`), optionally followed by plain arguments.

`public/deploy.php` applies the same rule with `$allowedExact` and `$allowedArtisan`. The two lists must match. `CommandAllowlistTest` reads the script and fails if they drift.

Anything refused needs the deploy key again as the second key (`confirm_key` in the app, `key2` in the script). The script refuses the command when the second key is wrong. Only the app's forwarding passes it on.

## Commands you can run (2026-10-14)
Run from the **Run** box (Admin → Deployment → Advanced) or from the cPanel terminal. "No key" means the Run box runs it without the second key. "Second key" means the box asks for it. Every command is run in the site folder.

| Command | Key | What it does | When |
|---|---|---|---|
| `php artisan queue:failed` | no key | Lists failed jobs (read only) | Check why a job failed |
| `php artisan migrate --force` | no key | Runs new database migrations | After a deploy with migrations |
| `php artisan config:clear` | no key | Clears cached config | After any `.env` change |
| `php artisan queue:restart` | no key | Workers finish their current job and restart | After a deploy, so workers load new code |
| `php artisan whatsapp-web:status` | no key | WhatsApp Web session status | Quick health check |
| `php artisan whatsapp-web:resubscribe` | second key | Re-registers the WAHA webhook. Briefly restarts the session; the number stays linked | After a change to the webhook events (CLAUDE.md) |
| `php artisan whatsapp-web:sync` | second key | Imports recent chats and messages now (inline) | When messages are missing; add `--queue` to run it in the background |
| `php artisan whatsapp-web:lid-map <lid> <phone> --session=<name>` | second key | Dry run: shows which messages would move from a LID contact to the real contact | Before recording a LID mapping (see waha-sync-and-labels.md) |
| `php artisan whatsapp-web:lid-map <lid> <phone> --session=<name> --apply` | second key | Records the mapping and moves the messages (writes live data) | Only after the dry run |
| `php artisan queue:retry all` | second key | Re-runs **every** failed job. Outbound webhooks are sent again to the client endpoint | Only after the cause is fixed. Re-running a webhook that a receiver rejects just fails again |
| `php artisan queue:retry <id>` | second key | Re-runs one failed job by its id (from `queue:failed`) | Same caution as above |
| `php artisan media:fix-links` | second key | Counts saved photo links that use the integer conversation id (dry run) | Before a repair |
| `php artisan media:fix-links --apply` | second key | Rewrites those links in the database (writes live data) | After the dry run and a deploy of 6282223 |
| `php artisan media:make-private` | second key | Dry run: lists old public media files | Before a move |
| `php artisan media:make-private --apply` | second key | Moves old public media to the private disk and rewrites links | Only after a dry run |

**Avoid on the Run box:** `queue:flush` and `queue:forget` (they delete failed jobs for good), and `tinker` (runs arbitrary PHP). With the second key, `public/deploy.php` runs any command, so nothing stops these there. Run them only from the cPanel terminal, after a backup.

The `--apply` variants and `queue:retry` are **not** on the no-key list. A command name on the allowlist lets its extra arguments run without the key, so write-capable commands stay behind the second key. Shell characters (`|`, `;`, quotes, `>`) are never on the no-key list either: they only run with the second key, the same as any other command.

## Commands outside the allowlist
- `php artisan media:make-private` is not in `CommandAllowlist::ARTISAN`, so the Run box refuses it without the second key. Use the second key (`confirm_key`) on the Run box, or run it in the cPanel terminal. Dry run first (no `--apply`). See [private-media.md](private-media.md).

## Deploy key
- `public/deploy.php` has **no built-in key**. Without `DEPLOY_KEY` in `public/deploy.config.php` every request is refused with 503 (`not configured`).
- The key must not appear in repo files. It appeared in `WAHA-EXPANSION-PLAN.md` and was replaced with `<DEPLOY_KEY>`. That key is still in git history, so rotate it if it is used anywhere.

## How to test
- `tests/Feature/Deployment/CommandAllowlistTest.php`: 13 refused patterns (chains, pipes, backticks, `$()`, redirects, newline, quotes, look-alike artisan names, `git branch -D`) checked on the app side and against a copy of the remote script; allowed commands run without the second key; the two lists match; no built-in key; no config key → 503.
- `tests/Feature/Deployment/RollbackAndCommandTest.php`: the command path and the forwarded request.

## Limits
- Artisan arguments are only limited to plain characters. An allowed artisan command with harmless-looking arguments still runs without the second key. Add arguments to `CommandAllowlist` if that ever matters.
- The rule is a whitelist. Anything else needs the deploy key again, and the key is the only protection for that path.
