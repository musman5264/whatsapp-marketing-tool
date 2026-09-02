<?php

namespace App\Listeners;

use App\Events\AutomationWebhookReceived;
use App\Events\CampaignCompleted;
use App\Events\CommerceEventReceived;
use App\Events\ContactCreated;
use App\Events\LeadQualified;
use App\Events\LeadStageChanged;
use App\Events\MessageReceived;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Services\WebhookIdempotencyService;

class AutomationTriggerListener
{
    public function __construct(
        private readonly AutomationEngine $engine,
        private readonly WebhookIdempotencyService $idempotency,
    ) {}

    /**
     * One inbound message can reach this listener more than once — the WhatsApp
     * Web engine re-sends webhooks, message + message.any arrive for the same id,
     * and a failed inline run gets re-queued to the cron drain. Guard each
     * (automation, triggering message) pair with an atomic marker so a given
     * automation fires at most once per message.
     *
     * @param  array<string, mixed>  $context
     */
    private function alreadyTriggered(int $automationId, array $context): bool
    {
        $messageId = $context['message_id'] ?? null;
        if (! $messageId) {
            return false; // non-message triggers have their own semantics
        }

        return ! $this->idempotency->isNewEvent(
            'automation_trigger',
            $automationId.':msg:'.$messageId,
        );
    }

    public function handleMessageReceived(MessageReceived $event): void
    {
        $contactId = $event->message->conversation?->contact_id;
        $workspaceId = $event->message->conversation?->workspace_id;
        if (! $contactId || ! $workspaceId) {
            return;
        }

        $messageBody = $event->message->body ?? '';

        // Resume any runs parked on an "Ask question" node awaiting this contact's reply.
        $this->engine->resumeAwaitingReplies($workspaceId, $contactId, $messageBody);

        $this->fireWithConfig('message.received', $workspaceId, $contactId, [
            'message_id' => $event->message->id,
            'message_channel' => $event->message->channel,
            'message_body' => $messageBody,
        ], $messageBody);
    }

    public function handleReactionReceived(\App\Events\ReactionReceived $event): void
    {
        $contactId = $event->message->conversation?->contact_id;
        $workspaceId = $event->message->conversation?->workspace_id;
        if (! $contactId || ! $workspaceId) {
            return;
        }

        $this->fireWithConfig('reaction.received', $workspaceId, $contactId, [
            'message_id' => $event->message->id,
            'reaction_emoji' => $event->emoji,
        ], $event->emoji);
    }

    public function handleContactCreated(ContactCreated $event): void
    {
        $this->fire('contact.created', $event->contact->workspace_id, $event->contact->id);
    }

    public function handleCampaignCompleted(CampaignCompleted $event): void
    {
        // No per-contact trigger for campaign completion; skip.
    }

    public function handleCommerceEvent(CommerceEventReceived $event): void
    {
        // eventType is one of order.placed / order.fulfilled / order.cancelled /
        // cart.abandoned / customer.created — matched directly against trigger_type.
        $this->fire($event->eventType, $event->workspaceId, $event->contactId, $event->context);
    }

    public function handleLeadStageChanged(LeadStageChanged $event): void
    {
        // A lead only has a contact once it has been pushed to contacts. Without
        // one there is nobody for the automation's send/tag actions to act on.
        if (! $event->contactId) {
            return;
        }

        $this->fire('lead.stage_changed', $event->workspaceId, $event->contactId, $event->context());

        // Reaching a terminal stage is its own trigger, so a tenant can wire
        // "won" or "lost" follow-up without a condition node on the stage name.
        if ($event->isWon) {
            $this->fire('lead.won', $event->workspaceId, $event->contactId, $event->context());
        }

        if ($event->isLost) {
            $this->fire('lead.lost', $event->workspaceId, $event->contactId, $event->context());
        }
    }

    public function handleLeadQualified(LeadQualified $event): void
    {
        if (! $event->contactId) {
            return;
        }

        $this->fire('lead.qualified', $event->workspaceId, $event->contactId, $event->context());
    }

    public function handleAutomationWebhookReceived(AutomationWebhookReceived $event): void
    {
        $automation = Automation::where('id', $event->automationId)
            ->where('status', 'active')
            ->where('trigger_type', 'webhook')
            ->first();

        if (! $automation) {
            return;
        }

        $context = ['payload' => $event->payload];

        if ($event->contactId) {
            $this->engine->triggerForContact($automation, $event->contactId, $context);
        } else {
            // Contactless: trigger a run without a contact (contact_id = null)
            $this->triggerWithoutContact($automation, $context);
        }
    }

    private function triggerWithoutContact(Automation $automation, array $context = []): void
    {
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => null,
            'status' => 'pending',
            'context' => $context,
            'started_at' => now(),
        ]);

        dispatch(new ExecuteAutomationRunJob($run->id))->onQueue('automation');
    }

    private function fire(string $triggerType, int $workspaceId, int $contactId, array $context = []): void
    {
        Automation::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->each(function ($automation) use ($contactId, $context) {
                if ($this->alreadyTriggered($automation->id, $context)) {
                    return;
                }
                $this->engine->triggerForContact($automation, $contactId, $context);
            });
    }

    /**
     * Like fire(), but respects trigger_config for message.received automations:
     *   - keywords     list of terms
     *   - match_mode   how each term is tested against the body:
     *                  contains (default) | equals | starts_with | ends_with | regex
     * Matching is case-insensitive; the automation fires if ANY keyword matches.
     */
    private function fireWithConfig(string $triggerType, int $workspaceId, int $contactId, array $context, string $messageBody = ''): void
    {
        $automations = Automation::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->get();

        $body = trim($messageBody);
        $bodyLower = mb_strtolower($body);

        foreach ($automations as $automation) {
            $keywords = $automation->trigger_config['keywords'] ?? [];
            $mode = $automation->trigger_config['match_mode'] ?? 'contains';

            if (! empty($keywords)) {
                $matches = false;
                foreach ($keywords as $kw) {
                    if ($this->keywordMatches($bodyLower, $body, (string) $kw, $mode)) {
                        $matches = true;
                        break;
                    }
                }
                if (! $matches) {
                    continue;
                }
            }

            if ($this->alreadyTriggered($automation->id, $context)) {
                continue;
            }

            $this->engine->triggerForContact($automation, $contactId, $context);
        }
    }

    private function keywordMatches(string $bodyLower, string $bodyRaw, string $keyword, string $mode): bool
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return false;
        }
        $kwLower = mb_strtolower($kw);

        return match ($mode) {
            'equals' => $bodyLower === $kwLower,
            'starts_with' => str_starts_with($bodyLower, $kwLower),
            'ends_with' => str_ends_with($bodyLower, $kwLower),
            'regex' => (function () use ($bodyRaw, $kw) {
                // Anchor-free, case-insensitive. A bad pattern never matches.
                $pattern = '/'.str_replace('/', '\/', $kw).'/i';

                return @preg_match($pattern, $bodyRaw) === 1;
            })(),
            default => str_contains($bodyLower, $kwLower), // 'contains'
        };
    }
}
