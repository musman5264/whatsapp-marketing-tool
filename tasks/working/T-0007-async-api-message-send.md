# T-0007 — Async public API message send

- Plan / Phase: PLAN-000 / phase-01
- Origin: TEMP/intake-2026-10-12-1000.md
- Status: working

## Goal
`POST /api/v1/messages/send` must not wait on WAHA / Cloud API. Validate and record the message
synchronously, queue the provider call on the `whatsapp` queue, return `202` with the local message id.

## Rules that apply
- CLAUDE.md: WAHA calls from the inbound pipeline are soft-fail (not this path); never guess WAHA behaviour.
- CLAUDE.md: tests — `DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter=...`; one test process at a time.
- CLAUDE.md: new behaviour documented in `app/logic/`.
- CLAUDE.md: git — `git add <files>`, commit, push to main; `public/build` untouched (no resources/js change).

## Skills
- superpowers:systematic-debugging — done (root cause).
- superpowers:test-driven-development — to apply.
- superpowers:verification-before-completion — before "done".

## Checklist
- [ ] Job `SendWhatsappApiMessageJob` on `whatsapp` queue
- [ ] Controller returns 202 + `status: queued` + `message_id`
- [ ] Update MessageSendApiTest / MessageSendMediaApiTest expectations
- [ ] New test: provider failure marks message failed
- [ ] app/logic doc
- [ ] Commit + push, verify deploy by asset hash
