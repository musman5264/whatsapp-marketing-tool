# Private WhatsApp media

## What it does
WhatsApp Web (WAHA) photos and files, and files sent from the compose bar, are stored on the **private** disk, never on the public disk. The inbox reads them only through the login check. Old copies from before this change were on the public disk, where anyone with the URL could open them. `php artisan media:make-private` moves those old copies to the private disk and points the message links at the login route.

## Where things are
| Piece | Where |
|---|---|
| Private disk `local` | `storage/app/private` (`config/filesystems.php`) |
| Public disk `public` | `storage/app/public`, served at `/storage/...` |
| Store an inbound WAHA file | `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` `store()`, name `message-media/{id}.{ext}` |
| Compose-bar file | `app/Modules/Inbox/Http/Controllers/InboxController.php` (`reply`). Saved as `message-media/{hash}.{ext}`, then moved to `message-media/{message id}.{ext}` in the same transaction as the message row (see Outgoing composer files). |
| Login route | `GET /app/inbox/conversations/{conversation}/messages/{message}/media`, name `client.inbox.message-media` (`app/Modules/Inbox/routes/web.php`). `{conversation}` is the conversation's **uuid** (its route key), not its integer id. |
| Login route handler | `InboxController::serveMedia()` |
| Find a stored copy | `WahaMediaStore::existingPath()` |
| Login-route address | `WahaMediaStore::authenticatedUrl()`. Built from the conversation model, so the link carries the conversation's **uuid**. |
| Move command | `app/Modules/WhatsappWeb/Console/MakePrivateMediaCommand.php` (registered in `WhatsappWebServiceProvider`) |

## How files are served
- **Login route.** `serveMedia()` checks the user can see the conversation. If the stored copy exists, it streams it from the private disk (`WahaMediaStore::respond()`, `Cache-Control: private`). If the copy is missing, it clears `preview_url` and downloads the file from WAHA again.
- **Stored copy lookup.** `existingPath()` uses the payload's `media_path` when it is `message-media/{this message id}.{ext}`. Otherwise it derives the name from `mime_type`. Paths that leave the folder or name another message are ignored.
- **Public copy (old files).** `/storage/{path}` is served by the `public/storage` symlink when it exists, otherwise by `StorageFileController::show()`. Neither checks a login. Once a file is moved, its public URL returns 404.
- **Signed link for WAHA (15 minutes).** `WahaMediaStore::fetchUrl()` returns `Storage::disk('local')->temporaryUrl($path, now()->addMinutes(15))`. The URL looks like `/private-files/message-media/{name}?expires=...&signature=...`. Laravel's `storage.local` route (`serve => true` in `config/filesystems.php`, url `APP_URL/private-files`) serves it only with a valid signature. Used for outgoing WhatsApp Web files: `reply()` calls it after the file has been moved to its message-id name (see Outgoing composer files). The code comment says WAHA fetches the link once and it expires after 15 minutes. Whether WAHA always fetches within that time is **unverified live**.

## Outgoing composer files (T-0031)
When an agent attaches a file in the compose bar, `reply()` in `InboxController`:
1. Saves the upload under its random name on the private disk, `message-media/{hash}.{ext}`.
2. Opens a database transaction and creates the message row. It then moves the file to `message-media/{message id}.{ext}` (same extension) and saves the payload with:
   - `media_path`: the new path.
   - `preview_url`: the login-route address (`WahaMediaStore::authenticatedUrl()`). The chat shows the image from it, and `serveMedia()` streams the file.
   - `link` (WhatsApp Web only): a 15-minute signed link (`WahaMediaStore::fetchUrl()`), made after the move. Cloud API messages keep `media_id` and get no `link`.
3. Sends the message after the transaction, as before. A failed send keeps the message and the file, with status `failed`.

If the move fails, the transaction rolls back, the random-name file is deleted, and the reply returns an error (JSON 500 for the composer, which asks for JSON; a flash error otherwise). No message is left without its file. A failure after the move, such as a database error on the final save, rolls back the message and leaves the file under its message-id name; that case is not cleaned up.

The signed `link` is saved on the payload row, and the reply JSON and the `MessageSent` broadcast carry the whole payload, so the link is also sent to the browser and to the channel. This is how the code was before T-0031, and it is an open decision (see Unverified).

## The move command
```
php artisan media:make-private            # dry run: prints the plan, changes nothing
php artisan media:make-private --apply    # moves the files and rewrites the links
```

**What it looks at.** Every file under `message-media/` on the public disk. Nothing else on the public disk is touched.

