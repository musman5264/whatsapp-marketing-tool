<?php

namespace App\Modules\Leads\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-workspace qualification tuning. A workspace with no row scores on the
 * defaults below, so scoring works before anyone visits the settings screen.
 */
class LeadScoringConfig extends Model
{
    protected $table = 'lead_scoring_configs';

    /**
     * Rule weights, in points. They are expected to total 100 — {@see normalizedWeights()}
     * rescales them if a tenant's edits drift, so a score is always out of 100.
     *
     * Only signals the Google Places scraper actually populates are weighted:
     * whatsapp_status is deliberately absent until number validation exists to set it.
     *
     * @var array<string, int>
     */
    public const DEFAULT_WEIGHTS = [
        'has_phone' => 35,
        'rating' => 25,
        'review_volume' => 25,
        'has_website' => 15,
    ];

    /** Score at or above which a lead is hot / warm. Below warm is cold. */
    public const DEFAULT_THRESHOLDS = ['hot' => 70, 'warm' => 40];

    /** review_count that counts as maximum credibility; scaled logarithmically below it. */
    public const REVIEW_SATURATION = 200;

    protected $fillable = ['workspace_id', 'weights', 'thresholds'];

    protected function casts(): array
    {
        return [
            'weights' => 'array',
            'thresholds' => 'array',
        ];
    }

    public static function forWorkspace(int $workspaceId): self
    {
        return static::firstOrNew(['workspace_id' => $workspaceId]);
    }

    /** @return array<string, int> */
    public function effectiveWeights(): array
    {
        $stored = $this->weights ?? [];

        // Intersect with the defaults so a stale key from an older release can't
        // inject a rule the scorer no longer knows how to compute.
        $weights = [];
        foreach (self::DEFAULT_WEIGHTS as $rule => $default) {
            $weights[$rule] = isset($stored[$rule]) ? max(0, (int) $stored[$rule]) : $default;
        }

        return array_sum($weights) === 0 ? self::DEFAULT_WEIGHTS : $weights;
    }

    /** @return array<string, int> */
    public function effectiveThresholds(): array
    {
        $stored = $this->thresholds ?? [];
        $hot = (int) ($stored['hot'] ?? self::DEFAULT_THRESHOLDS['hot']);
        $warm = (int) ($stored['warm'] ?? self::DEFAULT_THRESHOLDS['warm']);

        // A warm bar above the hot bar would make "warm" unreachable.
        $warm = min($warm, $hot);

        return ['hot' => $hot, 'warm' => $warm];
    }
}
