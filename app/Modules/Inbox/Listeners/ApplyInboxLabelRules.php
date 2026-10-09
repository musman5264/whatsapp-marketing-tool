<?php

namespace App\Modules\Inbox\Listeners;

use App\Events\MessageReceived;
use App\Modules\Inbox\Services\LabelRuleEvaluator;
use App\Modules\WhatsappWeb\Services\Waha\WahaLabelSync;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the label rules on every inbound message and mirrors any newly applied
 * label onto a WhatsApp Web chat. Synchronous on purpose: the work is cheap DB
 * queries and the shared-hosting deployment has no persistent queue worker.
 * Failures are logged and swallowed so the inbound pipeline is never interrupted.
 */
class ApplyInboxLabelRules
{
    public function __construct(private readonly LabelRuleEvaluator $evaluator) {}

    public function handle(MessageReceived $event): void
    {
        try {
            $attached = $this->evaluator->evaluate($event->message);
            if ($attached !== [] && $event->message->conversation) {
                WahaLabelSync::fromSystem()?->syncConversationLabels($event->message->conversation);
            }
        } catch (Throwable $e) {
            Log::warning('inbox.label_rules.failed', [
                'message_id' => $event->message->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
