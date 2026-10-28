<?php

use App\Services\CostGap\DocumentParser;
use App\Services\CostGap\ObligationAnalyzer;
use App\Services\PriceCatalog\CatalogMatcher;

it('treats exclusions optional work and conflicting mentions conservatively', function (string $offer, string $expected) {
    $parser = new DocumentParser;
    $analyzer = new ObligationAnalyzer(new CatalogMatcher);
    $rows = $analyzer->compare(['segments' => $parser->segments('Требуется доставка.')], ['segments' => $parser->segments($offer)], '');
    expect($rows[0]['status'])->toBe($expected);
})->with([
    ['Доставка включена в стоимость.', 'included'],
    ['Доставка не включена.', 'clarify'],
    ['Доставка возможна за дополнительную плату.', 'clarify'],
    ['Доставка включена? Уточнить стоимость.', 'clarify'],
    ['Доставка по запросу.', 'clarify'],
    ['В стоимость входят:'."\n".'доставка', 'included'],
    ['В стоимость не входят:'."\n".'доставка', 'clarify'],
    ['Доставка включена. Доставка оплачивается отдельно.', 'clarify'],
    ['Доставка — 5 000 рублей.', 'clarify'],
    ['Необходимо включить доставку в стоимость.', 'clarify'],
    ['Доставка включена, если заказчик согласует маршрут.', 'clarify'],
    ['Поставка товара за 100 000 рублей.', 'not_mentioned'],
]);

it('does not discard mixed customer exclusions and recognizes a manual obligation without duplicating a known category', function () {
    $parser = new DocumentParser;
    $analyzer = new ObligationAnalyzer(new CatalogMatcher);
    $rows = collect($analyzer->compare(['segments' => $parser->segments('Доставка не требуется, монтаж необходим.')],
        ['segments' => $parser->segments('Монтаж включён.')], "Доставка до склада\nДоставка до склада\nЕжедневная уборка рабочего места"))->keyBy('key');
    expect($rows)->toHaveCount(3);
    expect($rows['installation']['status'])->toBe('clarify');
    expect($rows['delivery']['requirement_evidence'])->toHaveCount(3);
    expect($rows->last()['title'])->toBe('Ежедневная уборка рабочего места');
    expect($rows->last()['requirement_evidence'][0]['ref'])->toBe('Ваш перечень, строка 3');
});
