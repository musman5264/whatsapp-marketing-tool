# Realtime (Pusher / Reverb)

**Code:** `app/Services/RealtimeConfig.php`; used by `SecureHeaders` (CSP `connect-src`), `HandleInertiaRequests::pusherPublicConfig()` (props for `resources/js/echo.js`) and `PusherSettingsServiceProvider`.

- `RealtimeConfig::resolve()` returns driver (`reverb|pusher|none`), key, cluster, `enabled` (frontend will connect) and `canBroadcast` (server has key + secret + app id).
- Admin Pusher credentials (SystemSetting) apply only when key, secret **and** app id are all set; otherwise env values are used.
- CSP allows `wss://` and `https://` for `ws-{cluster}.pusher.com`, `sockjs-{cluster}.pusher.com` (or the Reverb host) whenever the frontend would connect, plus `https://connect.facebook.net` when the Meta SDK is enabled.
- If the frontend would connect but the server cannot broadcast, a warning is logged at most once per hour.

**Reverb from the admin panel (T-0015):** Admin → Reverb Settings (`/admin/reverb-settings`, `manage_settings`). Saved values are copied into the runtime config by `app/Providers/ReverbSettingsServiceProvider.php` on every request and in the Reverb server process (the server only accepts the app it has in its config). Keys: `reverb_app_id`, `reverb_app_key`, `reverb_app_secret` (encrypted), `reverb_host`, `reverb_port`, `reverb_scheme`, and the switch `realtime_driver` (`auto` | `reverb`).
- **Generate** (`ReverbSettingsController::generate`) creates random values; nothing is saved until **Save**.
- **Test** (`ReverbConnectionTester`) sends one event through Reverb's HTTP API with the saved credentials. It checks server-to-Reverb only, not the browser WebSocket.
- **Turn on** (`toggle`, `enabled=true`) needs complete credentials **and** a passing test in the same request; then `realtime_driver=reverb`, and a `reverb:restart` signal is sent (the running server reloads its credentials). **Turn off** sets `auto` again and realtime returns to Pusher or `.env`.
- `RealtimeConfig::resolve()` uses Reverb first when it is active; Reverb overrides Pusher when both are set (the provider is registered after Pusher).
- **cPanel:** Reverb is a long-running process. Shared cPanel hosting only runs cron, so Reverb usually cannot run there. Run it on a VPS (`php artisan reverb:start --host=0.0.0.0 --port=8080`, under systemd or supervisor) and forward WebSocket traffic for `/app` and `/apps` to it from the host you enter. Otherwise keep hosted Pusher.
- Tests: `tests/Feature/Realtime/ReverbSettingsTest.php` (permission, masking, encryption at rest, toggle rules, provider, restart signal, failure message).

**Reverb watchdog and status (T-0017):** `app/Services/ReverbRuntime.php`.
- `reverb:ensure-running` (`app/Console/Commands/ReverbEnsureRunning.php`) is scheduled every minute in `routes/console.php` (`reverb-watchdog`, `withoutOverlapping`). It runs on the cron entry that already runs `schedule:run`. Reverb off: nothing. Reverb answering `GET /up` on the listen port: nothing. Otherwise it starts Reverb. The result is kept in the cache (`reverb:watchdog`) and shown on the page.
- Start: `setsid nohup php artisan reverb:start --host=<listen host> --port=<listen port>` (`shell_exec`), output to `storage/logs/reverb.log`, pid and start time in `storage/app/reverb.pid`. A cache lock stops two starts at once. The start waits up to 8 seconds for `/up`.
- Host without shell commands (`exec` disabled): the page says so and nothing is attempted.
- Restart after a credential change: the restart signal makes the running server exit; the next watchdog run starts it with the new settings.
- Settings: `reverb_listen_host` (`0.0.0.0` or `127.0.0.1`, default `0.0.0.0`), `reverb_listen_port` (default `8080`).
- Page: status card (running or stopped, pid, started, uptime, listen address, last watchdog check, log tail), refreshed every 10 seconds from `GET /admin/reverb-settings/status`. Start now (`POST .../start`) and Restart (`POST .../restart`).
- Limits, to verify on the host: a process started from cron must survive the cron run and any host process limits; browsers must reach the listen port (or a proxy) over WSS. The start path is tested with a fake launcher; on Windows only its failure path was run.
- **Switch on** (T-0018): starts Reverb first (`ReverbRuntime::launch`), then runs the connection test, then sets `realtime_driver=reverb`. If it cannot start or the test fails, realtime stays as it was and the reason is shown.
- **Automatic start** (`reverb_autostart`, default on): the watchdog starts a stopped Reverb only when this is on. Off: the watchdog reports `waiting` and Reverb runs only when someone presses Start now. The switch saves straight away.
- **Switch off** (T-0018): switches realtime back and stops the Reverb process this panel started (pid file, SIGTERM). A Reverb started by hand on the server has to be stopped there.
- **Browsers can reach it**: `ReverbRuntime::publicReachable()` asks the public host and port for `/up` (cached 60 s). No means the proxy for `/up` and the WebSocket paths is missing, or the port is blocked.
- Tests: `tests/Feature/Realtime/ReverbWatchdogTest.php`, `ReverbSettingsTest.php`.

