<?php

use App\Enums\TenderUserStatus;
use App\Enums\UserRole;
use App\Models\LocalMvpSearchSnapshot;
use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderUserState;
use App\Models\User;
use App\Services\TenderExportService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function (): void {
    config()->set('tender.local_mvp_operator.enabled', true);
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('stores personal notes tags and next actions without exposing them to another user', function () {
    [$operator, $tender] = productivityTender($this, '=Проверить условия контракта');

    $this->patchJson("/local/mvp/tenders/{$tender->id}/annotation", [
        'note' => 'Позвонить заказчику и проверить обеспечение.',
        'tags' => [' Приоритет ', 'позвонить', 'ПРИОРИТЕТ'],
        'next_action_on' => '2026-09-05',
    ])
        ->assertOk()
        ->assertJsonPath('tender.note', 'Позвонить заказчику и проверить обеспечение.')
        ->assertJsonPath('tender.tags.0', 'ПРИОРИТЕТ')
        ->assertJsonPath('tender.tags.1', 'позвонить')
        ->assertJsonPath('tender.next_action_on', '2026-09-05');

    $this->postJson("/local/mvp/tenders/{$tender->id}/status", ['status' => 'favorite'])->assertOk();
    $this->postJson("/local/mvp/tenders/{$tender->id}/status", ['status' => 'new'])->assertOk();

    $this->assertDatabaseHas('tender_user_states', [
        'user_id' => $operator->id,
        'tender_id' => $tender->id,
        'status' => TenderUserStatus::New->value,
        'note' => 'Позвонить заказчику и проверить обеспечение.',
    ]);

    $otherAdmin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
        'telegram_id' => 'productivity-other-admin',
    ]);
    $this->actingAs($otherAdmin)
        ->patchJson("/local/mvp/tenders/{$tender->id}/annotation", ['note' => 'Чужая заметка'])
        ->assertNotFound();
});

it('exports accessible cards to safe CSV and a valid XLSX workbook', function () {
    [$operator, $tender] = productivityTender($this, '=HYPERLINK("https://evil.test")');
    TenderUserState::query()->create([
        'user_id' => $operator->id,
        'tender_id' => $tender->id,
        'status' => TenderUserStatus::Potential,
        'note' => 'Проверить документы',
        'tags' => ['приоритет'],
        'next_action_on' => '2026-09-06',
    ]);

    $csv = $this->postJson('/local/mvp/tenders/export', [
        'format' => 'csv',
        'scope' => 'current',
        'tender_ids' => [$tender->id],
        'filter_summary' => 'Текущая выдача · тег: приоритет',
    ]);
    $csv->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload();
    expect($csv->getContent())->toContain("'=HYPERLINK")
        ->toContain('Проверить документы')
        ->toContain('Текущая выдача');

    expect(app(TenderExportService::class)->csv([], '  =DANGEROUS'))
        ->toContain("'  =DANGEROUS");

    $xlsx = $this->postJson('/local/mvp/tenders/export', [
        'format' => 'xlsx',
        'scope' => 'selected',
        'tender_ids' => [$tender->id],
        'filter_summary' => 'Выбрано вручную: 1',
    ]);
    $xlsx->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertDownload();
    expect(substr((string) $xlsx->getContent(), 0, 2))->toBe('PK');

    $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    file_put_contents($path, $xlsx->getContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue()
        ->and($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('Проверить документы');
    $zip->close();
    unlink($path);
});

it('exports only cards new in a selected saved-search run', function () {
    [$operator, $first] = productivityTender($this, 'Первая закупка');
    $second = Tender::query()->create([
        'source' => 'eis_rss',
        'external_id' => 'productivity-second',
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456780',
        'canonical_url_hash' => hash('sha256', 'productivity-second'),
        'title' => 'Новая закупка запуска',
        'currency' => 'RUB',
    ]);
    $query = SearchQuery::query()->create([
        'user_id' => $operator->id,
        'name' => 'Экспорт новых',
        'keywords' => ['закупка'],
        'status' => 'active',
    ]);
    LocalMvpSearchSnapshot::query()->where('user_id', $operator->id)->delete();
    LocalMvpSearchSnapshot::query()->create([
        'user_id' => $operator->id,
        'search_query_id' => $query->id,
        'query' => 'закупка',
        'source' => 'eis_rss',
        'tender_ids' => [$first->id],
    ]);
    $run = LocalMvpSearchSnapshot::query()->create([
        'user_id' => $operator->id,
        'search_query_id' => $query->id,
        'query' => 'закупка',
        'source' => 'eis_rss',
        'tender_ids' => [$first->id, $second->id],
    ]);

    $response = $this->postJson('/local/mvp/tenders/export', [
        'format' => 'csv',
        'scope' => 'run_new',
        'query_id' => $query->id,
        'run_id' => $run->id,
    ])->assertOk();

    expect($response->getContent())->toContain('Новая закупка запуска')
        ->not->toContain('Первая закупка');
});

/** @return array{User, Tender} */
function productivityTender($test, string $title): array
{
    $test->get('/local/mvp-operator')->assertOk();
    $operator = User::query()->where('email', 'local-mvp-operator@tenderfinder.invalid')->firstOrFail();
    $tender = Tender::query()->create([
        'source' => 'eis_rss',
        'external_id' => 'productivity-'.md5($title),
        'reg_number' => '01234567890123456789',
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456789',
        'canonical_url_hash' => hash('sha256', 'productivity-'.$title),
        'title' => $title,
        'budget_amount' => '250000.00',
        'currency' => 'RUB',
        'metadata' => [
            'customer' => 'Тестовый заказчик',
            'delivery_place' => 'г. Москва',
        ],
    ]);
    LocalMvpSearchSnapshot::query()->create([
        'user_id' => $operator->id,
        'query' => $title,
        'source' => 'eis_rss',
        'tender_ids' => [$tender->id],
    ]);

    return [$operator, $tender];
}
