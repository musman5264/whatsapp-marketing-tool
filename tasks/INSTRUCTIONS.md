# Tracker Instructions

**Main instructions: `CLAUDE.md` (project root) and global `~/.claude/CLAUDE.md`.** This file only holds tracker notes.

## Project overview
- Laravel + Inertia/Vue multi-channel inbox (WhatsApp Cloud + WAHA "WhatsApp Web", Instagram, Messenger, SMS, email), Pusher/Reverb broadcasting. (assumed from repo layout)
- Live site: https://wa.esystematics.com (Docker build). Deploy rules: CLAUDE.md > Git and deployment.
- Logic/feature docs: app/logic/README.md (index), WAHA-EXPANSION-SPEC.md, WAHA-EXPANSION-PLAN.md

## Workflow (always)
1. Start: read CLAUDE.md, this file, tasks/INDEX.md, tasks/working/*.
2. New request: TEMP intake -> process skill -> tasks in tasks/pending.
3. Work one task at a time; done only after verification.
