# Private WhatsApp media

## What it does
WhatsApp Web (WAHA) photos and files, and files sent from the compose bar, are stored on the **private** disk, never on the public disk. The inbox reads them only through the login check. Old copies from before this change were on the public disk, where anyone with the URL could open them. `php artisan media:make-private` moves those old copies to the private disk and points the message links at the login route.

## Where things are
| Piece | Where |
|---|---|
| Private disk `local` | `storage/app/private` (`config/filesystems.php`) |
| Public disk `public` | `storage/app/public`, served at `/storage/...` |
| Store an inbound WAHA file | `app/Modules/WhatsappWeb/Services/WahaMediaStore.php` `store()`, name `message-media/{id}.{ext}` |
| Compose-bar file | `app/Modules/Inbox/Http/Controllers/InboxController.php` (`reply`), name `message-media/{hash}.{ext}` on the private disk |
| Login route | `GET /app/inbox/conversations/{conversation}/messages/{message}/media`, name `client.inbox.message-media` (`app/Modules/Inbox/routes/web.php`) |
| Login route handler | `InboxController::serveMedia()` |
| Find a stored copy | `WahaMediaStore::existingPath()` |
| Login-route address | `WahaMediaStore::authenticatedUrl()` |
| Move command | `app/Modules/WhatsappWeb/Console/MakePrivateMediaCommand.php` (registered in `WhatsappWebServiceProvider`) |

## How files are served
- **Login route.** `serveMedia()` checks the user can see the conversation. If the stored copy exists, it streams it from the private disk (`WahaMediaStore::respond()`, `Cache-Control: private`). If the copy is missing, it clears `preview_url` and downloads the file from WAHA again.
- **Stored copy lookup.** `existingPath()` uses the payload's `media_path` when it is `message-media/{this message id}.{ext}`. Otherwise it derives the name from `mime_type`. Paths that leave the folder or name another message are ignored.
- **Public copy (old files).** `/storage/{path}` is served by the `public/storage` symlink when it exists, otherwise by `StorageFileController::show()`. Neither checks a login. Once a file is moved, its public URL returns 404.
- **Signed link for WAHA (15 minutes).** `WahaMediaStore::fetchUrl()` returns `Storage::disk('local')->temporaryUrl($path, now()->addMinutes(15))`. The URL looks like `/private-files/message-media/{name}?expires=...&signature=...`. Laravel's `storage.local` route (`serve => true` in `config/filesystems.php`, url `APP_URL/private-files`) serves it only with a valid signature. Used for outgoing WhatsApp Web files (`InboxController`, around line 225). The code comment says WAHA fetches the link once and it expires after 15 minutes. Whether WAHA always fetches within that time is **unverified live**.

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
- `tests/Feature/WhatsappWeb/MakePrivateMediaCommandTest.php` (10 tests): dry run changes nothing, copy and delete, links rewritten and other keys kept, `existingPath` finds the copy afterwards, second run is a no-op, different-size file not overwritten, files outside `message-media/` untouched, failed save keeps the public copy, hash-named outbound photo moves to its message id, orphan keeps its path.
- Status: the tests are written. Until they pass, treat the behaviour described here as **unverified**.

## Unverified
- Running it on the live site (wa.esystematics.com) is **unverified**. Nothing has been moved there by this change.
- Whether the `public/storage` symlink exists on the live host (decides whether old files are served by the web server or by `StorageFileController`) is **unverified**.
- Whether WAHA always fetches a signed `/private-files` link within its 15-minute window is **unverified live**.
- The nested `image.link` rewrite is a safety net. No live payload has been checked for a `/storage/message-media/` value there.
