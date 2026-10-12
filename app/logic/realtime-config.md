# Realtime (Pusher / Reverb)

**Code:** `app/Services/RealtimeConfig.php`; used by `SecureHeaders` (CSP `connect-src`), `HandleInertiaRequests::pusherPublicConfig()` (props for `resources/js/echo.js`) and `PusherSettingsServiceProvider`.

- `RealtimeConfig::resolve()` returns driver (`reverb|pusher|none`), key, cluster, `enabled` (frontend will connect) and `canBroadcast` (server has key + secret + app id).
- Admin Pusher credentials (SystemSetting) apply only when key, secret **and** app id are all set; otherwise env values are used.
- CSP allows `wss://` and `https://` for `ws-{cluster}.pusher.com`, `sockjs-{cluster}.pusher.com` (or the Reverb host) whenever the frontend would connect, plus `https://connect.facebook.net` when the Meta SDK is enabled.
- If the frontend would connect but the server cannot broadcast, a warning is logged at most once per hour.

**Events on the chat channel:** `MessageReceived`, `MessageSent`, `MessageStatusUpdated`, `TypingChanged` and `MessageMediaReady` (`app/Events/MessageMediaReady.php`, sent once a WhatsApp Web media file is stored; carries `id`, `conversation_id`, `preview_url`, `mime_type`). All are synchronous (`ShouldBroadcastNow`) and need the live broadcast setup below.

**Live requirements:** `BROADCAST_CONNECTION=pusher` + `PUSHER_APP_ID/KEY/SECRET/CLUSTER` (or the admin Pusher settings), then `php artisan config:clear`.

**Tests:** `tests/Feature/Realtime/RealtimeConfigTest.php`, `RealtimeCspTest.php`. Unverified: a real browser check on the live site.
