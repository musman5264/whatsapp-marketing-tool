<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Inbox\Models\ConversationActivity;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Inbox\Models\InboxLabelRule;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Support\Facades\Cache;

/**
 * Applies the workspace's automatic label rules to an inbound message.
 *
 * Each inbound message is evaluated at most once (cache gate). Every active rule
 * is checked in priority order and a label is only attached when it is not
 * already on the conversation (syncWithoutDetaching; the activity log is written
 * only on a real change), mirroring LabelController::attach.
 */
class LabelRuleEvaluator
{
    /**
     * @return list<InboxLabel> labels that were newly attached by this run
     */
    public function evaluate(Message $message): array
    {
        if ($message->direction !== 'in') {
            return [];
        }
        if (! Cache::add('label_rules:msg:'.$message->id, 1, 3600)) {
            return [];
        }

        $conversation = $message->conversation;
        if (! $conversation || $this->autoLabelDisabled($conversation)) {
            return [];
        }

        $rules = InboxLabelRule::with('label')
            ->where('workspace_id', $conversation->workspace_id)
            ->where('is_active', true)
            ->where(function ($q) use ($conversation) {
                $q->whereNull('channel_account_id')
                    ->orWhere('channel_account_id', $conversation->channel_account_id);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $attached = [];
        foreach ($rules as $rule) {
            $label = $rule->label;
            if (! $label || (int) $label->workspace_id !== (int) $conversation->workspace_id) {
                continue;
            }
            if (! $this->matches($rule, $message, $conversation)) {
                continue;
            }

            $rule->increment('hits');

            $changes = $conversation->labels()->syncWithoutDetaching([$label->id]);
            if (! empty($changes['attached'])) {
                ConversationActivity::log($conversation, 'label_added', [
                    'label' => $label->name,
                    'source' => 'rule',
                    'rule' => $rule->name,
                ]);
                $attached[] = $label;
            }
        }

        return $attached;
    }

    public function matches(InboxLabelRule $rule, Message $message, Conversation $conversation): bool
    {
        $body = (string) $message->body;

        return match ($rule->match_type) {
            InboxLabelRule::MATCH_FIRST_MESSAGE => $this->isFirstInbound($message, $conversation),
            InboxLabelRule::MATCH_NEW_CONTACT => $this->isNewContact($message, $conversation),
            InboxLabelRule::MATCH_KEYWORD_ANY => $this->keywordMatch($rule->pattern, $body, false),
            InboxLabelRule::MATCH_KEYWORD_ALL => $this->keywordMatch($rule->pattern, $body, true),
            InboxLabelRule::MATCH_REGEX => $this->regexMatch($rule->pattern, $body),
            default => false,
        };
    }

    /**
     * Keywords are separated by commas or new lines.
     *
     * @return list<string>
     */
    public static function keywords(?string $pattern): array
    {
        $parts = preg_split('/[,\n\r]+/u', (string) $pattern) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($k) => $k !== ''));
    }

    public static function isValidRegex(?string $pattern): bool
    {
        $compiled = self::compileRegex($pattern);

        return $compiled !== null && @preg_match($compiled, '') !== false;
    }

    /**
     * Accepts either a delimited PCRE (`/abc/`, `~abc~i`) or a bare expression,
     * which is wrapped in slashes. The `i` (case-insensitive) and `u` (UTF-8)
     * flags are always added.
     */
    public static function compileRegex(?string $pattern): ?string
    {
        $p = trim((string) $pattern);
        if ($p === '') {
            return null;
        }

        if (preg_match('/^[\/#~!@%|]/', $p) === 1) {
            $delim = $p[0];
            $end = strrpos($p, $delim);
            if ($end === false || $end === 0) {
                return null;
            }
            $core = substr($p, 1, $end - 1);
            $flags = substr($p, $end + 1);
            foreach (['i', 'u'] as $flag) {
                if (! str_contains($flags, $flag)) {
                    $flags .= $flag;
                }
            }

            return $delim.$core.$delim.$flags;
        }

        return '/'.str_replace('/', '\/', $p).'/iu';
    }

    /**
     * Case-insensitive and Unicode-aware (mb_stripos), so Arabic and Urdu keywords work.
     */
    private function keywordMatch(?string $pattern, string $body, bool $all): bool
    {
        $keywords = self::keywords($pattern);
        if ($keywords === [] || $body === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            $found = mb_stripos($body, $keyword, 0, 'UTF-8') !== false;
            if ($all && ! $found) {
                return false;
            }
            if (! $all && $found) {
                return true;
            }
        }

        return $all;
    }

    private function regexMatch(?string $pattern, string $body): bool
    {
        $compiled = self::compileRegex($pattern);
        if ($compiled === null || $body === '') {
            return false;
        }

        return @preg_match($compiled, $body) === 1;
    }

    /** True when this is the first inbound message of the conversation. */
    private function isFirstInbound(Message $message, Conversation $conversation): bool
    {
        return ! Message::where('conversation_id', $conversation->id)
            ->where('direction', 'in')
            ->where('id', '<', $message->id)
            ->exists();
    }

    /** True when the contact has no earlier inbound message in any of its conversations. */
    private function isNewContact(Message $message, Conversation $conversation): bool
    {
        if (! $conversation->contact_id) {
            return false;
        }

        $conversationIds = Conversation::where('contact_id', $conversation->contact_id)->pluck('id');

        return ! Message::whereIn('conversation_id', $conversationIds)
            ->where('direction', 'in')
            ->where('id', '<', $message->id)
            ->exists();
    }

    /** A WhatsApp Web number can switch off automatic labelling. */
    private function autoLabelDisabled(Conversation $conversation): bool
    {
        $phoneId = $conversation->channelAccount?->phone_number_id;
        if (! $phoneId) {
            return false;
        }

        $session = WhatsappWebSession::where('workspace_id', $conversation->workspace_id)
            ->where('session_name', $phoneId)
            ->first();

        return $session !== null && ! $session->auto_label_enabled;
    }
}
