# Dates and times shown to users (T-0067)

Written 2026-10-29. Built; **not tested (tests skipped by request) and not looked at in a browser: unverified.**

## What was wrong
- The page's timezone is `users.timezone`, whose column default is `UTC`, so anyone who never saved a timezone saw UTC (5 hours behind Pakistan). The inbox's `|| 'Asia/Dhaka'` fallback never ran because `'UTC'` is not empty.
- Times were formatted with the browser's language and no 12/24-hour choice, so AM/PM appeared or not depending on the browser.

## How it works now
- `users.time_format` (`12h` default, `24h`), migration `2026_10_29_120000_add_time_format_to_users_table.php` (run `php artisan migrate --force` after deploy). Saved from Settings (`SettingsController::update`), shared to every page as the Inertia prop `timeFormat` (`HandleInertiaRequests`).
- `resources/js/Utils/datetime.js`: `setTimeFormat()` keeps the choice in a module variable (called by `ClientLayout` on each render, before children format a time); `formatTimeTz`, `formatInTz` and `lib/date.js` `formatDateTime` pass it as `hour12`. `userTimezone(saved)` returns the saved timezone, or the device's timezone while it is the untouched default `UTC`. Used by the inbox list and chat (`Inbox/Index.jsx`, `Inbox/Show.jsx`).
- Settings page: a "Time format" select and a "Use this device's timezone" button next to the timezone picker.

## Known limits
- Other pages that call the helpers with a timezone of their own (campaign schedules, social posts) keep that timezone; only the 12/24-hour choice applies to them.
- Someone who really wants UTC cannot pick it for the inbox (it reads as "not set"). Marked `shortcut:` in `userTimezone`.
- `formatDateTz` (date only) has no time part and is unchanged.
