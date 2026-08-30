<?php

namespace App\Modules\Leads\Models;

use App\Models\User;
use App\Support\Concerns\MasksDemoData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    use MasksDemoData;

    protected $table = 'lead_activities';

    /**
     * A follow-up note is free text a salesperson typed — "rang +8801700000000,
     * ask for Priya" — so it carries exactly the PII the demo mask exists to
     * hide. 'text' scrubs phone-like runs and emails out of it.
     *
     * @return array<string, string>
     */
    protected function demoMask(): array
    {
        return ['body' => 'text'];
    }

    /** Things a person logs by hand. Mirrored by MANUAL_TYPES in the React log form. */
    public const MANUAL_TYPES = ['note', 'call', 'whatsapp', 'email', 'meeting'];

    /** Things the app records on its own. A user may never post these. */
    public const SYSTEM_TYPES = ['created', 'stage_changed', 'qualified', 'pushed_to_contacts', 'rescored'];

    /**
     * Outcomes offered when logging a call — kept here rather than free text so the
     * timeline stays scannable and could later be counted on.
     */
    public const OUTCOMES = ['answered', 'no_answer', 'voicemail', 'wrong_number', 'not_interested', 'interested'];

    protected $fillable = ['workspace_id', 'lead_id', 'user_id', 'type', 'body', 'meta', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSystem(): bool
    {
        return in_array($this->type, self::SYSTEM_TYPES, true);
    }
}