**Manual start and the Deployment command (T-0019):** the **Start now** button on the Reverb page shows when the credentials are saved, Reverb does not answer and shell commands are allowed. It also shows while the Reverb switch is off. It starts the same process as the watchdog (`ReverbRuntime::launch()`) and does not switch realtime over.
- The same start is the command `php artisan reverb:launch` (`app/Console/Commands/ReverbLaunch.php`). It starts Reverb in the background and exits within about 8 seconds, so the Deployment command runner can wait for it. The package command `reverb:start` is not used there: it runs in the foreground until stopped, and the runner would never get an answer.
- The command is on three allowlists, which must name it alike: `DeploymentController::runCommand()`, the quick-command list on the Deployment page, and `public/deploy.php` (`$allowedPrefixes`). The remote script needs its entry too, or the server asks for the deploy key again.
- A start with credentials saved loads them into the Reverb server config even when the switch is off (`ReverbSettingsServiceProvider::apply()` never waited for the switch). Only the broadcaster default follows the switch.
- `launch()` refuses to start without complete credentials, before any shell command runs.
- Limits, to verify on the host: `launch()` runs `PHP_BINARY`. Under some web PHP handlers that is not the CLI binary, so the Start button may fail where the command works. Tests: `tests/Feature/Realtime/ReverbLaunchCommandTest.php`, `ReverbWatchdogTest` (start while off, refusal), `tests/Feature/Deployment/RollbackAndCommandTest.php`.

**Live events never throw into the inbound pipeline (T-0016):** the events above that broadcast immediately implement `Illuminate\Contracts\Broadcasting\ShouldRescue`, so a refused send (Pusher quota or size limit, Reverb down) is reported to the log and the listeners (automations) still run. Without it, Laravel sends the event to the broadcaster before its listeners and a refusal cancelled them. `WhatsappDriver::processInboundMessage` releases the duplicate-guard key only when no message row was stored, so a retry cannot store the same WhatsApp message twice. Test: `tests/Feature/Realtime/BroadcastFailureDoesNotBreakInboundTest.php`.

**Secrets (T-0015):** `SystemSetting::set()` sets `is_secret` before the value, so a secret saved for the first time is encrypted. A migration (`2026_10_13_120000_encrypt_plaintext_system_secrets`) encrypts secrets saved as plain text before this fix.

**Events on the chat channel:** `MessageReceived`, `MessageSent`, `MessageStatusUpdated`, `TypingChanged` and `MessageMediaReady` (`app/Events/MessageMediaReady.php`, sent once a WhatsApp Web media file is stored; carries `id`, `conversation_id`, `preview_url`, `mime_type`). All are synchronous (`ShouldBroadcastNow`) and need the live broadcast setup below.

**Live requirements:** `BROADCAST_CONNECTION=pusher` + `PUSHER_APP_ID/KEY/SECRET/CLUSTER` (or the admin Pusher settings), then `php artisan config:clear`.

**Tests:** `tests/Feature/Realtime/RealtimeConfigTest.php`, `RealtimeCspTest.php`, `ReverbSettingsTest.php`, `BroadcastFailureDoesNotBreakInboundTest.php`.

**Verified locally (not on the live site):** with Reverb on the local server, an open chat page connected to Reverb and a real inbound webhook appeared in it without a reload (same document, marker kept). Live site: unverified.
