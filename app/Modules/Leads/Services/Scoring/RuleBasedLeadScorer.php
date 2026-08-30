<?php

namespace App\Modules\Leads\Services\Scoring;

use App\Modules\Leads\Contracts\LeadScorer;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadScoringConfig;

/**
 * Scores a lead from the signals the Google Places scraper already collects.
 * Deterministic and free — no API call per lead — and every rule reports why it
 * awarded what it did.
 */
class RuleBasedLeadScorer implements LeadScorer
{
    /** @var array<int, LeadScoringConfig> keyed by workspace, so scoring a page of leads is one query */
    private array $configCache = [];

    public function score(Lead $lead): LeadScore
    {
        $config = $this->config((int) $lead->workspace_id);
        $weights = $config->effectiveWeights();

        $rules = [
            'has_phone' => $this->hasPhone($lead),
            'rating' => $this->rating($lead),
            'review_volume' => $this->reviewVolume($lead),
            'has_website' => $this->hasWebsite($lead),
        ];

        // Weights are rescaled to total 100 so a tenant whose edits sum to 80 (or
        // 150) still gets comparable 0-100 scores.
        $totalWeight = array_sum($weights);
        $breakdown = [];
        $score = 0.0;

        foreach ($rules as $rule => [$ratio, $detail]) {
            $max = (int) round($weights[$rule] / $totalWeight * 100);
            $points = (int) round($ratio * $max);
            $score += $points;

            $breakdown[] = [
                'rule' => $rule,
                'points' => $points,
                'max' => $max,
                'detail' => $detail,
            ];
        }

        $score = (int) min(100, max(0, round($score)));

        return new LeadScore($score, $this->band($score, $config->effectiveThresholds()), $breakdown);
    }

    /** @return array{0: float, 1: string} ratio 0-1 and a human reason */
    private function hasPhone(Lead $lead): array
    {
        return $lead->phone
            ? [1.0, 'Phone number available']
            : [0.0, 'No phone number — cannot be messaged'];
    }

    private function rating(Lead $lead): array
    {
        $rating = (float) ($lead->rating ?? 0);
        if ($rating <= 0) {
            return [0.0, 'No Google rating'];
        }

        return [min(1.0, $rating / 5), sprintf('Rated %.1f / 5 on Google', $rating)];
    }

    /**
     * Review count scales logarithmically: the gap between 0 and 20 reviews says
     * far more about a business than the gap between 180 and 200.
     */
    private function reviewVolume(Lead $lead): array
    {
        $count = (int) ($lead->review_count ?? 0);
        if ($count <= 0) {
            return [0.0, 'No Google reviews'];
        }

        $ratio = min(1.0, log10($count + 1) / log10(LeadScoringConfig::REVIEW_SATURATION + 1));

        return [$ratio, sprintf('%d Google review%s', $count, $count === 1 ? '' : 's')];
    }

    private function hasWebsite(Lead $lead): array
    {
        return $lead->website
            ? [1.0, 'Has a website']
            : [0.0, 'No website listed'];
    }

    /** @param array{hot: int, warm: int} $thresholds */
    private function band(int $score, array $thresholds): string
    {
        return match (true) {
            $score >= $thresholds['hot'] => 'hot',
            $score >= $thresholds['warm'] => 'warm',
            default => 'cold',
        };
    }

    private function config(int $workspaceId): LeadScoringConfig
    {
        return $this->configCache[$workspaceId] ??= LeadScoringConfig::forWorkspace($workspaceId);
    }
}
