# PLAN-004 Multiple QR WhatsApp numbers per workspace, plan limits, failover

Status: PLAN ONLY (user asked "just plan", 2026-10-26). Not approved, no tasks created, nothing built.
Facts below were read from the code on 2026-10-26; WAHA/live behaviour is unverified.

## What exists today (verified in code)
- One session per workspace: `WhatsappWebSession::sessionNameFor()` = `ws-{workspaceId}`; `SessionProvisioner::ensure()` creates exactly that one row + one shadow `ChannelAccount` (phone_number_id = session name).
- Sending is already per account: `WhatsappDriver::send` -> `conversation->channelAccount` -> `sendViaWhatsappWeb` uses `phone_number_id` as the WAHA session. Automation (`AutomationEngine` ~1672) and inbox (`InboxController` ~888) also resolve the session from the account.
- ~12 places still assume "the workspace's one session" (`where('workspace_id', ..)->first()`): `CampaignController` (413, 488), `SendCampaignMessageJob` (172), `InboxSetupController` (77), `LabelRuleEvaluator` (207), `SaveContactToPhone` (33), `WahaLabelSync` (323), `WhatsappWebHealthController` (22), `WhatsappWebSessionController` (182), `NotifyEposReconnectJob` (loops over integrations, reads one session).
- Plans: `plans.limits` JSON, `null` = unlimited. `EnforceLimit` only counts monthly usage meters, so a "how many numbers" cap needs its own count check (the `users` limit in `DashboardController` is read the same way).

## Feasibility
- Multiple QR numbers: yes, medium effort. Data model needs `session_name` no longer derived from workspace alone (e.g. `ws-{workspaceId}-{n}`), UI list of numbers, and the ~12 single-session call sites made account-aware. Send/receive core already works per account.
- Plan limits: yes, small. New limit key `whatsapp_numbers` (null = unlimited) in the plan editor + check when adding a number; show "2 of 3 used" and an upgrade prompt.
- Failover: yes, but only for NEW traffic, with a hard limit: a customer's chat lives on the number they wrote to. If number A drops, replying from number B reaches the customer as a different sender (and may be ignored or look like spam). So failover is safe for: campaigns/broadcasts, API/automation-initiated first messages, and "send as primary, else next" choices. For replies in an existing chat the safe default is queue-and-alert, with an opt-in per workspace to reroute.
- Ban risk goes UP if failover blasts a second number with the same volume; add per-number daily caps.

## Phases
1. Data model + provisioner: many sessions per workspace, new session naming, migration that keeps existing `ws-{id}` row as number 1. No behaviour change for one-number workspaces.
2. Make the single-session call sites account-aware (list above); each gets a test that fails with two numbers first.
3. Plan limit `whatsapp_numbers`: plan editor field, enforcement on add, usage display, pricing page text, English locale strings.
4. UI: numbers list (status, phone, QR/relink per number), pick a number per campaign / automation / API call, default number per workspace.
5. Failover/rotation: per-workspace routing group with priority order. Health = session status (`active`) from the now-fixed `session.status` webhook. Rules: campaigns rotate or fall over; new outbound picks first active; existing chats queue + alert unless "allow reroute" is on. Mark rerouted messages so the inbox shows which number sent them. EPOS alert says WHICH number dropped.
6. Logic doc in `app/logic/`, live verification checklist.

## Open decisions for the user
- Failover on replies in existing chats: queue + alert (recommended) or reroute?
- Plan numbers per tier (e.g. Starter 1, Pro 3, Business 10, or unlimited for top plan)?
- One WAHA server for all numbers (resource use grows per session) or limits there too?
- Campaigns: rotate across numbers always, or only fail over when the chosen number is down?

## Risks
- WhatsApp may ban numbers used for bulk sending; rotation reduces but does not remove this.
- Duplicate contacts/conversations when the same customer writes to two numbers (one conversation per number is the proposed rule).
- WAHA engine capacity per session: unverified.
