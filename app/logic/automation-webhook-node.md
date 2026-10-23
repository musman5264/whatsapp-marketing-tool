# Automation: API Call node (was "Call Webhook")

Lets a flow call any HTTP API in the middle of a conversation, keep the answer, and use it in later nodes.
Node type in saved flows is still `webhook`; only the label changed to "API Call" (`automation.node_webhook` in `en.json`; the other 16 locale files still carry the old translated name).
Code read on 2026-10-24; tests below pass; live behaviour is unverified.

## Where the code is
| Piece | File |
|---|---|
| Node run | `app/Modules/Automation/Services/AutomationEngine.php` `executeWebhook()`, `decodeJsonField()` |
| `{{...}}` replacement | same file, `renderTokens()`, `contextLookup()`, `tokenText()` |
| Poll options from a list | same file, `pollOptions()` (used by `executeSendPoll()`) |
| Condition on a nested field | same file, `evaluateCondition()` → `conditionContextValue()` |
| Builder panel | `resources/js/Pages/Automation/Builder.jsx` `WebhookFields`, variable list `collectFlowVariables()` |
| Builder "test run" | `AutomationEngine::previewNode()` branch `'webhook'` (does not call the URL, seeds sample values) |
| Tests | `tests/Feature/Automation/AutomationNodeBehaviourTest.php` (`test_api_call_*`, `test_each_block_*`, `test_dotted_path_*`, `test_poll_options_*`, `test_condition_can_branch_*`) |

## Fields (saved on the node as `data`)
| Field | Key | Notes |
|---|---|---|
| Node label | `label` | display only |
| URL | `url` | required; `{{tokens}}` are replaced (not URL-encoded) |
| Method | `method` | POST (default), GET, PUT, PATCH. The engine also accepts DELETE, the dropdown does not offer it |
| Headers | `headers` | JSON text, tokens replaced, e.g. `{"Authorization":"Bearer {{token}}"}` |
| Payload | `payload` | JSON text, tokens replaced |
| Save response as | `result_var` | variable name; spaces become `_` |

## What happens when the node runs
1. Empty URL: the node errors and the whole run is marked `failed`.
2. URL, headers and payload go through `renderTokens()`. Headers/payload must be valid JSON after replacement, otherwise they become empty (silently).
3. `Http::timeout(10)`. GET sends the payload as the query string. Other methods send the payload **plus a `context` key holding the run context without internal keys** (names starting with `_`, such as `_awaiting_reply`, are not sent).
4. A non-2xx answer is **not** an error: the node is logged `ok` and the flow continues. Only `{{webhook_status}}` tells you.
5. A timeout or connection failure throws, `executeNode()` catches it, and the run is marked `failed`.
6. If `result_var` is set and the body is JSON:
   - `{{result_var}}` = the raw JSON text of the whole body (this is what the dotted paths read from)
   - `{{result_var_field}}` = each top-level scalar field as text (a JSON `true` becomes `1`, `false` becomes empty here; use the dotted path for booleans)
   If the body is not JSON, `{{result_var}}` = the raw body.
7. The values land in the run context (`context_update`) and are read by every later node.

## Reading the reply in later nodes
Order in `renderTokens()`: `{{#each …}}` blocks, `{{whatsapp.*}}`, `{{customer.*}}` (alias of whatsapp), contact shorthands, `{{contact.field}}`, then context variables. A token that never resolves is removed, so a customer never sees a raw `{{...}}`.

| Write | Gives |
|---|---|
| `{{api.customer.name}}` | nested field (`api` = the variable name). `{{context.api.customer.name}}` is the same |
| `{{api.products.0.name}}` | item by position (0 = first) |
| `{{api.products.*.name}}` | that field of every item, joined with ", " (in a poll option, one option per item) |
| `{{api.tags}}` | a list of plain values joins with ", "; any other structure becomes JSON text |
| `{{#each api.products}}{{@n}}. {{name}} – Rs {{price}}\n{{/each}}` | one copy of the block per item; `{{@n}}` = 1, 2, 3…; `{{this}}` = the item itself when items are plain values; a name that is not a field of the item is left for the normal replacement (so `{{whatsapp.name}}` still works inside) |
| Condition node, field `api.hours.open_now`, `equals`, `true` | nested field in a condition; a JSON boolean compares as `true` / `false` |
| Poll node, options `{{api.products.*.name}}` | one poll option per item (only when the options box holds just this one token) |

Safety: inside `{{#each}}`, `{{` found in API data is changed to `{ {` so data can never run as a token (test `test_each_block_does_not_run_tokens_found_inside_api_data`).
A path that does not exist renders as empty text.
Reserved first names: `whatsapp`, `customer`, `contact` are handled before variables, so a `result_var` named `customer` is not reachable as `{{customer.name}}` (that gives the WhatsApp contact's name). Use another name such as `api`.

Used by: send WhatsApp, Ask Question, send media (link, caption), send poll, send SMS/email, condition, update contact, API Call URL/headers/payload.

## Limits
- No cap on `{{#each}}` size: a WhatsApp text over 4096 characters will be refused by WhatsApp. Keep lists short (the API should limit them).
- Poll option count is not limited in code; WhatsApp's own limit is unverified here.
- The builder hint shows the new syntax only for the saved variable name; the "Insert a variable" chips still list flat names.
- Only the node's English label was renamed.

## Product list over a QR number (WAHA) — what is possible
Verified in `Builder.jsx` and `WhatsappDriver::interactiveAsPlainText()`: a QR number has no native buttons, lists or carousel (WAHA's own docs mark Send Buttons "DEPRECATED - Buttons do not work at the moment!", lists "fragile", no carousel). Quick Replies / List Message are flattened to a numbered text message. What is real: text, image/video/document with caption, location, native polls.
Working recipe: Ask Question (category) → API Call `GET …/products?category={{answer}}` saved as `api` → Send WhatsApp with the `{{#each}}` list → Ask Question "reply with a number" or a Send Poll with `{{api.products.*.name}}`. A poll vote is saved to `poll_answer` and the run can wait for it.

## How to test
```
DB_PASSWORD= php -d memory_limit=1G vendor/phpunit/phpunit/phpunit tests/Feature/Automation tests/Feature/ProductionHardening/AutomationEngineTest.php
```
Result 2026-10-24: `OK (77 tests, 202 assertions)`. The Builder "test run" seeds sample values only; a real check needs a run against a real URL and the run log.
