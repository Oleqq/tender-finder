<?php

use App\Tenders\EisRssSource;

it('turns EIS technical RSS fields into a readable tender card', function () {
    $result = app(EisRssSource::class)->parse(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>Электронный аукцион №01234567890123456789</title>
      <link>https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456789</link>
      <description><![CDATA[Параметры поиска: Искомое слово: разработка сайта. Найденный результат: Электронный аукцион №01234567890123456789Наименование объекта закупки: Оказание услуг по разработке и созданию официального сайтаНачальная цена контракта: 728800.00 Валюта: Российский рубльРазмещение выполняется по: 44-ФЗНаименование Заказчика: Тестовый заказчик]]></description>
      <pubDate>Mon, 24 Aug 2026 10:00:00 +0300</pubDate>
    </item>
  </channel>
</rss>
XML);

    expect($result->items)->toHaveCount(1)
        ->and($result->items[0]->title)->toBe('Оказание услуг по разработке и созданию официального сайта')
        ->and($result->items[0]->summary)->toBe('Электронный аукцион · 44-ФЗ')
        ->and($result->items[0]->budgetAmount)->toBe('728800.00')
        ->and($result->items[0]->metadata)->toBe([
            'customer' => 'Тестовый заказчик',
            'category' => 'Электронный аукцион',
            'procurement_law' => '44',
        ]);
});