**Matching a file to a message** (first rule that matches):
1. The file name is `{id}.{ext}` and message `{id}` exists. This is the inbound photo case.
2. Some message's `preview_url`, `link`, or a type key's `link` (for example `image.link`) contains `/storage/message-media/{file name}`. This is the composer-image case, where the name is a hash.
3. Otherwise the file is an **orphan** and keeps its own path.

A file that matches more than one message is a conflict and is left in place. So is a message that would get two different files.

**Target path.** A file with a message goes to `message-media/{message id}.{ext}`, with the extension lower-cased (`bin` if it has none). Orphans keep their own path.

**Dry run.** One line per planned move, then one line per message whose links would change, then conflicts, then a total:
```
message-media/9f2c...e6f.jpg -> message-media/12.jpg (message 12)
message-media/c4e8...5b8e.jpg -> message-media/c4e8...5b8e.jpg (orphan)
rewrite links of message 12 to the login route
Found 2 file(s): 2 can move, 0 conflict(s) left in place. 1 message(s) will have their links rewritten. Nothing was changed. Run with --apply to move them.
```
Exit code 1 when there is any conflict, otherwise 0.

**Apply, per file:**
1. If the private disk already has the target with the same size, the copy is skipped. If it has a different size, the file is a conflict: nothing is copied, the public copy stays, and the message is not touched.
2. Otherwise the file is copied by stream to the private disk and the size is checked. A mismatched copy is deleted again and the public copy kept (error).
3. For a file with a message, one database transaction (row locked) sets `media_path` to the target. It replaces `preview_url`, `link` and any type-key `link` that contain `/storage/message-media/` with the login-route address. Every other payload key is left as it was. It saves through Eloquent, so model events run. A failure rolls back and the public copy is kept.
4. The public copy is deleted only after steps 2 and 3 succeeded.

**Summary.** `Done: N file(s) moved, N message(s) rewritten, N conflict(s), N error(s).` Exit 1 if there was any conflict or error. Errors are written to the log under `whatsapp_web.media.make_private_failed`; the screen shows a short line only.

**Running it twice.** The second run finds no public copies of the moved files, so it changes nothing and exits 0.

**Cloud storage.** If a cloud storage provider is active (`StorageManager::diskName()` is not `public`), the command prints an error and exits 1 without changing anything.

**Where to run it.** It is not in the no-key allowlist (`CommandAllowlist::ARTISAN`). Run it from the cPanel terminal, or from the Deployment Run box with the second key. See [deployment-commands.md](deployment-commands.md).

## Failure behaviour
- Copy or database failure for one file: the public copy is kept, the database change for that file is rolled back, the other files continue, and the exit code is 1.
- Failure to delete the public copy after the links were changed: the links already point at the login route (which serves the private copy), and the public copy stays until the next run.
- A private copy left over from a failed database step is reused by the next `--apply` (same size, so no second copy).
- A different-size file on the private disk is never overwritten.

## How to test
- `tests/Feature/Inbox/OutboundMediaNamingTest.php` (6 tests, T-0031): a WhatsApp Web composer image is stored under its message id; the payload has `media_path` and the login-route `preview_url`; the login route serves the bytes to a workspace user; the WAHA send gets a signed link to that file; the Cloud path keeps `media_id`; a failed WAHA send keeps the file. Run status is in the T-0031 task file.
- `tests/Feature/WhatsappWeb/MakePrivateMediaCommandTest.php` (10 tests): dry run changes nothing, copy and delete, links rewritten and other keys kept, `existingPath` finds the copy afterwards, second run is a no-op, different-size file not overwritten, files outside `message-media/` untouched, failed save keeps the public copy, hash-named outbound photo moves to its message id, orphan keeps its path.
- Status (2026-10-14): `MakePrivateMediaCommandTest` (10 tests) passes on the private test database. Live runs are not done (see the Unverified list).

## Unverified
- Running it on the live site (wa.esystematics.com) is **unverified**. Nothing has been moved there by this change.
- Whether the `public/storage` symlink exists on the live host (decides whether old files are served by the web server or by `StorageFileController`) is **unverified**.
- Whether WAHA always fetches a signed `/private-files` link within its 15-minute window is **unverified live**.
- The nested `image.link` rewrite is a safety net. No live payload has been checked for a `/storage/message-media/` value there.
- Outgoing composer naming (T-0031): `OutboundMediaNamingTest` passes (2026-10-14, private test database). Not verified on the live site.
- **Open decision:** CLAUDE.md says the signed WAHA link must not be stored or exposed outside the send. `reply()` still saves it on the payload, returns it in the reply JSON, and `MessageSent` broadcasts it. The tests read `payload['link']` from the database, so this was not changed in T-0031.
