<?php

namespace App\Modules\Inbox\Models;

use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Automatic labelling rule: when an inbound message matches, the rule's label is
 * attached to the conversation (see LabelRuleEvaluator).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $inbox_label_id
 * @property int|null $channel_account_id
 * @property string $name
 * @property string $match_type
 * @property string|null $pattern
 * @property bool $is_active
 * @property int $priority
 * @property int $hits
 */
class InboxLabelRule extends Model
{
    public const MATCH_KEYWORD_ANY = 'keyword_any';
    public const MATCH_KEYWORD_ALL = 'keyword_all';
    public const MATCH_REGEX = 'regex';
    public const MATCH_FIRST_MESSAGE = 'first_message';
    public const MATCH_NEW_CONTACT = 'new_contact';

    public const MATCH_TYPES = [
        self::MATCH_KEYWORD_ANY,
        self::MATCH_KEYWORD_ALL,
        self::MATCH_REGEX,
        self::MATCH_FIRST_MESSAGE,
        self::MATCH_NEW_CONTACT,
    ];

    /** Match types that need a pattern. */
    public const PATTERN_TYPES = [self::MATCH_KEYWORD_ANY, self::MATCH_KEYWORD_ALL, self::MATCH_REGEX];

    protected $table = 'inbox_label_rules';

    protected $fillable = [
        'workspace_id', 'inbox_label_id', 'channel_account_id', 'name',
        'match_type', 'pattern', 'is_active', 'priority', 'hits',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'hits' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(InboxLabel::class, 'inbox_label_id');
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }
}
