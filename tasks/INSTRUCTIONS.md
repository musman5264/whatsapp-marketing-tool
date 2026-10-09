# Tracker Instructions

**Main instructions: `CLAUDE.md` (project root, none yet) and global `~/.claude/CLAUDE.md`.** This file only holds tracker notes.

## Project overview
- Laravel + Inertia/Vue multi-channel inbox (WhatsApp Cloud + WAHA "WhatsApp Web", Instagram, Messenger, SMS, email), Pusher/Reverb broadcasting. (assumed from repo layout)
- Deploy: see memory "deploy-process" (commit + push main + curl deploy.php).
- Logic/feature docs: WAHA-EXPANSION-SPEC.md, WAHA-EXPANSION-PLAN.md, docs/waha-api-coverage.md

## Workflow (always)
1. Start: read CLAUDE.md, this file, tasks/INDEX.md, tasks/working/*.
2. New request: TEMP intake -> process skill -> tasks in tasks/pending.
3. Work one task at a time; done only after verification.
