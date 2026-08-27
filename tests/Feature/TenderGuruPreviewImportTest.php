<?php

use App\Jobs\MatchTender;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceRun;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\RssPollingDispatcher;
use App\Tenders\TenderGuruPreviewSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('imports a documented TenderGuru preview response locally without a key or notification delivery', function () {
    Queue::fake();
    $user = User::factory()->create(['telegram_id' => '9005']);
    SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => 'Локальный preview',
        'keywords' => ['поддержка', 'сайт'],
        'minus_keywords' => ['строительство'],
        'region' => 'Москва',
        'status' => 'active',
        'monitoring_started_at' => now(),
    ]);
    Http::fake([
        'https://www.tenderguru.ru/api2.3/export*' => Http::response(<<<'JSON'
[
  {"Total":"2"},
  {
    "ID":"9990000",
    "Date":"26-08-2026",
    "TenderName":"Разработка проектной документации для тепловых сетей",
    "Category":"Строительство",
    "Region":"Москва",
    "Price":"250000",
    "EndTime":"03-09-2026",
    "TenderNumOuter":"01234567890123456780",
    "Fz":"44",
    "searchFragmentXML":{"fragment":["Разработка проектов теплоснабжения"]}
  },
  {
    "ID":"9990001",
    "Date":"26-08-2026",
    "TenderName":"Техническая поддержка корпоративного сайта",
    "Category":"IT",
    "Region":"Москва",
    "Price":"150000",
    "EndTime":"03-09-2026",
    "TenderNumOuter":"01234567890123456789",
    "Fz":"44",
    "searchFragmentXML":{"fragment":["Техническая [B]поддержка[/B] сайта"]}
  }
]
JSON),
    ]);

    $this->artisan('tenders:import-tenderguru-preview', ['query' => 'поддержка сайта'])
        ->expectsOutputToContain('TenderGuru preview import finished: 1 new item(s), 2 item(s) seen, 1 new local match(es).')
        ->doesntExpectOutputToContain('поддержка сайта')
        ->doesntExpectOutputToContain('Техническая поддержка корпоративного сайта')
        ->assertExitCode(0);

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://www.tenderguru.ru/api2.3/export?')
            && $query === ['kwords' => '"поддержка сайта"', 'dtype' => 'json', 'actual' => '1'];
    });
    Queue::assertNotPushed(MatchTender::class);
    expect(SourceFeed::query()->where('status', 'manual_preview')->count())->toBe(1)
        ->and(Tender::query()->where('source', 'tenderguru_preview')->firstOrFail())
        ->title->toBe('Техническая поддержка корпоративного сайта')
        ->and(Tender::query()->where('source', 'tenderguru_preview')->count())
        ->toBe(1)
        ->and(Tender::query()->where('source', 'tenderguru_preview')->firstOrFail())
        ->region->toBe('Москва')
        ->budget_amount->toBe('150000.00')
        ->and(TenderQueryMatch::query()->count())->toBe(1);

    expect(SourceRun::query()->value('items_seen'))->toBe(2);
});

it('rejects malformed TenderGuru preview data without storing anything', function () {
    Http::fake([
        'https://www.tenderguru.ru/api2.3/export*' => Http::response('{not-json'),
    ]);

    $this->artisan('tenders:import-tenderguru-preview', ['query' => 'сайт'])
        ->expectsOutputToContain('TenderGuru preview import failed: invalid_json.')
        ->assertExitCode(1);

    expect(SourceFeed::query()->count())->toBe(0)
        ->and(Tender::query()->count())->toBe(0);
});

it('keeps only preview cards whose titles contain every meaningful word from the manual phrase', function () {
    Http::fake([
        'https://www.tenderguru.ru/api2.3/export*' => Http::response(<<<'JSON'
[
  {"Total":"2"},
  {
    "ID":"9990003",
    "TenderName":"Разработка проектной документации для газопровода",
    "searchFragmentXML":{"fragment":["Официальный сайт заказчика: разработка проектной документации"]}
  },
  {
    "ID":"9990004",
    "TenderName":"Разработка сайта учреждения",
    "searchFragmentXML":{"fragment":["Создание и разработка корпоративного сайта"]}
  }
]
JSON),
    ]);

    $result = app(TenderGuruPreviewSource::class)->fetch('Разработка сайта');
    $items = $result->items;

    expect($items)->toHaveCount(1)
        ->and($result->itemsReturned)
        ->toBe(2)
        ->and($items[0]->externalId)->toBe('9990004')
        ->and($items[0]->title)->toBe('Разработка сайта учреждения');
});

it('does not make a network request for an invalid preview query', function () {
    Http::fake();

    $this->artisan('tenders:import-tenderguru-preview', ['query' => 'x'])
        ->expectsOutputToContain('TenderGuru preview import failed: invalid_query.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('keeps the manual preview feed out of the RSS scheduler', function () {
    Queue::fake();
    config()->set('tender.rss.live_polling_enabled', true);
    SourceFeed::query()->create([
        'canonical_url' => 'tenderguru-preview://test-hash',
        'url_hash' => hash('sha256', 'manual-preview-feed'),
        'status' => 'manual_preview',
        'poll_interval_seconds' => 0,
        'next_poll_at' => now()->subMinute(),
    ]);

    expect(app(RssPollingDispatcher::class)->dispatchOneDueFeed())->toBeFalse();
    Queue::assertNothingPushed();
});
