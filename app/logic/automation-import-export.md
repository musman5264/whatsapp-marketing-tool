# Automation: Import / Export

Code read, targeted tests run and the modal looked at in a browser on 2026-10-24 (local). Not tried on the live site; an actual file round trip (download, then upload through the browser) was not done by hand: the pieces are covered by tests.

## What the user sees
Builder toolbar: an **Import / Export** button next to **Test** opens a small window with:
- **Export as JSON (to import it again)**: downloads `<automation-name>.json`.
- **Export as Markdown (to read or share)**: downloads `<automation-name>.md`, a readable description: title, trigger and keyword filter, every step in the order the flow reaches it (number, name, settings), where each branch goes (Yes / No, or Found / Sent / No data / Not sent for E-POS steps), and a "Not connected" list for loose steps.
- **Choose an exported .json file**: imports it.
Both exports are built from what is on screen, so unsaved edits are included.

## Export file (JSON)
`{"format":"whatsmine.automation","version":1,"exported_at":...,"automation":{"name","trigger_type","trigger_config","nodes":[{id,type,position,data}],"edges":[{id,source,target,sourceHandle,targetHandle}]}}`.
Not exported: workspace, status, run count, `trigger_token`. In API Call steps, header values whose name looks secret (authorization, token, secret, password, api key, cookie) become `REDACTED`, unless the value is a `{{template}}`. A key typed into a step's payload or URL is not detected: check the file before sharing it.
Code: `resources/js/lib/automationFile.js` (`buildExport`, `redactSecrets`, `flowToMarkdown`, `fileSafeName`, `downloadText`), tests `resources/js/__tests__/automationFile.test.js`.

## Import
`POST /app/automations/import` (`client.automations.import`, field `payload` = the file text) in `AutomationController::import()`. It always creates a **new draft** automation named `<name> (imported)` in the importer's current workspace and opens it. The automation on screen is never changed.
The file is treated as untrusted: at most 1,000,000 characters, 1-200 steps, each with a unique id (max 64 chars) and a plain type name; at most 400 connections and any that point at a missing step are dropped; the file's workspace, status and token are ignored; the trigger type must look like `message.received`. A bad file gets a message in the window and nothing is created. Format must be `whatsmine.automation`, version 1.
Steps that refer to things by id (WhatsApp template names are by name, but campaigns, chatbots, agents, stores, sub-flows, E-POS link and question) are copied as they are; ids from another system will not match here and the step needs to be chosen again. Sub-flows are looked up inside the workspace only.
Tests: `tests/Feature/Automation/AutomationImportTest.php` (10 tests).

## Also in this change
The flow check list warns about an Ask Question step with no step after it (see `automation-ask-question.md`).
