<?php

/**
 * Deployment script configuration.
 *
 * Copy this file to `deploy.config.php` (same directory) on the PRODUCTION server
 * and fill in the values. `deploy.config.php` is .gitignore'd so your secret is
 * never committed.
 *
 * Until a valid config exists with a non-empty DEPLOY_KEY, public/deploy.php
 * refuses every request with HTTP 503.
 */

return [
    // A long random shared secret. The admin panel sends this as ?key=... on every
    // deploy request. Generate one, e.g.:  php -r "echo bin2hex(random_bytes(24));"
    'DEPLOY_KEY' => '',

    // Your GitHub repository. SSH is tried first (needs a deploy key on the server —
    // use ?action=setup-key from the admin panel to generate one). HTTPS is the
    // fallback (needs a Personal Access Token, passed per-request by the app).
    'REPO_URL_SSH'   => 'git@github.com:youruser/yourrepo.git',
    'REPO_URL_HTTPS' => 'https://github.com/youruser/yourrepo.git',

    // Branch to deploy.
    'BRANCH' => 'main',
];
