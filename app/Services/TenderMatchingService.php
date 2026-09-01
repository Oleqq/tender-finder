<?php

namespace App\Services;

use App\Enums\QueryStatus;
use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Tenders\EisRssMatchMode;

class TenderMatchingService
{
    public function evaluate(SearchQuery $query, Tender $tender): TenderMatchResult
    {
        $haystack = $this->lower($tender->title.' '.$tender->description);
        $keywords = array_filter($query->keywords ?? [], 'is_string');
        $mode = $this->matchMode($query);
        $matchedKeywords = array_values(array_filter(
            $keywords,
            fn (string $keyword): bool => str_contains($haystack, $this->lower($keyword)),
        ));
        $missingKeywords = array_values(array_diff($keywords, $matchedKeywords));
        $matchesKeywords = match ($mode) {
            EisRssMatchMode::All => $missingKeywords === [],
            EisRssMatchMode::Any => $matchedKeywords !== [],
            EisRssMatchMode::Exact => str_contains(
                $haystack,
                $this->lower(implode(' ', $keywords)),
            ),
        };

        if (! $matchesKeywords) {
            return new TenderMatchResult(false, ['excluded_by' => 'keyword', 'missing_keywords' => $missingKeywords]);
        }

        $minusKeywords = array_filter($query->minus_keywords ?? [], 'is_string');
        $matchedMinusKeywords = array_values(array_filter($minusKeywords, fn (string $keyword): bool => str_contains($haystack, $this->lower($keyword))));

        if ($matchedMinusKeywords !== []) {
            return new TenderMatchResult(false, ['excluded_by' => 'minus_keyword', 'matched_minus_keywords' => $matchedMinusKeywords]);
        }

        $reasons = [
            'keywords' => $mode === EisRssMatchMode::Any ? $matchedKeywords : array_values($keywords),
            'match_mode' => $mode->value,
        ];

        if ($query->region !== null) {
            if ($tender->region !== null && $this->lower($query->region) !== $this->lower($tender->region)) {
                return new TenderMatchResult(false, ['excluded_by' => 'region', 'expected_region' => $query->region]);
            }

            $reasons['region'] = $tender->region === null ? 'unknown' : 'matched';
        }

        if ($query->budget_min !== null || $query->budget_max !== null) {
            if ($tender->budget_amount === null) {
                $reasons['budget'] = 'unknown';
            } elseif (($query->budget_min !== null && (float) $tender->budget_amount < (float) $query->budget_min)
                || ($query->budget_max !== null && (float) $tender->budget_amount > (float) $query->budget_max)) {
                return new TenderMatchResult(false, ['excluded_by' => 'budget']);
            } else {
                $reasons['budget'] = 'matched';
            }
        }

        if ($query->deadline_from !== null || $query->deadline_to !== null) {
            if ($tender->deadline_at === null) {
                $reasons['deadline'] = 'unknown';
            } elseif (($query->deadline_from !== null && $tender->deadline_at->lt($query->deadline_from))
                || ($query->deadline_to !== null && $tender->deadline_at->gt($query->deadline_to->endOfDay()))) {
                return new TenderMatchResult(false, ['excluded_by' => 'deadline']);
            } else {
                $reasons['deadline'] = 'matched';
            }
        }

        return new TenderMatchResult(true, $reasons);
    }

    public function matchTender(Tender $tender, bool $queueNotifications = true): int
    {
        $matches = 0;

        SearchQuery::query()
            ->where('status', QueryStatus::Active)
            ->each(function (SearchQuery $query) use ($tender, $queueNotifications, &$matches): void {
                $result = $this->evaluate($query, $tender);

                if (! $result->matches) {
                    return;
                }

                $match = TenderQueryMatch::query()->firstOrCreate(
                    ['tender_id' => $tender->id, 'search_query_id' => $query->id],
                    ['match_reasons' => $result->reasons, 'matched_at' => now()],
                );

                if ($match->wasRecentlyCreated) {
                    $matches++;

                    if ($queueNotifications) {
                        app(NotificationService::class)->queueForMatch($match);
                    }
                }
            });

        return $matches;
    }

    private function lower(?string $value): string
    {
        return mb_strtolower($value ?? '');
    }

    private function matchMode(SearchQuery $query): EisRssMatchMode
    {
        $filters = is_array($query->filters) ? $query->filters : [];
        $relevance = is_array($filters['relevance'] ?? null) ? $filters['relevance'] : [];
        $value = is_string($relevance['match_mode'] ?? null)
            ? $relevance['match_mode']
            : '';

        return EisRssMatchMode::tryFrom($value) ?? EisRssMatchMode::All;
    }
}
