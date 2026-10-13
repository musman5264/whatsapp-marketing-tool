<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Keeps the Reverb WebSocket server running and reports on it.
 *
 * The scheduler (the cron entry that already runs every minute) calls watchdog(): while Reverb
 * is switched on in the admin panel and does not answer its health check, the watchdog starts it.
 * The admin page uses status() and launch() for the live status card and the Start button.
 */
class ReverbRuntime
{
    public const HEARTBEAT_KEY = 'reverb:watchdog';

    private const PID_FILE = 'app/reverb.pid';

    private const LOG_FILE = 'logs/reverb.log';

    private const LOG_TAIL_BYTES = 16384;

    private const LOG_TAIL_LINES = 12;

    public function listenHost(): string
    {
        $host = (string) (SystemSetting::get('reverb_listen_host') ?? '');

        return in_array($host, ['0.0.0.0', '127.0.0.1'], true) ? $host : '0.0.0.0';
    }

    public function listenPort(): int
    {
        $port = (int) (SystemSetting::get('reverb_listen_port') ?? 0);

        return $port > 0 && $port <= 65535 ? $port : 8080;
    }

    /** True when Reverb answers its health check on this server (GET /up, as Reverb serves it). */
    public function healthy(): bool
    {
        try {
            $response = Http::timeout(2)->get('http://127.0.0.1:'.$this->listenPort().'/up');

            return $response->successful() && $response->json('health') === 'OK';
        } catch (Throwable) {
            return false;
        }
    }

