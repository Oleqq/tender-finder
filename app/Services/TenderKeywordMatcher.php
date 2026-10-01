<?php

namespace App\Services;

/** Small inflection tolerance; exact phrase mode remains literal. */
final class TenderKeywordMatcher
{
    /** @param list<string> $keywords */
    public function containsPhrase(string $text, array $keywords): bool
    {
        $needle = $this->words(implode(' ', $keywords));
        $haystack = $this->words($text);
        if ($needle === [] || $haystack === []) {
            return false;
        }

        foreach ($haystack as $start => $word) {
            if ($word !== $needle[0]) {
                continue;
            }

            $position = $start;
            foreach (array_slice($needle, 1) as $next) {
                $found = false;
                for ($candidate = $position + 1; $candidate <= min($position + 3, count($haystack) - 1); $candidate++) {
                    if ($haystack[$candidate] === $next) {
                        $position = $candidate;
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    public function contains(string $text, string $keyword): bool
    {
        $text = mb_strtolower($text);
        $keyword = mb_strtolower(trim($keyword));
        if ($keyword === '') {
            return false;
        }
        if (str_contains($text, $keyword)) {
            return true;
        }

        $normalizedKeyword = $this->normalize($keyword);

        return $normalizedKeyword !== '' && str_contains(' '.$this->normalize($text).' ', ' '.$normalizedKeyword.' ');
    }

    private function normalize(string $text): string
    {
        return implode(' ', $this->words($text));
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', str_replace('ё', 'е', mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(static function (string $word): string {
            // Keep at least four Cyrillic letters, preventing short words and
            // abbreviations from collapsing into a shared one-letter stem.
            return preg_replace('/^([а-я]{4,}?)(?:иями|ями|ами|ией|иям|ием|иях|ого|ему|ыми|ими|ая|яя|ую|юю|ое|ее|ые|ие|ия|ию|ий|ов|ев|ей|ам|ям|ах|ях|ом|ем|а|я|ы|и|е|у|ю|о|ь)$/u', '$1', $word) ?? $word;
        }, $words);
    }
}
