<?php

namespace App\Services\PriceCatalog;

final class CatalogMatcher
{
    /** @var array<string, list<string>> */
    private array $tokenCache = [];

    /** @param array<string, mixed> $requirement
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function candidates(array $requirement, array $items): array
    {
        $tokens = $this->tokens((string) $requirement['name']);
        $candidates = [];
        foreach ($items as $item) {
            $shared = array_values(array_intersect($tokens, $this->tokens($item['name'])));
            if ($shared === []) {
                continue;
            }
            $score = count($shared) / max(1, count($tokens));
            if ($score < .45) {
                continue;
            }
            $checks = $this->checks($requirement, $item);
            $exact = $this->normalize($requirement['name']) === $this->normalize($item['name']);
            $known = count(array_filter($checks, fn (array $check): bool => $check['state'] === 'ok'));
            $candidates[] = [
                'item' => $item, 'terms' => $shared, 'checks' => $checks,
                'score' => round($score * 100),
                'status' => $exact && $known === count($checks) ? 'matched' : 'uncertain',
                'rank' => $score + ($exact ? 1 : 0) + $known * .05,
            ];
        }
        usort($candidates, fn (array $a, array $b): int => ($b['rank'] <=> $a['rank']) ?: ($a['item']['id'] <=> $b['item']['id']));

        return array_slice($candidates, 0, 3);
    }

    /** @param array<string, mixed> $requirement
     * @param  array<string, mixed>  $item
     * @return list<array{label: string, state: string, detail: string}>
     */
    public function checks(array $requirement, array $item): array
    {
        $result = [];
        $required = $this->tokens((string) ($requirement['characteristics'] ?? ''));
        $available = $this->tokens((string) ($item['characteristics'] ?? ''));
        $result[] = $this->check('Характеристики', $required === [] || $available === [] ? null : array_diff($required, $available) === [],
            'Совпали указанные слова и числа.', 'Характеристики отличаются или переданы не полностью.');
        $quantity = $requirement['quantity'] ?? null;
        $stock = $item['quantity'] ?? null;
        $result[] = $this->check('Количество', $quantity === null || $stock === null ? null : (float) $stock >= (float) $quantity,
            'Указанного количества достаточно.', 'Указанное количество меньше требуемого.');
        $unit = $requirement['unit'] ?? null;
        $catalogUnit = $item['unit'] ?? null;
        $result[] = $this->check('Единица измерения', ! filled($unit) || ! filled($catalogUnit) ? null : $this->unit($unit) === $this->unit($catalogUnit),
            'Единицы совпадают.', 'Единицы различаются; пересчёт не выполняется.');
        $region = $requirement['region'] ?? null;
        $catalogRegion = $item['region'] ?? null;
        $result[] = $this->check('Регион', ! filled($region) || ! filled($catalogRegion) ? null : ($this->normalize($region) === $this->normalize($catalogRegion)
            || in_array($this->normalize($catalogRegion), ['вся россия', 'все регионы', 'любой'], true)),
            'Регион совпадает или в каталоге разрешены все регионы.', 'Регион требует уточнения.');

        return $result;
    }

    /** @return list<string> */
    public function tokens(string $text): array
    {
        if (isset($this->tokenCache[$text])) {
            return $this->tokenCache[$text];
        }
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(str_replace('ё', 'е', $text))) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = match ($part) {
                'сайта', 'сайтов', 'сайты', 'сайту', 'website', 'вебсайт' => 'сайт',
                'сео' => 'seo',
                default => $part,
            };
            if (mb_strlen($part) > 5 && preg_match('/^[а-я]+$/u', $part)) {
                $part = preg_replace('/(ами|ями|ого|ему|ому|ов|ев|ам|ям|ах|ях)$/u', '', $part) ?? $part;
            }
            if ((mb_strlen($part) >= 3 || ctype_digit($part)) && ! in_array($part, [
                'поставка', 'оказание', 'выполнение', 'работ', 'работы', 'услуг', 'услуги', 'закупка',
                'право', 'договора', 'заключение', 'нужд', 'компании', 'для', 'при', 'или',
                'разработка', 'создание', 'проведение',
            ], true)) {
                $tokens[] = $part;
            }
        }

        return $this->tokenCache[$text] = array_values(array_unique($tokens));
    }

    public function normalize(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(str_replace('ё', 'е', $value))) ?? '');
    }

    private function unit(string $unit): string
    {
        return match ($this->normalize($unit)) {
            'штука', 'штуки', 'штук', 'шт' => 'шт',
            'килограмм', 'килограммы', 'кг' => 'кг',
            'час', 'часов', 'ч' => 'ч',
            default => $this->normalize($unit),
        };
    }

    /** @return array{label: string, state: string, detail: string} */
    private function check(string $label, ?bool $ok, string $yes, string $no): array
    {
        return ['label' => $label, 'state' => $ok === null ? 'unknown' : ($ok ? 'ok' : 'different'),
            'detail' => $ok === null ? 'Не хватает данных в спецификации или каталоге.' : ($ok ? $yes : $no)];
    }
}
