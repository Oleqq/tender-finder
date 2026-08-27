<?php

use App\Models\SourceFeed;
use App\Models\Tender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('probes one allow-listed RSS feed without storing tender records or exposing item data', function () {
    Http::fake([
        'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>Проверяемая закупка 01234567890123456789</title>
      <link>https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456789</link>
      <description>Проверяемое описание</description>
      <pubDate>Tue, 25 Aug 2026 10:00:00 +0300</pubDate>
    </item>
  </channel>
</rss>
XML),
    ]);

    $this->artisan('tenders:probe-eis-rss', [
        'url' => 'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html?searchString=fixture',
    ])
        ->expectsOutputToContain('RSS preflight succeeded.')
        ->expectsOutputToContain('Items parsed')
        ->doesntExpectOutputToContain('Проверяемая закупка')
        ->doesntExpectOutputToContain('01234567890123456789')
        ->assertExitCode(0);

    expect(Tender::query()->count())->toBe(0)
        ->and(SourceFeed::query()->count())->toBe(0);
});

it('keeps the preflight local and reports an allow-list failure without making a request', function () {
    Http::fake();

    $this->artisan('tenders:probe-eis-rss', [
        'url' => 'https://example.test/feed.xml',
    ])
        ->expectsOutputToContain('RSS preflight failed: url_not_allowed.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('reports a TLS validation failure separately from a generic connection failure', function () {
    Http::fake(fn (): never => throw new ConnectionException('cURL error 60: SSL certificate problem'));

    $this->artisan('tenders:probe-eis-rss', [
        'url' => 'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html?searchString=fixture',
    ])
        ->expectsOutputToContain('RSS preflight failed: tls_failed.')
        ->assertExitCode(1);
});
