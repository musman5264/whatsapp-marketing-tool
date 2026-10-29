---
id: T-0065
title: EPOS alert.push: send kind order/complaint (enum kept, checked, dropdown, complaint example flow)
status: completed
priority: high
type: feature
plan: PLAN-000 (general)
phase: phase-01-ongoing
depends_on: []
source: TEMP/intake-2026-10-29-1650.md
created: 2026-10-29 17:10
started: 2026-10-29 17:10
updated: 2026-10-29 18:10
completed: 2026-10-29 18:10
---

# T-0065: EPOS alert.push: send kind order/complaint (enum kept, checked, dropdown, complaint example flow)

Design: keep manifest param enum; EposExecutor refuses a value outside the enum (reason invalid, no call); E-POS node shows a dropdown for enum params; epos-complaint-alert.flow.json + kind=order in the order flow; tests; update app/logic/epos-integration.md. Skipped: complaints_enabled (unused by the tool). Spec: TOOL-complaint-alert.md.

## Rules from CLAUDE.md
- [ ] Never guess: verify against the code; live behaviour stays "unverified" until checked on the running system
- [ ] Test first; PHPUnit run with the private DB / empty password / memory_limit=1G; never two test processes at once
- [ ] New UI strings in resources/js/locales/en.json; UI checked at 360px and 1920px, light and dark; times from user/workspace settings
- [ ] Logic written to app/logic/ (update in place)
- [ ] git add by file name; commit; if resources/js changed run npm run build and commit public/build separately; push only when the user asks; no deploy claim without the asset-hash check
- [ ] WAHA calls soft-fail; new WAHA endpoint checked against the WAHA docs

## Logic / design docs
- app/logic/README.md, plus the file named in the task body

## Skills
- [x] superpowers:brainstorming / systematic-debugging (intake step 2)
- [x] superpowers:test-driven-development (T-0065, T-0068 test first; T-0066/67 skipped on the user's instruction)
- [ ] superpowers:verification-before-completion
- [ ] code-reviewer / senior-frontend where UI or backend security is touched

## Progress log
- 2026-10-29 17:10 created and started after the user approved the design in chat

## Result
EPOS-complaint kind built. Files: ManifestFetcher.php (enum kept + checked), EposExecutor.php (value outside enum refused as invalid), EposNode.jsx (dropdown), examples/epos-complaint-alert.flow.json (new), epos-order-alert.flow.json (kind: order), app/logic/epos-integration.md. Tests written first and green before the user said to skip tests: EposNodeTest, IntegrationApiTest, EposOrderAlertFlowTest, Automation/Integrations/Client screens (194 tests OK). Skipped: complaints_enabled (unused). Not run against a real EPOS.

## Progress log (end)
- 2026-10-29 18:10 built; see Result
