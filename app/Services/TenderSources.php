<?php

namespace App\Services;

use App\Models\SearchQuery;

final class TenderSources
{
    public const LABELS = [
        'rostender' => 'RosTender',
        'sber_ast' => 'Сбер АСТ (прямой реестр)',
        'workspace_ru' => 'Workspace.ru',
        'etp_gpb' => 'ЭТП ГПБ',
        'bidzaar' => 'Bidzaar',
        'etp_region' => 'ЭТП РЕГИОН',
        'b2b_center' => 'B2B-Center',
        'roseltorg' => 'Росэлторг',
        'rts_tender' => 'РТС-Тендер',
        'sber_ast_catalog' => 'Сбер АСТ (каталог)',
    ];

    /** Missing/null selection keeps the legacy "all connected sources" mode.
     * @return list<string>|null
     */
    public static function selected(SearchQuery $query): ?array
    {
        $value = $query->filters['source']['platforms'] ?? null;

        return is_array($value) ? array_values(array_intersect(array_keys(self::LABELS), array_filter($value, 'is_string'))) : null;
    }

    public static function allows(SearchQuery $query, ?string $source): bool
    {
        $selected = self::selected($query);

        return $selected === null || in_array($source, $selected, true);
    }

    public static function label(string $source): string
    {
        return self::LABELS[$source] ?? 'Источник закупки';
    }
}
