<?php

namespace App\Tenders;

final class EisRssQueryRelevanceFilter
{
    /**
     * @param  list<TenderSourceItem>  $items
     * @return list<TenderSourceItem>
     */
    public function filter(array $items, string $query): array
    {
        $terms = $this->terms($query);

        if ($terms === []) {
            return [];
        }

        return array_values(array_filter(
            $items,
            fn (TenderSourceItem $item): bool => $this->matches($item, $terms),
        ));
    }

    /**
     * @param  list<string>  $terms
     */
    private function matches(TenderSourceItem $item, array $terms): bool
    {
        $subject = $this->normalize(
            $item->title.' '.$this->withoutSearchPreamble($item->summary ?? ''),
        );

        foreach ($terms as $term) {
            if (! str_contains($subject, $this->stem($term))) {
                return false;
            }
        }

        return true;
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

    private function withoutSearchPreamble(string $summary): string
    {
        return preg_replace('/\A.*?найденный\s+результат\s*:\s*/uis', '', $summary) ?? $summary;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(str_replace('ё', 'е', $value), 'UTF-8');
    }

    private function stem(string $term): string
    {
        return mb_strlen($term) < 5 ? $term : mb_substr($term, 0, -1, 'UTF-8');
    }
}
