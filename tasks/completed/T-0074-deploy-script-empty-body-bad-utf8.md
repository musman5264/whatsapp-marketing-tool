---
id: T-0074
title: Deploy script sent an empty body (HTTP 200) when a command's output was not valid UTF-8
status: completed
plan: PLAN-000
phase: phase-01
priority: high
depends_on: none
started: 2026-11-24 15:33
completed: 2026-11-24 16:05
---

## Root cause (systematic-debugging, phases 1-2)
`public/deploy.php` `out()` echoed `json_encode($data)`. For output with invalid UTF-8 json_encode returns `false`, and `echo false` sends nothing: HTTP 200, empty body, which DeployClient reported as "non-JSON response". Reproduced by test (body NULL before the fix). UNVERIFIED on live: which command was log 192 and whether this was its cause (a PHP fatal with errors hidden looks the same).

## Acceptance criteria
- [x] Invalid UTF-8 command output still returns JSON. Check: DeployScriptTest::command_output_with_invalid_utf8_still_returns_json (red, then green).
- [x] Empty body gets its own plain message in DeployClient. Check: code only (no test).
- [ ] Live: deploy.php on the TARGET server updated, then rerun the failed command. Needs the user.

## Rules from CLAUDE.md
- [x] Logic doc updated (app/logic/deployment-commands.md); commit by name; no secrets.

## Skills
- [x] task-tracker, [x] systematic-debugging, [x] test-driven-development, [x] verification-before-completion (tests/Feature/Deployment: 99 green)

## Result
Files: public/deploy.php, DeployClient.php, DeployScriptTest.php, deployment-commands.md. The script fix only works once the copy on the target server is updated.
