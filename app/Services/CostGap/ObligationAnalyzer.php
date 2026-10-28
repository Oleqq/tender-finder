<?php

namespace App\Services\CostGap;

use App\Services\PriceCatalog\CatalogMatcher;
use Illuminate\Validation\ValidationException;

final class ObligationAnalyzer
{
    private const TYPES = [
        'delivery' => ['Доставка', 'достав\p{L}*|транспортиров\p{L}*'],
        'installation' => ['Монтаж и установка', 'монтаж\p{L}*|установк\p{L}*'],
        'commissioning' => ['Пусконаладка', 'пусконалад\p{L}*|наладк\p{L}*'],
        'training' => ['Обучение и инструктаж', 'обучен\p{L}*|инструктаж\p{L}*'],
        'loading' => ['Погрузка, разгрузка и подъём', 'погруз\p{L}*|разгруз\p{L}*|подъем\p{L}*'],
        'packaging' => ['Упаковка и тара', 'упаков\p{L}*|тар[аыуе]'],
        'warranty' => ['Гарантийные обязательства', 'гаранти\p{L}*'],
        'support' => ['Сопровождение и обслуживание', 'сопровожд\p{L}*|обслуживан\p{L}*|поддержк\p{L}*'],
        'insurance' => ['Страхование', 'страхов\p{L}*'],
        'travel' => ['Командировки, проезд и проживание', 'командиров\p{L}*|проезд\p{L}*|проживан\p{L}*'],
        'certification' => ['Сертификация и испытания', 'сертифика\p{L}*|испытан\p{L}*'],
        'licenses' => ['Лицензии', 'лицензи\p{L}*'],
        'disposal' => ['Вывоз и утилизация', 'утилиза\p{L}*|вывоз\p{L}*'],
        'taxes' => ['Налоги и НДС', 'ндс|налог\p{L}*'],
    ];

    private const NEGATIVE = '/\b(?:без|не\s+(?:включ\p{L}*|вход\p{L}*|предусмотр\p{L}*|выполня\p{L}*|осуществля\p{L}*)|отдельн\p{L}*\s+(?:плат\p{L}*|оплат\p{L}*))\b/iu';

    private const CONDITIONAL = '/\?|\b(?:возмож\p{L}*|уточн\p{L}*|опциональ\p{L}*|предполага\p{L}*|если|в\s+случае|только|по\s+(?:запросу|согласованию)|при\s+условии|дополнител\p{L}*\s+(?:оплат\p{L}*|плат\p{L}*))/iu';

    private const POSITIVE = '/\b(?:включ(?:[её]н[аоы]?|аем|им)|входит|входят|предусмотрен[аоы]?|обеспеч(?:им|иваем)|выполн(?:им|ен[аоы]?|яем)|окажем|осуществ(?:им|ляем))\b/iu';

    public function __construct(private readonly CatalogMatcher $words) {}

