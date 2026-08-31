<?php

/**
 * Standalone git deployment script (cPanel / shared hosting friendly).
 *
 * The Laravel app never runs deploy commands itself — it makes an HTTP request to
 * THIS script, which is a separate PHP invocation. That way the app can be
 * mid-overwrite without crashing the request doing the work.
 *
 * Config comes from a sibling `deploy.config.php` (git-ignored). Without it, or
 * without a DEPLOY_KEY, every request is refused with 503.
 *
 * Trigger:
 *   https://your-site/deploy.php?key=DEPLOY_KEY&action=ACTION
 *
 * Actions: probe · setup-key · clone · deploy · rollback · status · git-info
 *          composer · migrate · migrate-status · fix-env · set-env · cleanup · cmd · log · selfdelete
 */

set_time_limit(600);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
header('Content-Type: application/json');

// ─── Load config ─────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/deploy.config.php';
if (! is_file($configFile)) {
    http_response_code(503);
    die(json_encode(['error' => 'not configured', 'hint' => 'Create public/deploy.config.php from deploy.config.example.php']));
}
$CONFIG = require $configFile;

$DEPLOY_KEY     = (string) ($CONFIG['DEPLOY_KEY'] ?? '');
$REPO_URL_SSH   = (string) ($CONFIG['REPO_URL_SSH'] ?? '');
$REPO_URL_HTTPS = (string) ($CONFIG['REPO_URL_HTTPS'] ?? '');
$BRANCH         = (string) ($CONFIG['BRANCH'] ?? 'main');
$SITE_ROOT      = dirname(__DIR__);                 // parent of public/
$HOME_DIR       = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
$SSH_DIR        = $HOME_DIR . '/.ssh';
$SSH_KEY_FILE   = $SSH_DIR . '/wa_deploy';

if ($DEPLOY_KEY === '') {
    http_response_code(503);
    die(json_encode(['error' => 'not configured', 'hint' => 'Set DEPLOY_KEY in deploy.config.php']));
}

