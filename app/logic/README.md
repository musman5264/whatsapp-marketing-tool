# App logic docs

Tracked write-ups of what is **built and working now**. Update a file in place when you change the feature; add a row here when you add a file. Plans and unbuilt ideas do not belong here (see `tasks/plans/`, `WAHA-EXPANSION-*.md`).

| File | Covers |
|---|---|
| [realtime-config.md](realtime-config.md) | Pusher/Reverb single source of truth, CSP, why the inbox stops updating live |
| [waha-sync-and-labels.md](waha-sync-and-labels.md) | WAHA webhook events, phone-sent messages, history sync, unread, auto labels, contact save |
| [waha-api-coverage.md](waha-api-coverage.md) | Audit of WAHA endpoints vs what the app calls (copy of the old git-ignored `docs/` file; keep this one current) |
| [api-message-send.md](api-message-send.md) | Public `POST /api/v1/messages/send`: async WhatsApp send that waits for the result (200 sent / 422 failed / 202 queued), status endpoint, message.sent / message.failed webhooks |
| [api-contacts-upsert.md](api-contacts-upsert.md) | Public `POST /api/v1/contacts`: 201 new / 200 existing / restores deleted, no more 500 on repeated numbers |
| [inbox-paging.md](inbox-paging.md) | Conversation list and chat paging (lazy older messages, cursor, scroll rules), reaction row, media box sizes |
| [date-time-display.md](date-time-display.md) | Times shown to users: timezone fallback (device timezone while the saved one is the default UTC), 12h/24h setting, where the helpers live |
| [deployment-commands.md](deployment-commands.md) | Deployment page Run box: exact-match command allowlist (app and `public/deploy.php`), second key, no built-in deploy key |
| [private-media.md](private-media.md) | Chat media on the private disk: login route, signed links (WAHA 15 min), `media:make-private` dry run and `--apply`; `media:fix-links` repairs links saved with the integer conversation id |
| [webhook-deliveries.md](webhook-deliveries.md) | Outbound webhooks: exact body and headers stored per attempt, `X-Webhook-Signature` format, Deliveries page details, double-encoding fix |
| [automation-webhook-node.md](automation-webhook-node.md) | Automation "API Call" node (was Call Webhook): fields, what runs, response saved as variables, dotted paths, `{{#each}}` lists, poll options from a list, conditions on nested fields, QR-number caveats |
| [automation-ask-question.md](automation-ask-question.md) | Ask Question node: waiting for a reply, which message resumes it, why an unconnected Ask Question used to repeat the menu on every message (fixed) |
| [automation-import-export.md](automation-import-export.md) | Builder Import / Export button: JSON file (import again), Markdown description, secret header values redacted, import always makes a new draft in the current workspace |
| [epos-integration.md](epos-integration.md) | EPOS link API (`/api/v1/integrations`), manifest cache and validation, health/backoff, SSRF-safe outbound client, the E-POS flow step, and (T-0071) RentOyee as a second system plus per-client on/off switches |

The contract for the EPOS team is [EPOS-INTEGRATION-SPEC.md](../../EPOS-INTEGRATION-SPEC.md) (repo root).

## How to write one
Short sections: what it does, where the code is (file paths), the data flow, failure behaviour, how to test, known unverified points.