    /** @param array<string, mixed> $required
     * @param  array<string, mixed>  $offer
     * @return list<array<string, mixed>>
     */
    public function compare(array $required, array $offer, string $manual): array
    {
        $rows = [];
        foreach (self::TYPES as $key => [$title, $pattern]) {
            $evidence = [];
            foreach ($required['segments'] as $segment) {
                if ($this->matches($pattern, $segment['text'])) {
                    $evidence[] = $segment;
                }
            }
            if ($evidence !== []) {
                $rows[$key] = $this->row($key, $title, $evidence, $offer, $pattern);
            }
        }
        foreach ($required['segments'] as $segment) {
            if (! preg_match('/\b(?:обязан\p{L}*|необходим\p{L}*|требуется|требуются|предоставить|обеспечить|выполнить)\b/iu', $segment['text'])) {
                continue;
            }
            $known = false;
            foreach (self::TYPES as [$title, $pattern]) {
                $known = $known || $this->matches($pattern, $segment['text']);
            }
            if (! $known) {
                $label = mb_substr($segment['text'], 0, 160);
                $key = 'text:'.hash('sha256', $this->words->normalize($segment['text']));
                $rows[$key] = $this->row($key, $label, [$segment], $offer);
            }
        }
        foreach (preg_split('/\R/u', trim($manual)) ?: [] as $line => $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            if (mb_strlen($label) > 300) {
                throw ValidationException::withMessages(['manual_requirements' => 'Одно дополнительное требование — до 300 знаков.']);
            }
            $matched = [];
            foreach (self::TYPES as $type => [$title, $pattern]) {
                if ($this->matches($pattern, $label)) {
                    $matched[] = $type;
                }
            }
            $evidence = [['ref' => 'Ваш перечень, строка '.($line + 1), 'text' => $label]];
            if (count($matched) === 1) {
                $key = $matched[0];
                [$title, $pattern] = self::TYPES[$key];
                $rows[$key] = $this->row($key, $title, [...($rows[$key]['requirement_evidence'] ?? []), ...$evidence], $offer, $pattern);
            } else {
                $key = 'manual:'.hash('sha256', $this->words->normalize($label));
                $rows[$key] = $this->row($key, $label, $evidence, $offer);
            }
        }
        if (count($rows) > 100) {
            throw ValidationException::withMessages(['manual_requirements' => 'Допускается до 100 требований. Сравните разделы отдельно.']);
        }

        return array_values($rows);
    }

    private function matches(string $pattern, string $text): bool
    {
        return preg_match('/\b(?:'.$pattern.')\b/iu', str_replace('ё', 'е', mb_strtolower($text))) === 1;
    }

    /** @param list<array{ref: string, text: string}> $evidence
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>
     */
    private function row(string $key, string $title, array $evidence, array $offer, ?string $pattern = null): array
    {
        $mentions = [];
        $heading = null;
        $tokens = $this->words->tokens($title);
        foreach ($offer['segments'] as $segment) {
            $text = $segment['text'];
            if (preg_match('/:\s*$/u', $text)) {
                $heading = preg_match(self::POSITIVE, $text) && ! preg_match(self::NEGATIVE, $text) && ! preg_match(self::CONDITIONAL, $text) ? $segment : null;
            }
            $mentioned = $pattern !== null ? $this->matches($pattern, $text)
                : ($tokens !== [] && count(array_intersect($tokens, $this->words->tokens($text))) / count($tokens) >= .75);
            if ($mentioned) {
                $uncertain = preg_match(self::NEGATIVE, $text) || preg_match(self::CONDITIONAL, $text);
                $positive = preg_match(self::POSITIVE, $text) || $heading !== null;
                $mentions[] = [...$segment, 'explicit' => ! $uncertain && $positive,
                    'context' => $heading !== null && $heading['ref'] !== $segment['ref'] ? $heading : null];
            }
            // A new prose paragraph closes a preceding list heading.
            if (! preg_match('/:\s*$/u', $text) && preg_match('/[.!?]$/u', $text)) {
                $heading = null;
            }
        }
        $explicit = count(array_filter($mentions, fn (array $mention): bool => $mention['explicit']));
        $status = $mentions === [] ? 'not_mentioned' : ($explicit === count($mentions) ? 'included' : 'clarify');
        $requirementUncertain = count(array_filter($evidence, fn (array $item): bool => (bool) preg_match('/\bне\s+(?:требу\p{L}*|предусмотр\p{L}*)\b|при\s+необходимости|по\s+согласованию/iu', $item['text']))) > 0;
        if ($requirementUncertain) {
            $status = 'clarify';
        }

        return ['key' => $key, 'title' => $title, 'status' => $status, 'requirement_evidence' => $evidence, 'offer_evidence' => $mentions,
            'reason' => $requirementUncertain ? 'В требованиях есть отрицание или условие. Уточните, требуется ли обязательство и в каком объёме.' : match ($status) {
                'included' => 'Найдена формулировка включения. Проверьте объём и условия по цитатам.',
                'not_mentioned' => 'В извлечённом тексте предложения не найдено явного упоминания. Это не доказывает отсутствие услуги.',
                default => 'Есть упоминание, исключение, условие или неоднозначная формулировка. Требуется проверка.',
            }];
    }
}
