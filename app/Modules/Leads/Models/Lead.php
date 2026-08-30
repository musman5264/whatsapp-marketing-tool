<?php

namespace App\Modules\Leads\Models;

use App\Modules\Shared\Models\Contact;
use App\Support\Concerns\MasksDemoData;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    use HasFactory, MasksDemoData;

    protected static function newFactory()
    {
        return LeadFactory::new();
    }

    protected $table = 'leads';

    /**
     * Scraped-lead PII masked in demo mode (see App\Support\Concerns\MasksDemoData).
     *
     * @return array<string, string>
     */
    protected function demoMask(): array
    {
        return [
            'name' => 'name',
            'phone' => 'phone',
            'email' => 'email',
            'website' => 'redact',
            'address' => 'redact',
            'lat' => 'null',
            'lng' => 'null',
        ];
    }

    protected $fillable = [
        'workspace_id', 'name', 'phone', 'email', 'website', 'address', 'city', 'country',
        'lat', 'lng', 'category', 'rating', 'review_count', 'google_place_id', 'whatsapp_status',
        'pushed_to_contacts', 'stage_id', 'board_position', 'stage_changed_at',
        'score', 'score_band', 'score_breakdown', 'scored_at',
        'next_follow_up_at', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'pushed_to_contacts' => 'boolean',
            'rating' => 'float',
            'score' => 'integer',
            'score_breakdown' => 'array',
            'board_position' => 'integer',
            'scored_at' => 'datetime',
            'stage_changed_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** Follow-up history, newest first. */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('occurred_at')->latest('id');
    }

    /** Follow-up due now or in the past. */
    public function scopeFollowUpDue(Builder $query): Builder
    {
        return $query->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now());
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(LeadPipelineStage::class, 'stage_id');
    }

    /**
     * Set when the lead is pushed to contacts. Automations act on contacts, so a
     * lead without one cannot drive a pipeline automation.
     */
    public function contact(): HasOne
    {
        return $this->hasOne(Contact::class, 'lead_id');
    }

    /** Board order: highest score first within a stage, newest as the tiebreak. */
    public function scopeBoardOrdered(Builder $query): Builder
    {
        return $query->orderBy('board_position')
            ->orderByDesc('score')
            ->orderByDesc('id');
    }
}
