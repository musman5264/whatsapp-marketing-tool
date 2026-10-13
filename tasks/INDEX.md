# Task Board
_Last updated: 2026-10-13 13:11_

## Working
| ID | Task | Plan / Phase | Priority | Updated |
|----|------|--------------|----------|---------|
| T-0010 | Fetch WhatsApp media when it arrives, not on first view (live check pending) | PLAN-001 / phase-01 | high | 2026-10-13 01:24 |
| T-0012 | Page the conversation list and each chat, lazy-load older messages (live check pending) | PLAN-001 / phase-01 | high | 2026-10-13 01:24 |
| T-0013 | Stop chat jumping to bottom; reserve media space (live check pending) | PLAN-001 / phase-01 | medium | 2026-10-13 01:24 |

## Pending
| ID | Task | Plan / Phase | Depends on | Priority | Status |
|----|------|--------------|------------|----------|--------|
| T-0005 | Deploy to wa.esystematics.com | PLAN-000 / phase-01 | T-0001..3 | high | blocked: deploy access |
| T-0014 | Confirm live delivery and the 00:39 gap on the live server | PLAN-001 / phase-01 | none (user checks) | high | blocked: needs the user to run the three live checks (the code cannot reach the live server) |

## Completed (most recent first)
| ID | Task | Plan / Phase | Completed |
|----|------|--------------|-----------|
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

Next task ID: T-0020   (T-0006 and T-0007 are also in tasks/working from another session; IDs are never reused)
Next plan ID: PLAN-002
