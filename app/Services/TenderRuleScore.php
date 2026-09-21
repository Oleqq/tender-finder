<?php

namespace App\Services;

use App\Models\SearchQuery;

/**
 * Calculates an explainable, no-cost score from the rules already selected by
 * a user. It is deliberately not an AI decision and never changes matching.
 */
final class TenderRuleScore
{
    /**
     * @param  list<string>  $matchedKeywords
     * @param  array<string, mixed>  $reasons
     */
    public function forMatch(SearchQuery $query, array $matchedKeywords, array $reasons): int
    {
        $keywords = array_values(array_filter($query->keywords ?? [], 'is_string'));
        $keywordScore = $keywords === []
            ? 0
            : (int) round(40 * count($matchedKeywords) / count($keywords));

        $score = $keywordScore;
        $score += ($reasons['region'] ?? null) === 'matched' ? 20 : 0;
        $score += ($reasons['budget'] ?? null) === 'matched' ? 20 : 0;
        $score += ($reasons['deadline'] ?? null) === 'matched' ? 20 : 0;

        return min($score, 100);
    }
}
