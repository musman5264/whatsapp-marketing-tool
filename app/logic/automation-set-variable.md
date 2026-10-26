# Automation: Set variable step and variables in Condition values (T-0060)

Code written 2026-10-26. **No tests were written for this change** (the user asked to skip them). Checked only: `php -l`, eslint on `Builder.jsx` (same 1 error + 3 warnings as before), `npm run build`, and the existing `tests/Feature/Automation` + `ProductionHardening` suites (result in the task file). Not tried in a browser or on the live site.

## Set variable (`set_variable`, group Logic)
Saved data: `variable` (name), `value` (text, `{{tokens}}` allowed), `fallback` (optional text, tokens allowed).
- Name: spaces become `_`; must start with a letter and use letters, numbers, `_`. A leading `_` is refused (engine-internal keys such as `_awaiting_reply`). A bad name fails the run with "Variable name is required ...".
- The step renders `value` with the same token engine as messages. A token that does not exist renders empty (for example `{{epos.name}}` after EPOS found nothing). If the result is empty and `fallback` is not blank, `fallback` is rendered and used. If both are empty the variable is saved as an empty string (later `{{name}}` is empty; a Condition "does not exist" is true).
- With no fallback it simply creates a variable by hand: `value` `VIP`, or `Hello {{contact.name}}`.
- Written to the run context, so every later step can use `{{name}}` in messages, Condition fields (`context.name`) and E-POS parameters. One exit, no branch. Run log line: `Set {{name}} = "..." (fallback used).`
- Test run (builder "Test") previews it with the real value and fallback logic and carries the variable on.
- Where: `AutomationEngine::executeSetVariable()` / `resolveSetVariable()` / `previewNode()`; builder `Builder.jsx` (`NODE_DEFS.set_variable`, `SetVariableFields`, `collectFlowVariables`, subtitle), strings `automation.node_set_variable`, `field_variable_*`, `set_variable_hint` in `en.json` only (other locales fall back to English).

Example for a customer EPOS does not know: Ask → E-POS (profile) → Set variable `customer_name` = `{{epos.name}}`, fallback `{{whatsapp.name}}` → messages say "Hi {{customer_name}}".

## Condition value can be a variable
`AutomationEngine::evaluateCondition()` now renders the Value when it contains `{{`, so `{{whatsapp.name}}` or `{{epos.name}}` is compared as its current text, not as the literal characters. Values without `{{` behave as before. Comparing is still case-insensitive for equals / not equals / contains. The run-log line still prints the raw Value text.

## Known gaps
- The AI workflow generator (`WorkflowGenerator`) does not know this step.
- Urdu and the other locales have no strings yet.
- Panel not looked at at 360px / dark mode.
