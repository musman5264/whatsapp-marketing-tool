# App logic docs

Tracked write-ups of what is **built and working now**. Update a file in place when you change the feature; add a row here when you add a file. Plans and unbuilt ideas do not belong here (see `tasks/plans/`, `WAHA-EXPANSION-*.md`).

| File | Covers |
|---|---|
| [realtime-config.md](realtime-config.md) | Pusher/Reverb single source of truth, CSP, why the inbox stops updating live |
| [waha-sync-and-labels.md](waha-sync-and-labels.md) | WAHA webhook events, phone-sent messages, history sync, unread, auto labels, contact save |
| [waha-api-coverage.md](waha-api-coverage.md) | Audit of WAHA endpoints vs what the app calls (copy of the old git-ignored `docs/` file; keep this one current) |

## How to write one
Short sections: what it does, where the code is (file paths), the data flow, failure behaviour, how to test, known unverified points.
