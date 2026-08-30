# Deployment (GitHub → server)

This app ships an **in-app deployment manager** (Admin → Deployment). It works by
talking over HTTP to a standalone `public/deploy.php` script on the target server —
the Laravel app never runs `git`/`composer` itself, so it survives overwriting its
own code mid-deploy. Designed for cPanel / shared hosting with SSH + git + composer.

## One-time server bootstrap

You do the **first** deploy by hand; after that the admin panel handles everything.

1. **SSH into the server** and check out the repo into the site directory
   (the directory whose `public/` your domain points at):

   ```bash
   cd ~/wa.esystematics.com          # or wherever the docroot's parent is
   git clone git@github.com:YOURUSER/YOURREPO.git .    # SSH — needs a deploy key (step 4), or:
   git clone https://YOURTOKEN@github.com/YOURUSER/YOURREPO.git .   # HTTPS with a PAT
   composer install --no-dev --optimize-autoloader
   cp .env.example .env && nano .env                   # set APP_KEY, DB_*, APP_URL, etc.
   php artisan key:generate
   php artisan migrate --force
   php artisan storage:link
   ```

   Set `APP_URL=https://wa.esystematics.com` and `APP_ENV=production` in `.env`.
   The frontend build (`public/build/`) is committed, so no `npm` is needed on the
   server — rebuild it locally with `npm run build` and commit before deploying.

2. **Create the deploy config** on the server:

   ```bash
   cp public/deploy.config.example.php public/deploy.config.php
   nano public/deploy.config.php
   ```

   Set a strong `DEPLOY_KEY` (`php -r "echo bin2hex(random_bytes(24));"`), your
   `REPO_URL_SSH` / `REPO_URL_HTTPS`, and `BRANCH`. This file is `.gitignore`d.

3. **Configure the manager**: log into the admin panel → **Deployment**:
   - Repository URL, Branch
   - Deploy script URL: `https://wa.esystematics.com/deploy.php`
   - Deploy key: the `DEPLOY_KEY` from step 2
   - GitHub token: only if the repo is private and you're using HTTPS
   - Save. Click **Check server** — you should see PHP/git/disk info.

4. **SSH deploy key (recommended over a token)**: click **Generate SSH deploy key
   on server** → copy the printed public key → GitHub repo → *Settings → Deploy
   keys → Add deploy key* → paste, title "wa production", **do not** allow write
   access.

## Day-to-day

- Push to the branch → Admin → Deployment → **Check for updates** shows "N commits
  behind" → **Deploy latest commit**. The step list shows fetch / reset / migrate /
  cache rebuild results. Every run is recorded in the history table with who/when.
- **Rollback**: *Load recent commits* → pick one → *Roll back to selected commit*.
- **Commands**: the dropdown runs allowlisted maintenance commands
  (`optimize:clear`, `migrate`, `queue:restart`, …). The "Custom command" box runs
  anything but requires re-entering the deploy key.

## After going live — WhatsApp Web

Once `APP_URL` is the public HTTPS URL, the WhatsApp Web (QR) integration can
receive inbound messages. In the client app: **Inbox → Setup → WhatsApp → QR code →
Disconnect**, then **Connect** again — this re-registers the session with WAHA using
the new public webhook URL (`https://wa.esystematics.com/webhooks/whatsapp-web/…`).

## Security notes

- `public/deploy.config.php` holds the shared secret and is git-ignored — never
  commit it. Rotate `DEPLOY_KEY` by editing the file and re-saving it in the admin
  panel.
- The manager UI is restricted to super-admins.
- When you're done setting up, use **Lock down** to remove common risky helper
  files, and consider **Remove deploy script** if you won't deploy for a while
  (re-upload `public/deploy.php` from the repo to re-enable).
