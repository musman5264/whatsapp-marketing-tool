# Deployment commands (allowlist and deploy key)

## What it does
The **Run** box on the Deployment page (Admin → Deployment → Advanced) sends one command to the server. The app checks it, then `DeploymentController::runCommand()` forwards it to `public/deploy.php`, which runs it in the site folder and returns the output. The app never runs deploy commands itself.

## The rule (one rule, two places)
A command runs **without the second key** only if it passes `CommandAllowlist::allows()` (`app/Modules/Deployment/Services/CommandAllowlist.php`):
- The text (trimmed) contains only letters, digits, space and `_ . : = / -`. Shell characters, quotes and newlines are refused outright, so nothing can be chained onto an allowed command.
- and either it equals an entry in `CommandAllowlist::EXACT` (`git status`, `git log`, `git log --oneline -20`, `git branch`, `composer install --no-dev`, `composer install --no-dev --optimize-autoloader`),
- or it is `php artisan <name>` where `<name>` is in `CommandAllowlist::ARTISAN` (`optimize:clear`, `migrate`, `migrate:status`, `config:clear`, `cache:clear`, `queue:restart`, `storage:link`, `whatsapp-web:status`, `about`, `reverb:launch`), optionally followed by plain arguments.

`public/deploy.php` applies the same rule with `$allowedExact` and `$allowedArtisan`. The two lists must match. `CommandAllowlistTest` reads the script and fails if they drift.

Anything refused needs the deploy key again as the second key (`confirm_key` in the app, `key2` in the script). The script refuses the command when the second key is wrong. Only the app's forwarding passes it on.

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
