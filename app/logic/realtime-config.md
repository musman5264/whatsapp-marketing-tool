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

**Live events never throw into the inbound pipeline (T-0016):** the events above that broadcast immediately implement `Illuminate\Contracts\Broadcasting\ShouldRescue`, so a refused send (Pusher quota or size limit, Reverb down) is reported to the log and the listeners (automations) still run. Without it, Laravel sends the event to the broadcaster before its listeners and a refusal cancelled them. `WhatsappDriver::processInboundMessage` releases the duplicate-guard key only when no message row was stored, so a retry cannot store the same WhatsApp message twice. Test: `tests/Feature/Realtime/BroadcastFailureDoesNotBreakInboundTest.php`.

**Secrets (T-0015):** `SystemSetting::set()` sets `is_secret` before the value, so a secret saved for the first time is encrypted. A migration (`2026_10_13_120000_encrypt_plaintext_system_secrets`) encrypts secrets saved as plain text before this fix.

**Events on the chat channel:** `MessageReceived`, `MessageSent`, `MessageStatusUpdated`, `TypingChanged` and `MessageMediaReady` (`app/Events/MessageMediaReady.php`, sent once a WhatsApp Web media file is stored; carries `id`, `conversation_id`, `preview_url`, `mime_type`). All are synchronous (`ShouldBroadcastNow`) and need the live broadcast setup below.

**Live requirements:** `BROADCAST_CONNECTION=pusher` + `PUSHER_APP_ID/KEY/SECRET/CLUSTER` (or the admin Pusher settings), then `php artisan config:clear`.

**Tests:** `tests/Feature/Realtime/RealtimeConfigTest.php`, `RealtimeCspTest.php`, `ReverbSettingsTest.php`, `BroadcastFailureDoesNotBreakInboundTest.php`.

**Verified locally (not on the live site):** with Reverb on the local server, an open chat page connected to Reverb and a real inbound webhook appeared in it without a reload (same document, marker kept). Live site: unverified.
