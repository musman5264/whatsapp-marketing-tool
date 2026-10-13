# Task Board
_Last updated: 2026-10-13 17:56_

## Working
| ID | Task | Plan / Phase | Priority | Updated |
|----|------|--------------|----------|---------|
| T-0010 | Fetch WhatsApp media when it arrives, not on first view (live check pending) | PLAN-001 / phase-01 | high | 2026-10-13 01:24 |
| T-0012 | Page the conversation list and each chat, lazy-load older messages (live check pending) | PLAN-001 / phase-01 | high | 2026-10-13 01:24 |
| T-0013 | Stop chat jumping to bottom; reserve media space (live check pending) | PLAN-001 / phase-01 | medium | 2026-10-13 01:24 |
| T-0029 | Move old public chat media to the private disk with media:make-private (dry run first) | PLAN-002 / phase-01 | high | 2026-10-13 17:55 |
| T-0030 | Webhook: send the WhatsApp photo or file as a signed link that expires | PLAN-002 / phase-01 | high | 2026-10-13 17:55 |
| T-0031 | Outgoing WhatsApp Web image shows 404 after 5e7137e: random file name the login route cannot find | PLAN-002 / phase-01 | high | 2026-10-13 18:01 |

## Pending
| ID | Task | Plan / Phase | Depends on | Priority | Status |
|----|------|--------------|------------|----------|--------|
| T-0005 | Deploy to wa.esystematics.com | PLAN-000 / phase-01 | T-0001..3 | high | blocked: deploy access |
| T-0014 | Confirm live delivery and the 00:39 gap on the live server | PLAN-001 / phase-01 | none (user checks) | high | blocked: needs the user to run the three live checks (the code cannot reach the live server) |
| T-0022 | composer.lock out of date with composer.json | PLAN-000 / phase-01 | none | medium | not started |
| T-0023 | Run Reverb on the Easypanel VPS; cPanel uses it as an external realtime server | PLAN-000 / phase-01 | T-0021 | high | blocked: your VPS and Cloudflare setup (code done, commit 4a851b3) |
| T-0026 | WAHA webhooks rejected: checked SHA-256, WAHA signs SHA-512; live messages never arrived | PLAN-000 / phase-01 | T-0023 | high | blocked: deploy 3da12df and live check |
| T-0028 | Private media storage (public photo links) and media in the outgoing webhook | PLAN-002 / phase-01 | T-0027, T-0029, T-0030 | high | blocked: your deploy of 5e7137e with T-0030 and T-0031, the command run, and the live check |

## Completed (most recent first)
| ID | Task | Plan / Phase | Completed |
|----|------|--------------|-----------|
| T-0027 | Images not shown: storage link and photo type mapping; incoming and outgoing images verified live | PLAN-000 / phase-01 | 2026-10-13 17:35 |
| T-0025 | Graphify output committed and pushed to origin main (4791e38) | PLAN-000 / phase-01 | 2026-10-13 14:29 |
| T-0024 | Build the graphify knowledge graph of the repo (code, no API key) | PLAN-000 / phase-01 | 2026-10-13 14:03 |
| T-0021 | Reverb not reachable from browsers on cPanel (cancelled: Reverb moves to a VPS, T-0023) | PLAN-000 / phase-01 | 2026-10-13 13:55 |
| T-0020 | Security: exact-match command allowlist, no built-in deploy key, Reverb starts with the command-line PHP | PLAN-000 / phase-01 | 2026-10-13 13:40 |
| T-0019 | Reverb: manual start from the Deployment commands and a Start button on the Reverb page | PLAN-000 / phase-01 | 2026-10-13 13:11 |
| T-0018 | Reverb: turning it on starts it; automatic start switch; turning it off stops it; browser reachability | PLAN-000 / phase-01 | 2026-10-13 12:52 |
| T-0017 | Reverb watchdog in the scheduler starts Reverb while it is switched on; live status on the Reverb page | PLAN-000 / phase-01 | 2026-10-13 12:39 |
| T-0016 | Live sync: a refused realtime send no longer cancels automations or stores a message twice | PLAN-000 / phase-01 | 2026-10-13 12:13 |
| T-0015 | Super-admin Reverb settings: auto configure, test, on/off toggle (local-verified; live deploy pending) | PLAN-000 / phase-01 | 2026-10-13 12:13 |
| T-0011 | Media lookup checks one path, not the whole folder | PLAN-001 / phase-01 | 2026-10-13 01:24 |
| T-0009 | message.received payload carries the sender number and media type (for PMS ticket replies) | PLAN-000 / phase-01 | 2026-10-13 00:21 |
| T-0008 | Show the exact body and headers of each WhatsMine webhook delivery (also fixes the double-encoded body) | PLAN-000 / phase-01 | 2026-10-13 00:08 |
| T-0006 | CANCELLED: temporary WhatsMine capture endpoint, removed from this repo (kept in esystematics, commit 2b143de) | PLAN-000 / phase-01 | 2026-10-12 22:58 |
| T-0004 | Project CLAUDE.md from mnrdev | PLAN-000 / phase-01 | 2026-10-09 16:35 |
| T-0003 | Auto labels + contact save | PLAN-000 / phase-01 | 2026-10-09 17:00 |
| T-0002 | WAHA sync core | PLAN-000 / phase-01 | 2026-10-09 17:00 |
| T-0001 | Realtime CSP + WAHA media | PLAN-000 / phase-01 | 2026-10-09 17:00 |

## Plans
| Plan | Goal | Current phase | Tasks done / total |
|------|------|---------------|--------------------|
| PLAN-000-general | One-off tasks | phase-01-ongoing | 0 / 0 |
| PLAN-001-inbox-speed-and-media | Inbox speed, live updates and media | phase-01-speed-and-media | 1 / 5 |
| PLAN-002-private-media | Chat media off the public disk; signed webhook media | phase-01-private-media | 0 / 3 |

Next task ID: T-0032   (T-0006 and T-0007 are also in tasks/working from another session; IDs are never reused)
Next plan ID: PLAN-003
