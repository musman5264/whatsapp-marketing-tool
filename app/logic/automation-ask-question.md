# Automation: Ask Question node and waiting for a reply

Code read and tests run on 2026-10-24 (`tests/Feature/Automation/AutomationNodeBehaviourTest.php`, `BranchFallbackTest.php`). Live behaviour unverified.

## How it works
- `AutomationEngine::executeAskQuestion()` sends the question, then parks the run: `status = waiting`, `resume_node_id` = the step connected after it, context `_awaiting_reply` and `_reply_var` (the "save answer to" name, default `answer`).
- The contact's next inbound message goes to `resumeAwaitingReplies()` first (called from `AutomationTriggerListener::handleMessageReceived`). It saves the text in the variable and continues the run at `resume_node_id`. This happens for **any** message from that contact, whatever the trigger's keyword filter says: the keyword filter only decides how a flow *starts*.
- The same message may also start a new run of an active flow whose trigger matches.

## Ask Question with nothing connected after it (fixed 2026-10-24)
Before: the run parked with `resume_node_id = null`; `executeRun()` treats a null resume step as a fresh start, so every reply restarted the flow from the trigger and sent the question again (a menu was repeated on every message).
Now:
- `executeAskQuestion()` does not park when nothing follows it. It sends the question, logs "Nothing is connected after it, so the flow ends here" and the run completes.
- A run already parked that way (left by the old code) is ended by `resumeAwaitingReplies()` when the next message arrives: the answer is saved, the run is marked completed, nothing is sent.
- The builder warns about an Ask Question with no step after it (flow check list).

## Building a menu
Trigger (keyword `m`, exactly) -> Ask Question (saves to `user`) -> Condition `user` equals `1` -> ... "No" -> Condition `user` equals `2` -> ... last "No" -> "Invalid choice" message. Connect every step; an unconnected exit ends the flow silently.
