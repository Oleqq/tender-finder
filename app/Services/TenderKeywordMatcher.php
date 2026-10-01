<?php

namespace App\Services;

/** Small inflection tolerance; exact phrase mode remains literal. */
final class TenderKeywordMatcher
{
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
        $words = preg_split('/[^\p{L}\p{N}]+/u', str_replace('ё', 'е', $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(static function (string $word): string {
            // Keep at least four Cyrillic letters, preventing short words and
            // abbreviations from collapsing into a shared one-letter stem.
            return preg_replace('/^([а-я]{4,}?)(?:иями|ями|ами|ией|иям|ием|иях|ого|ему|ыми|ими|ая|яя|ую|юю|ое|ее|ые|ие|ия|ию|ий|ов|ев|ей|ам|ям|ах|ях|ом|ем|а|я|ы|и|е|у|ю|о|ь)$/u', '$1', $word) ?? $word;
        }, $words));
    }
}
