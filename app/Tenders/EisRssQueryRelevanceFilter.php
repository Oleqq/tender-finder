<?php

namespace App\Tenders;

final class EisRssQueryRelevanceFilter
{
    /**
     * @param  list<TenderSourceItem>  $items
     */
    public function filter(array $items, EisRssRelevanceCriteria $criteria): EisRssRelevanceFilterResult
    {
        $terms = $this->terms($criteria->phrase);

        if ($terms === []) {
            return new EisRssRelevanceFilterResult([], []);
        }

        $matches = [];
        $reasons = [];

        foreach ($items as $item) {
            $reason = $this->matchReason($item, $criteria, $terms);

            if ($reason === null) {
                continue;
            }

            $matches[] = $item;
            $reasons[$item->externalId] = $reason;
        }

        return new EisRssRelevanceFilterResult($matches, $reasons);
    }

    /**
     * @param  list<string>  $terms
     * @return array{mode: string, matched_terms: list<string>, minus_keywords_checked: list<string>}|null
     */
    private function matchReason(
        TenderSourceItem $item,
        EisRssRelevanceCriteria $criteria,
        array $terms,
    ): ?array {
        $subject = $this->normalizedWords(
            $item->title.' '.$this->withoutSearchPreamble($item->summary ?? ''),
        );
        $minusKeywords = $this->minusKeywords($criteria->minusKeywords);

        if ($this->containsExcludedPhrase($subject, $minusKeywords)) {
            return null;
        }

        $matchedTerms = match ($criteria->matchMode) {
            EisRssMatchMode::All => $this->allTermsMatch($subject, $terms) ? $terms : [],
            EisRssMatchMode::Any => array_values(array_filter(
                $terms,
                fn (string $term): bool => str_contains($subject, $this->stem($term)),
            )),
            EisRssMatchMode::Exact => str_contains(
                $subject,
                $this->normalizedWords($criteria->phrase),
            ) ? [trim($criteria->phrase)] : [],
        };

        if ($matchedTerms === []) {
            return null;
        }

        return [
            'mode' => $criteria->matchMode->value,
            'matched_terms' => $matchedTerms,
            'minus_keywords_checked' => $minusKeywords,
        ];
    }

    /** @return list<string> */
    private function terms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $this->normalize($query), $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            fn (string $term): bool => mb_strlen($term) >= 2,
        )));
    }

    /**
     * @param  list<string>  $terms
     */
    private function allTermsMatch(string $subject, array $terms): bool
    {
        foreach ($terms as $term) {
            if (! str_contains($subject, $this->stem($term))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $minusKeywords
     * @return list<string>
     */
    private function minusKeywords(array $minusKeywords): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $keyword): string => $this->normalizedWords($keyword),
            $minusKeywords,
        ))));
    }

    /** @param list<string> $minusKeywords */
    private function containsExcludedPhrase(string $subject, array $minusKeywords): bool
    {
        foreach ($minusKeywords as $keyword) {
            if (str_contains($subject, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function withoutSearchPreamble(string $summary): string
    {
        return preg_replace('/\A.*?найденный\s+результат\s*:\s*/uis', '', $summary) ?? $summary;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(str_replace('ё', 'е', $value), 'UTF-8');
    }

    private function normalizedWords(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $this->normalize($value)) ?? '');
    }

    private function stem(string $term): string
    {
        return mb_strlen($term) < 5 ? $term : mb_substr($term, 0, -1, 'UTF-8');
    }
}