// ─── Auth ────────────────────────────────────────────────────────────────────
if (! hash_equals($DEPLOY_KEY, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    die(json_encode(['error' => 'invalid deploy key']));
}

// ─── Rate limit: 10 requests / minute / IP ───────────────────────────────────
$rlFile = sys_get_temp_dir() . '/wa_deploy_rl_' . md5($_SERVER['REMOTE_ADDR'] ?? 'cli') . '.json';
$hits = is_file($rlFile) ? (json_decode((string) file_get_contents($rlFile), true) ?: []) : [];
$hits = array_values(array_filter($hits, fn ($t) => $t > time() - 60));
if (count($hits) >= 10) {
    http_response_code(429);
    die(json_encode(['error' => 'rate limit exceeded, try again in a minute']));
}
$hits[] = time();
@file_put_contents($rlFile, json_encode($hits));

// ─── Action allowlist ────────────────────────────────────────────────────────
$action = (string) ($_GET['action'] ?? 'status');
$ALLOWED = ['probe', 'setup-key', 'clone', 'deploy', 'rollback', 'status', 'git-info',
    'composer', 'migrate', 'migrate-status', 'fix-env', 'set-env', 'cleanup', 'cmd', 'log', 'selfdelete'];
if (! in_array($action, $ALLOWED, true)) {
    http_response_code(400);
    die(json_encode(['error' => 'unknown action: ' . $action, 'allowed' => $ALLOWED]));
}

// ─── Shell helper ────────────────────────────────────────────────────────────
function run(string $cmd, ?string $cwd = null): array
{
    global $HOME_DIR, $SSH_KEY_FILE;

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = $_ENV;
    $env['HOME'] = $HOME_DIR;
    if (is_file($SSH_KEY_FILE)) {
        $env['GIT_SSH_COMMAND'] = "ssh -i {$SSH_KEY_FILE} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";
    }

    $p = @proc_open($cmd, $desc, $pipes, $cwd, $env);
    if (! is_resource($p)) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed (disabled?)'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($p);

    return ['exit' => $exit, 'stdout' => trim((string) $out), 'stderr' => trim((string) $err)];
}

function out(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function composerCmd(): string
{
    foreach (['composer', '/usr/local/bin/composer', 'php composer.phar', 'php /opt/cpanel/composer/bin/composer'] as $c) {
        $probe = run("{$c} --version 2>/dev/null");
        if ($probe['exit'] === 0) {
            return $c;
        }
    }
    return 'composer';
}

$IS_GIT = is_dir($SITE_ROOT . '/.git');

// ═════════════════════════════════════════════════════════════════════════════
// probe
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'probe') {
    $git = run('git --version');
    $ssh = run('ssh -V 2>&1');
    out([
        'action' => 'probe',
        'ok' => true,
        'site_root' => $SITE_ROOT,
        'home_dir' => $HOME_DIR,
        'whoami' => run('whoami')['stdout'],
        'php_version' => PHP_VERSION,
        'git_version' => $git['exit'] === 0 ? $git['stdout'] : ('NOT AVAILABLE: ' . $git['stderr']),
        'ssh_available' => $ssh['exit'] === 0 || str_contains($ssh['stderr'], 'OpenSSH'),
        'ssh_deploy_key' => is_file($SSH_KEY_FILE) ? 'EXISTS' : 'NOT SET UP',
        'git_repo_initialized' => $IS_GIT,
        'composer' => (function () {
            $c = run(composerCmd() . ' --version 2>&1');
            return $c['exit'] === 0 ? $c['stdout'] : 'NOT AVAILABLE';
        })(),
        'disk_free' => @round(disk_free_space($SITE_ROOT) / 1073741824, 2) . ' GB',
        'functions' => [
            'proc_open' => function_exists('proc_open'),
            'exec' => function_exists('exec'),
            'shell_exec' => function_exists('shell_exec'),
        ],
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// setup-key — generate an SSH deploy key to add to GitHub (read-only)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'setup-key') {
    if (! is_dir($SSH_DIR)) {
        @mkdir($SSH_DIR, 0700, true);
    }
    if (is_file($SSH_KEY_FILE . '.pub')) {
        out([
            'action' => 'setup-key',
            'status' => 'KEY_ALREADY_EXISTS',
            'public_key' => trim((string) file_get_contents($SSH_KEY_FILE . '.pub')),
            'instructions' => 'Add this as a read-only Deploy Key in your GitHub repo → Settings → Deploy keys.',
        ]);
    }

    $r = run("ssh-keygen -t ed25519 -f {$SSH_KEY_FILE} -N '' -C 'wa-cpanel-deploy'");
    if ($r['exit'] !== 0) {
        $r = run("ssh-keygen -t rsa -b 4096 -f {$SSH_KEY_FILE} -N '' -C 'wa-cpanel-deploy'");
    }
    if ($r['exit'] !== 0 || ! is_file($SSH_KEY_FILE . '.pub')) {
        out(['action' => 'setup-key', 'ok' => false, 'error' => 'ssh-keygen failed', 'details' => $r['stderr']]);
    }
    @chmod($SSH_KEY_FILE, 0600);
    @chmod($SSH_KEY_FILE . '.pub', 0644);

    $sshConfig = $SSH_DIR . '/config';
    if (! is_file($sshConfig) || ! str_contains((string) file_get_contents($sshConfig), 'wa_deploy')) {
        @file_put_contents(
            $sshConfig,
            "\nHost github.com\n  IdentityFile {$SSH_KEY_FILE}\n  StrictHostKeyChecking no\n  UserKnownHostsFile /dev/null\n",
            FILE_APPEND
        );
        @chmod($sshConfig, 0600);
    }

    out([
        'action' => 'setup-key',
        'status' => 'KEY_GENERATED',
        'public_key' => trim((string) file_get_contents($SSH_KEY_FILE . '.pub')),
        'instructions' => 'Add this as a read-only Deploy Key in your GitHub repo → Settings → Deploy keys (do NOT allow write access).',
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// clone — first-time checkout
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'clone') {
    if ($IS_GIT) {
        out(['action' => 'clone', 'status' => 'ALREADY_CLONED', 'message' => 'Repo exists. Use action=deploy.']);
    }
    $res = [];
    $res[] = ['git init' => run('git init', $SITE_ROOT)['exit'] === 0 ? 'OK' : 'FAIL'];

    $r = run('git remote add origin ' . escapeshellarg($REPO_URL_SSH), $SITE_ROOT);
    $res[] = ['remote (ssh)' => $r['exit'] === 0 ? 'OK' : $r['stderr']];

    $r = run("git fetch origin " . escapeshellarg($BRANCH), $SITE_ROOT);
    if ($r['exit'] !== 0) {
        run('git remote remove origin', $SITE_ROOT);
        $token = (string) ($_GET['token'] ?? '');
        $https = $token !== ''
            ? preg_replace('#^https://#', "https://{$token}@", $REPO_URL_HTTPS)
            : $REPO_URL_HTTPS;
        run('git remote add origin ' . escapeshellarg($https), $SITE_ROOT);
        $r = run("git fetch origin " . escapeshellarg($BRANCH), $SITE_ROOT);
        $res[] = ['fetch (https)' => $r['exit'] === 0 ? 'OK' : $r['stderr']];
        if ($token !== '' && $r['exit'] === 0) {
            run('git remote set-url origin ' . escapeshellarg($REPO_URL_HTTPS), $SITE_ROOT);
        }
    } else {
        $res[] = ['fetch (ssh)' => 'OK'];
    }

    if ($r['exit'] !== 0) {
        out(['action' => 'clone', 'ok' => false, 'status' => 'FETCH_FAILED', 'results' => $res,
            'hint' => 'SSH: add the deploy key to GitHub. HTTPS: pass ?token=YOUR_PAT']);
    }

    run('git checkout -f ' . escapeshellarg($BRANCH), $SITE_ROOT);
    run('git branch --set-upstream-to=origin/' . $BRANCH . ' ' . $BRANCH, $SITE_ROOT);
    out(['action' => 'clone', 'ok' => true, 'status' => 'SUCCESS', 'results' => $res,
        'next' => 'Run action=deploy']);
}

// ═════════════════════════════════════════════════════════════════════════════
// deploy — pull latest + post-deploy
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'deploy') {
    if (! $IS_GIT) {
        out(['action' => 'deploy', 'ok' => false, 'error' => 'Not a git repo. Run action=clone first.']);
    }
    $res = [];
    $t0 = microtime(true);

    // Resolve prior unmerged / stash cruft
    if (trim(run('git diff --name-only --diff-filter=U', $SITE_ROOT)['stdout']) !== '') {
        run('git checkout --theirs . 2>/dev/null', $SITE_ROOT);
        run('git add -A 2>/dev/null', $SITE_ROOT);
        run('git reset HEAD -- . 2>/dev/null', $SITE_ROOT);
        $res[] = ['pre-clean' => 'resolved unmerged paths'];
    }
    if (trim(run('git stash list', $SITE_ROOT)['stdout']) !== '') {
        run('git stash drop 2>/dev/null', $SITE_ROOT);
    }

    // Preserve .htaccess
    $ht = [];
    foreach (['.htaccess', 'public/.htaccess'] as $f) {
        if (is_file($SITE_ROOT . '/' . $f)) {
            $ht[$f] = file_get_contents($SITE_ROOT . '/' . $f);
        }
    }

    $before = trim(run('git rev-parse HEAD', $SITE_ROOT)['stdout']);

    // Private-repo HTTPS auth
    $token = (string) ($_GET['token'] ?? '');
    if ($token !== '') {
        $authUrl = preg_replace('#^https://#', "https://{$token}@", $REPO_URL_HTTPS);
        run('git remote set-url origin ' . escapeshellarg($authUrl), $SITE_ROOT);
    }

    $r = run("git fetch origin '+refs/heads/{$BRANCH}:refs/remotes/origin/{$BRANCH}' --force 2>&1", $SITE_ROOT);
    $res[] = ['fetch' => $r['exit'] === 0 ? 'OK' : ('ERROR: ' . trim($r['stdout'] . ' ' . $r['stderr']))];

    if ($token !== '') {
        run('git remote set-url origin ' . escapeshellarg($REPO_URL_HTTPS), $SITE_ROOT);
    }

    $r = run("git reset --hard origin/{$BRANCH} 2>&1", $SITE_ROOT);
    $res[] = ['reset' => $r['exit'] === 0 ? ($r['stdout'] ?: 'OK') : ('ERROR: ' . $r['stderr'])];

    // Restore .htaccess
    foreach ($ht as $f => $c) {
        file_put_contents($SITE_ROOT . '/' . $f, $c);
    }

    // Strip \r from .env
    $envP = $SITE_ROOT . '/.env';
    if (is_file($envP)) {
        $e = file_get_contents($envP);
        $c = str_replace("\r", '', $e);
        if ($c !== $e) {
            file_put_contents($envP, $c);
            $res[] = ['env-cleanup' => 'removed CR chars'];
        }
    }

    // Optional composer
    if (($_GET['composer'] ?? '') === '1') {
        $r = run(composerCmd() . ' install --no-dev --optimize-autoloader --no-interaction 2>&1', $SITE_ROOT);
        $res[] = ['composer' => $r['exit'] === 0 ? 'OK' : ('ERROR: ' . substr($r['stdout'] . $r['stderr'], -800))];
    }

    // Artisan
    if (is_file($SITE_ROOT . '/artisan')) {
        if (($_GET['migrate'] ?? '1') === '1') {
            $r = run('php artisan migrate --force 2>&1', $SITE_ROOT);
            $res[] = ['migrate' => $r['exit'] === 0 ? ($r['stdout'] ?: 'OK') : ('ERROR: ' . ($r['stderr'] ?: $r['stdout']))];
        }
        foreach (['config:clear', 'route:clear', 'view:clear', 'cache:clear'] as $c) {
            run("php artisan {$c} 2>&1", $SITE_ROOT);
        }
        $res[] = ['caches cleared' => 'OK'];
        // NOT config:cache — shared hosting reads env at runtime
        run('php artisan route:cache 2>&1', $SITE_ROOT);
        run('php artisan view:cache 2>&1', $SITE_ROOT);
        run('php artisan optimize:clear 2>&1', $SITE_ROOT);
    }

    // Storage dirs + symlink
    foreach (['storage/app/public', 'storage/framework/cache', 'storage/framework/sessions',
        'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $d) {
        if (! is_dir($SITE_ROOT . '/' . $d)) {
            @mkdir($SITE_ROOT . '/' . $d, 0775, true);
        }
    }
    if (! file_exists($SITE_ROOT . '/public/storage') && is_dir($SITE_ROOT . '/storage/app/public')) {
        @symlink($SITE_ROOT . '/storage/app/public', $SITE_ROOT . '/public/storage');
    }

    $g = run('git log -1 --format="%h %s"', $SITE_ROOT);
    out([
        'action' => 'deploy',
        'ok' => true,
        'status' => 'SUCCESS',
        'elapsed' => round(microtime(true) - $t0, 2) . 's',
        'git' => [
            'branch' => trim(run('git branch --show-current', $SITE_ROOT)['stdout']),
            'commit' => trim($g['stdout']),
            'commit_hash' => trim(run('git rev-parse HEAD', $SITE_ROOT)['stdout']),
            'previous' => $before,
        ],
        'results' => $res,
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// rollback
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'rollback') {
    if (! $IS_GIT) {
        out(['action' => 'rollback', 'ok' => false, 'error' => 'Not a git repo.']);
    }
    $current = trim(run('git log -1 --format="%h %s (%ar)"', $SITE_ROOT)['stdout']);

    if (isset($_GET['list'])) {
        $n = max(10, min((int) ($_GET['list'] ?: 20), 50));
        out([
            'action' => 'rollback', 'mode' => 'list', 'current' => $current,
            'commits' => explode("\n", run("git log --oneline -{$n}", $SITE_ROOT)['stdout']),
        ]);
    }

    $t0 = microtime(true);
    $target = (string) ($_GET['commit'] ?? '');
    if ($target !== '') {
        $chk = run('git cat-file -t ' . escapeshellarg($target) . ' 2>&1', $SITE_ROOT);
        if (trim($chk['stdout']) !== 'commit') {
            out(['action' => 'rollback', 'ok' => false, 'error' => 'invalid commit: ' . $target]);
        }
    } else {
        $steps = max(1, min((int) ($_GET['steps'] ?? 1), 20));
        $rp = run('git rev-parse ' . escapeshellarg("HEAD~{$steps}") . ' 2>&1', $SITE_ROOT);
        if ($rp['exit'] !== 0) {
            out(['action' => 'rollback', 'ok' => false, 'error' => "not enough history for {$steps} steps"]);
        }
        $target = trim($rp['stdout']);
    }

    $before = trim(run('git rev-parse HEAD', $SITE_ROOT)['stdout']);
    $ht = [];
    foreach (['.htaccess', 'public/.htaccess'] as $f) {
        if (is_file($SITE_ROOT . '/' . $f)) {
            $ht[$f] = file_get_contents($SITE_ROOT . '/' . $f);
        }
    }

    $r = run('git reset --hard ' . escapeshellarg($target) . ' 2>&1', $SITE_ROOT);
    if ($r['exit'] !== 0) {
        out(['action' => 'rollback', 'ok' => false, 'error' => 'git reset failed', 'detail' => $r['stderr']]);
    }
    foreach ($ht as $f => $c) {
        file_put_contents($SITE_ROOT . '/' . $f, $c);
    }
    if (is_file($SITE_ROOT . '/artisan')) {
        run('php artisan migrate --force 2>&1', $SITE_ROOT);
        run('php artisan optimize:clear 2>&1', $SITE_ROOT);
        run('php artisan route:cache 2>&1', $SITE_ROOT);
        run('php artisan view:cache 2>&1', $SITE_ROOT);
    }

    $after = run('git log -1 --format="%h %s (%ar)"', $SITE_ROOT);
    out([
        'action' => 'rollback', 'ok' => true, 'status' => 'SUCCESS',
        'elapsed' => round(microtime(true) - $t0, 2) . 's',
        'before' => $current, 'after' => trim($after['stdout']),
        'commit_hash' => trim(run('git rev-parse HEAD', $SITE_ROOT)['stdout']),
        'previous_commit' => $before,
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// status
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'status') {
    if (! $IS_GIT) {
        out(['action' => 'status', 'ok' => true, 'git_initialized' => false,
            'message' => 'No git repo. Run action=clone.']);
    }
    out([
        'action' => 'status', 'ok' => true, 'git_initialized' => true,
        'git' => [
            'branch' => run('git branch --show-current', $SITE_ROOT)['stdout'],
            'commit' => run('git log -1 --format="%h %s"', $SITE_ROOT)['stdout'],
            'commit_hash' => run('git rev-parse HEAD', $SITE_ROOT)['stdout'],
        ],
        'recent_log' => explode("\n", run('git log --oneline -10', $SITE_ROOT)['stdout']),
        'uncommitted' => array_filter(explode("\n", run('git status --short', $SITE_ROOT)['stdout'])),
        'php_version' => PHP_VERSION,
        'disk_free' => @round(disk_free_space($SITE_ROOT) / 1073741824, 2) . ' GB',
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// git-info — local HEAD vs origin/BRANCH ahead/behind (after an authed fetch)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'git-info') {
    if (! $IS_GIT) {
        out(['action' => 'git-info', 'ok' => false, 'error' => 'no repo']);
    }
    $token = (string) ($_GET['token'] ?? '');
    if ($token !== '') {
        $authUrl = preg_replace('#^https://#', "https://{$token}@", $REPO_URL_HTTPS);
        run("git fetch " . escapeshellarg($authUrl) . " {$BRANCH}:refs/remotes/origin/{$BRANCH} 2>&1", $SITE_ROOT);
    } else {
        run('git fetch origin 2>&1', $SITE_ROOT);
    }
    $ahead = (int) run("git rev-list --count origin/{$BRANCH}..HEAD 2>/dev/null", $SITE_ROOT)['stdout'];
    $behind = (int) run("git rev-list --count HEAD..origin/{$BRANCH} 2>/dev/null", $SITE_ROOT)['stdout'];
    out([
        'action' => 'git-info', 'ok' => true,
        'branch' => run('git branch --show-current', $SITE_ROOT)['stdout'],
        'local_commit' => run('git log -1 --format="%h %s"', $SITE_ROOT)['stdout'],
        'local_hash' => run('git rev-parse HEAD', $SITE_ROOT)['stdout'],
        'remote_commit' => run("git log -1 --format=\"%h %s\" origin/{$BRANCH} 2>/dev/null", $SITE_ROOT)['stdout'],
        'commits_ahead' => $ahead,
        'commits_behind' => $behind,
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// composer
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'composer') {
    $r = run(composerCmd() . ' install --no-dev --optimize-autoloader --no-interaction 2>&1', $SITE_ROOT);
    out(['action' => 'composer', 'ok' => $r['exit'] === 0, 'exit' => $r['exit'],
        'output' => $r['stdout'], 'errors' => $r['stderr']]);
}

// ═════════════════════════════════════════════════════════════════════════════
// migrate / migrate-status
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'migrate') {
    $r = run('php artisan migrate --force 2>&1', $SITE_ROOT);
    out(['action' => 'migrate', 'ok' => $r['exit'] === 0,
        'output' => array_values(array_filter(array_map('trim', explode("\n", $r['stdout']))))]);
}
if ($action === 'migrate-status') {
    $r = run('php artisan migrate:status 2>&1', $SITE_ROOT);
    out(['action' => 'migrate-status', 'ok' => $r['exit'] === 0,
        'output' => explode("\n", $r['stdout'])]);
}

// ═════════════════════════════════════════════════════════════════════════════
// fix-env
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'fix-env') {
    $envF = $SITE_ROOT . '/.env';
    if (! is_file($envF)) {
        out(['action' => 'fix-env', 'ok' => false, 'error' => '.env not found']);
    }
    $orig = file_get_contents($envF);
    $clean = implode("\n", array_map('rtrim', explode("\n", str_replace("\r", '', $orig))));
    file_put_contents($envF, $clean);
    run('php artisan config:clear 2>&1', $SITE_ROOT);
    run('php artisan cache:clear 2>&1', $SITE_ROOT);
    out(['action' => 'fix-env', 'ok' => true, 'cr_removed' => substr_count($orig, "\r")]);
}

// ═════════════════════════════════════════════════════════════════════════════
// set-env — upsert one or more KEY=VALUE lines in .env (?vars=KEY1=val1,KEY2=val2)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'set-env') {
    $envF = $SITE_ROOT . '/.env';
    if (! is_file($envF)) {
        out(['action' => 'set-env', 'ok' => false, 'error' => '.env not found']);
    }
    $spec = (string) ($_GET['vars'] ?? '');
    if ($spec === '') {
        out(['action' => 'set-env', 'ok' => false, 'error' => 'pass ?vars=KEY=VALUE (comma-separate multiple)']);
    }

    $content = str_replace("\r", '', (string) file_get_contents($envF));
    $changed = [];
    foreach (explode(',', $spec) as $pair) {
        $pair = trim($pair);
        if (! str_contains($pair, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $pair, 2);
        $k = trim($k);
        if (! preg_match('/^[A-Z0-9_]+$/', $k)) {
            continue;
        }
        $line = $k . '=' . $v;
        if (preg_match('/^' . preg_quote($k, '/') . '=.*$/m', $content)) {
            $content = preg_replace('/^' . preg_quote($k, '/') . '=.*$/m', $line, $content);
        } else {
            $content = rtrim($content, "\n") . "\n" . $line . "\n";
        }
        $changed[] = $k;
    }
    file_put_contents($envF, $content);
    run('php artisan config:clear 2>&1', $SITE_ROOT);

    // Echo back the resulting values (secrets get masked).
    $result = [];
    foreach ($changed as $k) {
        if (preg_match('/^' . preg_quote($k, '/') . '=(.*)$/m', $content, $m)) {
            $val = trim($m[1], '"\' ');
            $result[$k] = (str_contains($k, 'KEY') || str_contains($k, 'SECRET') || str_contains($k, 'PASSWORD') || str_contains($k, 'TOKEN'))
                ? (strlen($val) ? '••••••••' : '(empty)')
                : $val;
        }
    }
    out(['action' => 'set-env', 'ok' => true, 'changed' => $result]);
}

// ═════════════════════════════════════════════════════════════════════════════
// cleanup — remove common dangerous helper files
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'cleanup') {
    $removed = [];
    foreach ([
        $SITE_ROOT . '/public/db_tool.php', $SITE_ROOT . '/public/raw_sql.php',
        $SITE_ROOT . '/public/adminer.php', $SITE_ROOT . '/public/info.php',
        $SITE_ROOT . '/public/phpinfo.php', $SITE_ROOT . '/unzip.php',
        $SITE_ROOT . '/public/unzip.php',
    ] as $f) {
        if (is_file($f)) {
            @unlink($f);
            $removed[] = basename($f);
        }
    }
    out(['action' => 'cleanup', 'ok' => true, 'removed' => $removed,
        'message' => $removed ? ('Removed: ' . implode(', ', $removed)) : 'Nothing to remove.']);
}

// ═════════════════════════════════════════════════════════════════════════════
// cmd — allowlisted, OR arbitrary with a second key (key2 must also match)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'cmd') {
    $command = trim((string) ($_GET['command'] ?? ''));
    if ($command === '' || strlen($command) > 500) {
        out(['action' => 'cmd', 'ok' => false, 'error' => 'empty or too long (max 500)']);
    }

    $allowedPrefixes = [
        'php artisan optimize:clear', 'php artisan migrate --force', 'php artisan migrate:status',
        'php artisan config:clear', 'php artisan cache:clear', 'php artisan queue:restart',
        'php artisan storage:link', 'php artisan whatsapp-web:status', 'php artisan about',
        'git status', 'git log', 'git branch',
        'composer install --no-dev',
    ];
    $isAllowlisted = false;
    foreach ($allowedPrefixes as $p) {
        if (str_starts_with($command, $p)) {
            $isAllowlisted = true;
            break;
        }
    }

    if (! $isAllowlisted) {
        // Arbitrary command — require the deploy key AGAIN as key2.
        if (! hash_equals($DEPLOY_KEY, (string) ($_GET['key2'] ?? ''))) {
            out(['action' => 'cmd', 'ok' => false,
                'error' => 'This command is not on the allowlist. Re-enter the deploy key to run an arbitrary command.']);
        }
    }

    $r = run($command . ' 2>&1', $SITE_ROOT);
    out(['action' => 'cmd', 'ok' => $r['exit'] === 0, 'exit' => $r['exit'],
        'command' => $command, 'output' => $r['stdout'], 'errors' => $r['stderr']]);
}

// ═════════════════════════════════════════════════════════════════════════════
// log — tail storage/logs/laravel.log
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'log') {
    $logF = $SITE_ROOT . '/storage/logs/laravel.log';
    $n = max(10, min(500, (int) ($_GET['lines'] ?? 200)));
    if (! is_file($logF)) {
        out(['action' => 'log', 'ok' => false, 'message' => 'log file not found']);
    }
    $lines = file($logF, FILE_IGNORE_NEW_LINES) ?: [];
    out(['action' => 'log', 'ok' => true, 'lines' => $n, 'log' => array_slice($lines, -$n)]);
}

// ═════════════════════════════════════════════════════════════════════════════
// selfdelete
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'selfdelete') {
    $ok = @unlink(__FILE__);
    out(['action' => 'selfdelete', 'ok' => $ok,
        'message' => $ok ? 'deploy.php removed from server.' : 'failed to delete.']);
}

out(['error' => 'unhandled action: ' . $action]);
