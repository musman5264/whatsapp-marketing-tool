<?php

namespace App\Modules\Leads\Services\Scoring;

/**
 * A qualification result: the 0-100 score, its band, and the per-rule breakdown
 * that produced it. The breakdown is what the UI shows to explain a score —
 * an unexplained number is one a salesperson will not trust or act on.
 */
final class LeadScore
{
    /**
     * @param  int  $score  0-100
     * @param  'hot'|'warm'|'cold'  $band
     * @param  list<array{rule: string, points: int, max: int, detail: string}>  $breakdown
     */
    public function __construct(
        public readonly int $score,
        public readonly string $band,
        public readonly array $breakdown,
    ) {}

    /** @return array{score: int, band: string, breakdown: array} */
    public function toArray(): array
    {
        return ['score' => $this->score, 'band' => $this->band, 'breakdown' => $this->breakdown];
    }
}