    /** Shell commands are needed to start Reverb in the background. Some shared hosts disable them. */
    public function execAvailable(): bool
    {
        if (! function_exists('shell_exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('shell_exec', $disabled, true);
    }

    /**
     * Starts `reverb:start` in the background and waits briefly for its health check.
     * Returns null when Reverb answers, otherwise the reason it does not.
     */
    public function launch(): ?string
    {
        // Without the saved app, the process would start with the .env values instead.
        if (RealtimeConfig::adminReverbCredentials() === null) {
            return 'Save the Reverb app ID, key, secret and host on the Reverb settings page first.';
        }

        if (! $this->execAvailable()) {
            return 'This host does not allow shell commands (exec is disabled), so Reverb cannot be started from here.';
        }

        $lock = Cache::lock('reverb:launch', 60);
        if (! $lock->get()) {
            return 'A start is already in progress.';
        }

        try {
            if ($this->healthy()) {
                return null;
            }

            $command = sprintf(
                'cd %s && S=$(command -v setsid || true); $S nohup %s %s reverb:start --host=%s --port=%d >> %s 2>&1 < /dev/null & echo $!',
                escapeshellarg(base_path()),
                escapeshellarg(CliPhpBinary::path()),
                escapeshellarg(base_path('artisan')),
                escapeshellarg($this->listenHost()),
                $this->listenPort(),
                escapeshellarg(storage_path(self::LOG_FILE)),
            );

            $pid = (int) trim((string) shell_exec($command));
            if ($pid <= 0) {
                return 'The start command did not return a process id.';
            }

            file_put_contents(storage_path(self::PID_FILE), json_encode([
                'pid' => $pid,
                'started_at' => now()->toIso8601String(),
            ]));

            for ($i = 0; $i < 8; $i++) {
                sleep(1);
                if ($this->healthy()) {
                    return null;
                }
            }

            return 'Reverb was started (pid '.$pid.') but did not answer within 8 seconds. See the log below.';
        } finally {
            $lock->release();
        }
    }

    /** What the scheduler does every minute. Returns the result it recorded. */
    public function watchdog(): array
    {
        if (! RealtimeConfig::reverbActive()) {
            return $this->record('skipped', 'Reverb is off, so nothing needs to run.');
        }

        if ($this->healthy()) {
            return $this->record('running', 'Reverb answers on port '.$this->listenPort().'.');
        }

        if (! $this->autostart()) {
            return $this->record('waiting', 'Reverb is switched on but not running, and automatic start is off. Press Start now.');
        }

        $error = $this->launch();

        return $error === null
            ? $this->record('started', 'Reverb was not running, so the watchdog started it.')
            : $this->record('error', $error);
    }

    /** The "Start Reverb automatically" switch. On unless the admin turned it off. */
    public function autostart(): bool
    {
        return (string) (SystemSetting::get('reverb_autostart') ?? 'true') !== 'false';
    }

    /**
     * Stops the Reverb process this panel started (its pid is in the pid file).
     * Returns null when it is stopped, or when nothing was running; otherwise the reason.
     */
    public function stop(): ?string
    {
        $pid = (int) ($this->pidMeta()['pid'] ?? 0);

        if ($pid <= 0) {
            return $this->healthy()
                ? 'Reverb was started outside this panel, so it has to be stopped on the server.'
                : null;
        }

        if ($this->processAlive($pid)) {
            $this->terminate($pid);
            for ($i = 0; $i < 10 && $this->healthy(); $i++) {
                usleep(500000);
            }
            if ($this->healthy()) {
                return 'Reverb did not stop after the termination signal. Check the process on the server.';
            }
        }

        @unlink(storage_path(self::PID_FILE));

        return null;
    }

    /**
     * True when the public address answers Reverb's health check, which is what a browser needs.
     * Null when no host is set. Cached for a minute, so the status poll stays light.
     */
    public function publicReachable(): ?bool
    {
        $host = (string) (SystemSetting::get('reverb_host') ?? '');
        if ($host === '') {
            return null;
        }

        $port = (int) (SystemSetting::get('reverb_port') ?? 0) ?: 443;
        $scheme = (string) (SystemSetting::get('reverb_scheme') ?? 'https') === 'http' ? 'http' : 'https';

        return Cache::remember('reverb:public-check:'.$scheme.'://'.$host.':'.$port, 60, function () use ($scheme, $host, $port) {
            try {
                $response = Http::timeout(4)->get($scheme.'://'.$host.':'.$port.'/up');

                return $response->successful() && $response->json('health') === 'OK';
            } catch (Throwable) {
                return false;
            }
        });
    }

    private function processAlive(int $pid): bool
    {
        return function_exists('posix_kill') && posix_kill($pid, 0);
    }

    private function terminate(int $pid): void
    {
        if (function_exists('posix_kill')) {
            posix_kill($pid, 15); // SIGTERM

            return;
        }

        shell_exec('kill '.$pid);
    }

    /** Snapshot for the admin status card (no secrets). */
    public function status(): array
    {
        $meta = $this->pidMeta();
        $startedAt = $meta['started_at'] ?? null;

        return [
            'active' => RealtimeConfig::reverbActive(),
            'exec_available' => $this->execAvailable(),
            'healthy' => $this->healthy(),
            'autostart' => $this->autostart(),
            'public_reachable' => $this->publicReachable(),
            'pid' => $meta['pid'] ?? null,
            'started_at' => $startedAt,
            'uptime_seconds' => $startedAt ? max(0, (int) now()->diffInSeconds($startedAt)) : null,
            'listen' => $this->listenHost().':'.$this->listenPort(),
            'last_watchdog' => Cache::get(self::HEARTBEAT_KEY),
            'log_tail' => $this->logTail(),
        ];
    }

    /** Asks a running Reverb server to restart; the watchdog starts it again with the new settings. */
    public function requestRestart(): void
    {
        try {
            Artisan::call('reverb:restart');
        } catch (Throwable) {
            // Cache or command unavailable: the watchdog still starts Reverb if it stops.
        }
    }

    /** Records the result of a start from the admin page, so the status card can show it. */
    public function recordManualStart(?string $error): void
    {
        $this->record(
            $error === null ? 'started' : 'error',
            $error ?? 'Started manually.',
        );
    }

    /** @return array{result: string, message: string, at: string} */
    private function record(string $result, string $message): array
    {
        $entry = ['result' => $result, 'message' => $message, 'at' => now()->toIso8601String()];
        Cache::forever(self::HEARTBEAT_KEY, $entry);

        return $entry;
    }

    /** @return array{pid?: int, started_at?: string} */
    private function pidMeta(): array
    {
        $file = storage_path(self::PID_FILE);
        if (! is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /** The last lines of the Reverb log, without terminal colour codes. */
    private function logTail(): array
    {
        $file = storage_path(self::LOG_FILE);
        if (! is_file($file)) {
            return [];
        }

        $size = (int) filesize($file);
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        fseek($handle, max(0, $size - self::LOG_TAIL_BYTES));
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);

        $lines = preg_split('/\r?\n/', trim($chunk)) ?: [];

        return array_values(array_map(
            fn (string $line) => preg_replace('/\x1b\[[0-9;]*m/', '', $line),
            array_slice($lines, -self::LOG_TAIL_LINES),
        ));
    }
}
