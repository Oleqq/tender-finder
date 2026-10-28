<?php

use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\PriceCatalog;
use App\Models\PriceCatalogComparison;
use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\PlanCatalog;
use App\Services\PriceCatalog\CatalogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    config()->set(['tender.local_mvp_full_access.enabled' => false, 'tender.local_mvp_operator.enabled' => false,
        'tender.temporary_full_access.enabled' => false]);
    Http::preventStrayRequests();
});

it('requires authentication and keeps the paid catalog locked for Basic', function () {
    $this->get('/catalog')->assertRedirect('/onboarding');
    [$user] = priceFixture('basic');
    $this->actingAs($user)->get('/catalog?plan=ultra')->assertInertia(fn (Assert $p) => $p->component('PriceCatalog')->where('enabled', false)->where('catalog.items', []));
    $this->get('/catalog/template')->assertForbidden();
    $this->get('/catalog/recommendations')->assertForbidden();
    $this->postJson('/catalog/items', ['version' => 0, 'name' => 'Сайт'])->assertForbidden();
    expect(PriceCatalog::query()->count())->toBe(0);
});

it('imports XLSX atomically and preserves decimal zero and unknown values', function (string $plan) {
    [$user] = priceFixture($plan);
    $this->actingAs($user)->post('/catalog/import', ['version' => 0, 'file' => priceXlsx([
        ['Название', 'Характеристики', 'Цена', 'Количество', 'Единица', 'Регион'],
        ['Ноутбук', '16 ГБ', '12 345,67', '2,500', 'шт', 'Москва'],
        ['SEO', null, '0', null, null, 'Все регионы'],
    ])], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('catalog.version', 1)
        ->assertJsonPath('catalog.items.0.price', '12345.67')->assertJsonPath('catalog.items.0.quantity', '2.500')
        ->assertJsonPath('catalog.items.1.price', '0.00')->assertJsonPath('catalog.items.1.quantity', null);
    $this->get('/catalog')->assertInertia(fn (Assert $p) => $p->where('enabled', true)->has('catalog.items', 2));
    Http::assertNothingSent();
})->with(['pro', 'ultra']);

it('rejects an invalid replacement without changing the previous catalog or version', function (array $rows) {
    [$user] = priceFixture('pro');
    $this->actingAs($user)->postJson('/catalog/items', ['version' => 0, 'name' => 'Мой товар'])->assertOk();
    $this->post('/catalog/import', ['version' => 1, 'file' => priceXlsx($rows)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $catalog = app(CatalogService::class)->present($user);
    expect($catalog['version'])->toBe(1)->and($catalog['items'][0]['name'])->toBe('Мой товар');
})->with([
    'wrong header' => [[['Ошибка'], ['Строка']]],
    'negative price' => [[['Название', 'Цена'], ['Товар', '-1']]],
    'invalid quantity' => [[['Название', 'Количество'], ['Товар', 'неизвестно']]],
    'oversized decimal' => [[['Название', 'Цена'], ['Товар', '10000000000000']]],
    'too precise' => [[['Название', 'Цена'], ['Товар', '1.001']]],
    'missing name after valid row' => [[['Название', 'Количество'], ['Корректная', 1], [null, 2]]],
    'formula' => [[['Название', 'Цена'], ['Товар', '=1+1']]],
    'duplicate header' => [[['Название', 'Название'], ['Товар', 'Другой']]],
    'empty' => [[['Название']]],
]);

it('rejects corrupt files archive traversal and XML entities', function () {
    [$user] = priceFixture('pro');
    $this->actingAs($user);
    $this->post('/catalog/import', ['version' => 0, 'file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'not excel')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    foreach (['../outside.xml' => '<x/>', 'xl/evil.xml' => '<!DOCTYPE x [<!ENTITY evil SYSTEM "file:///etc/passwd">]><x/>'] as $name => $xml) {
        $file = priceXlsx([['Название'], ['Товар']]);
        $zip = new ZipArchive;
        $zip->open($file->getPathname());
        $zip->addFromString($name, $xml);
        $zip->close();
        $this->post('/catalog/import', ['version' => 0, 'file' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    }
    expect(PriceCatalog::query()->count())->toBe(0);
});

it('bounds worksheet rows and keeps exactly 500 importable positions', function () {
    [$user] = priceFixture('pro');
    $rows = [['Название']];
    for ($i = 0; $i < 500; $i++) {
        $rows[] = ['Позиция '.$i];
    }
    $this->actingAs($user)->post('/catalog/import', ['version' => 0, 'file' => priceXlsx($rows)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonCount(500, 'catalog.items');
    $rows[] = ['Лишняя'];
    $this->post('/catalog/import', ['version' => 1, 'file' => priceXlsx($rows)], ['Accept' => 'application/json'])->assertUnprocessable();
    expect(PriceCatalog::query()->where('user_id', $user->id)->first()->version)->toBe(1);
    $this->postJson('/catalog/items', ['version' => 1, 'name' => 'Ещё'])->assertUnprocessable();
});

it('downloads a real Excel template accepted by the importer', function () {
    [$user] = priceFixture('pro');
    $response = $this->actingAs($user)->get('/catalog/template')->assertOk()->assertDownload('TenderFinder-catalog.xlsx');
    $path = $response->baseResponse->getFile()->getPathname();
    $file = new UploadedFile($path, 'template.xlsx', null, null, true);
    $this->post('/catalog/import', ['version' => 0, 'file' => $file], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'catalog.items');
    @unlink($path);
});

it('isolates catalogs and protects editing deletion and replacement against stale versions', function () {
    [$a] = priceFixture('pro');
    [$b] = priceFixture('pro');
    $id = $this->actingAs($a)->postJson('/catalog/items', ['version' => 0, 'name' => 'Секретный прайс'])->assertOk()->json('catalog.items.0.id');
    $this->actingAs($b)->get('/catalog')->assertInertia(fn (Assert $p) => $p->where('catalog.items', []));
    $this->patchJson('/catalog/items/'.$id, ['version' => 0, 'name' => 'Подмена'])->assertNotFound();
    $this->deleteJson('/catalog/items/'.$id, ['version' => 0])->assertNotFound();
    expect(PriceCatalog::query()->where('user_id', $b->id)->exists())->toBeFalse();
    $this->actingAs($a)->patchJson('/catalog/items/'.$id, ['version' => 1, 'name' => 'Новый товар', 'quantity' => '0'])->assertOk()->assertJsonPath('catalog.version', 2);
    $this->deleteJson('/catalog/items/'.$id, ['version' => 1])->assertConflict();
    $this->post('/catalog/import', ['version' => 1, 'file' => priceXlsx([['Название'], ['Замена']])], ['Accept' => 'application/json'])->assertConflict();
    $this->deleteJson('/catalog/items/'.$id, ['version' => 2])->assertOk()->assertJsonCount(0, 'catalog.items');
    $this->postJson('/catalog/items', ['version' => 3, 'name' => 'Вернул'])->assertOk();
    $this->deleteJson('/catalog', ['version' => 4])->assertOk()->assertJsonCount(0, 'catalog.items');
});

it('does not invent a specification or full coverage from a tender title', function () {
    [$user, $tender] = priceFixture('pro');
    $this->actingAs($user)->postJson('/catalog/items', ['version' => 0, 'name' => $tender->title])->assertOk();
    $this->get('/catalog/tenders/'.$tender->id)->assertInertia(fn (Assert $p) => $p->component('CatalogComparison')
        ->where('comparison.has_specification', false)->where('comparison.complete', false)
        ->has('comparison.rows', 1)->where('comparison.rows.0.status', 'uncertain')
        ->where('comparison.rows.0.requirement.quantity', null));
});

it('compares 18 explicit rows with 12 preliminary matches 4 uncertain and 2 missing', function () {
    [$user,$tender] = priceFixture('pro');
    $catalog = [['Название', 'Характеристики', 'Количество', 'Единица', 'Регион']];
    $spec = $catalog;
    for ($i = 0; $i < 16; $i++) {
        $catalog[] = ['ModelALPHA'.$i, '16 ГБ', $i < 12 ? 10 : null, 'шт', 'Москва'];
        $spec[] = ['ModelALPHA'.$i, '16 ГБ', 2, 'шт', 'Москва'];
    }
    $spec[] = ['Доставка', null, null, null, null];
    $spec[] = ['Обучение', null, null, null, null];
    $this->actingAs($user)->post('/catalog/import', ['version' => 0, 'file' => priceXlsx($catalog)], ['Accept' => 'application/json'])->assertOk();
    $view = app(CatalogService::class)->comparison($user, $tender);
    $this->post('/catalog/tenders/'.$tender->id.'/specification', [...array_map('strval', priceVersion($view)), 'complete' => true, 'file' => priceXlsx($spec)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('comparison.counts.matched', 12)->assertJsonPath('comparison.counts.uncertain', 4)
        ->assertJsonPath('comparison.counts.missing', 2)->assertJsonPath('comparison.complete', true);
});

it('requires clarification for insufficient stock mismatched units characteristics and region', function (string $field, mixed $value) {
    [$user,$tender] = priceFixture('pro');
    $this->actingAs($user)->postJson('/catalog/items', ['version' => 0, 'name' => 'Ноутбук', 'characteristics' => '16 ГБ', 'quantity' => '10', 'unit' => 'шт', 'region' => 'Москва'])->assertOk();
    $requirement = ['name' => 'Ноутбук', 'characteristics' => '16 ГБ', 'quantity' => '2.000', 'unit' => 'шт', 'region' => 'Москва'];
    $requirement[$field] = $value;
    PriceCatalogComparison::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'requirements' => [$requirement]]);
    $result = app(CatalogService::class)->comparison($user, $tender);
    expect($result['counts']['uncertain'])->toBe(1)->and($result['counts']['matched'])->toBe(0);
})->with([['quantity', '11.000'], ['quantity', null], ['unit', 'упаковка'], ['characteristics', '32 ГБ'], ['region', 'Казань']]);

it('does not reuse the same stock for duplicated specification rows', function () {
    [$user,$tender] = priceFixture('pro');
    $this->actingAs($user)->postJson('/catalog/items', ['version' => 0, 'name' => 'Ноутбук', 'characteristics' => '16 ГБ', 'quantity' => '10', 'unit' => 'шт', 'region' => 'Москва'])->assertOk();
    $row = ['name' => 'Ноутбук', 'characteristics' => '16 ГБ', 'quantity' => '8.000', 'unit' => 'шт', 'region' => 'Москва'];
    PriceCatalogComparison::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'requirements' => [$row, $row]]);
    $result = app(CatalogService::class)->comparison($user, $tender);
    expect($result['counts']['matched'])->toBe(0)->and($result['counts']['uncertain'])->toBe(2)
        ->and($result['rows'][0]['stock_check']['state'])->toBe('different');
});

it('persists user corrections and invalidates stale decisions when catalog specification or tender changes', function () {
    [$user,$tender] = priceFixture('pro');
    $id = $this->actingAs($user)->postJson('/catalog/items', ['version' => 0, 'name' => 'Разработка сайта'])->assertOk()->json('catalog.items.0.id');
    $root = '/catalog/tenders/'.$tender->id;
    $view = app(CatalogService::class)->comparison($user, $tender);
    $payload = [...priceVersion($view), 'index' => 0, 'status' => 'matched', 'item_id' => $id];
    $this->postJson($root.'/reviews', $payload)->assertUnprocessable();
    $response = $this->postJson($root.'/reviews', [...$payload, 'note' => 'Проверил документацию, условия подходят'])->assertOk()
        ->assertJsonPath('comparison.counts.confirmed', 1);
    $this->postJson($root.'/reviews', [...$payload, 'note' => 'Устарело'])->assertConflict();
    $view = $response->json('comparison');
    $this->postJson($root.'/reviews', [...priceVersion($view), 'index' => 0, 'status' => 'auto'])->assertOk()->assertJsonPath('comparison.counts.confirmed', 0);
    $view = app(CatalogService::class)->comparison($user, $tender);
    $this->postJson($root.'/reviews', [...priceVersion($view), 'index' => 0, 'status' => 'matched', 'item_id' => $id, 'note' => 'Проверено'])->assertOk();
    $this->patchJson('/catalog/items/'.$id, ['version' => 1, 'name' => 'Разработка сайта', 'quantity' => '1'])->assertOk();
    expect(app(CatalogService::class)->comparison($user, $tender)['counts']['confirmed'])->toBe(0);
    $view = app(CatalogService::class)->comparison($user, $tender);
    $this->postJson($root.'/reviews', [...priceVersion($view), 'index' => 0, 'status' => 'matched', 'item_id' => $id, 'note' => 'Обновлённый прайс проверен'])->assertOk();
    $before = app(CatalogService::class)->comparison($user, $tender);
    $tender->update(['description' => 'Новые условия']);
    $this->postJson($root.'/reviews', [...priceVersion($before), 'index' => 0, 'status' => 'missing'])->assertConflict();
    expect(app(CatalogService::class)->comparison($user, $tender->refresh())['counts']['confirmed'])->toBe(0);
    $view = app(CatalogService::class)->comparison($user, $tender);
    $this->post($root.'/specification', [...priceVersion($view), 'complete' => false, 'file' => priceXlsx([['Название'], ['SEO']])], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('comparison.counts.confirmed', 0)->assertJsonPath('comparison.complete', false);
    $view = app(CatalogService::class)->comparison($user, $tender);
    $this->deleteJson($root.'/specification', priceVersion($view))->assertOk()->assertJsonPath('comparison.has_specification', false);
});

it('keeps recommendations personal supports service competencies and hides expired and unrelated tenders', function () {
    [$user,$tender] = priceFixture('pro');
    [$other,$private] = priceFixture('pro');
    $this->actingAs($user)->postJson('/catalog/items', ['version' => 0, 'name' => 'Разработка сайта'])->assertOk();
    $this->getJson('/catalog/recommendations')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $tender->id);
    $this->get('/catalog/tenders/'.$private->id)->assertNotFound();
    $view = app(CatalogService::class)->comparison($other, $private);
    $this->postJson('/catalog/tenders/'.$private->id.'/reviews', [...priceVersion($view), 'index' => 0, 'status' => 'missing'])->assertNotFound();
    $tender->update(['deadline_at' => now()->subMinute()]);
    $this->getJson('/catalog/recommendations')->assertOk()->assertJsonCount(0, 'items');
    Http::assertNothingSent();
});

it('enforces the viewers tier for shared tenders and never shares a private catalog with team members', function () {
    [$owner,$tender,$query] = priceFixture('ultra');
    [$member] = priceFixture('pro');
    [$stranger] = priceFixture('ultra');
    $team = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Каталоги']);
    foreach ([$owner->id => 'owner', $member->id => 'member'] as $id => $role) {
        DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $id, 'role' => $role]);
    }
    DB::table('team_search_queries')->insert(['team_id' => $team->id, 'search_query_id' => $query->id, 'shared_by_id' => $owner->id]);
    $this->actingAs($owner)->postJson('/catalog/items', ['version' => 0, 'name' => 'Скрытый прайс владельца'])->assertOk();
    $path = '/catalog/tenders/'.$tender->id.'?team_id='.$team->id;
    $this->actingAs($member)->get($path)->assertOk()->assertInertia(fn (Assert $p) => $p->where('catalog.items', []));
    $this->actingAs($stranger)->get($path)->assertNotFound();
    $this->actingAs($member);
    Entitlement::query()->where('user_id', $member->id)->update(['plan_id' => app(PlanCatalog::class)->basic()->id]);
    $this->get($path)->assertForbidden();
});

it('denies a forged cross-user catalog item and stops access after expiry or Developer downgrade', function () {
    [$a,$tender] = priceFixture('pro');
    [$b] = priceFixture('pro');
    $otherId = $this->actingAs($b)->postJson('/catalog/items', ['version' => 0, 'name' => 'Чужая компетенция'])->json('catalog.items.0.id');
    $this->actingAs($a);
    $view = app(CatalogService::class)->comparison($a, $tender);
    $this->postJson('/catalog/tenders/'.$tender->id.'/reviews', [...priceVersion($view), 'index' => 0, 'status' => 'matched', 'item_id' => $otherId, 'note' => 'Подмена'])->assertNotFound();
    Entitlement::query()->where('user_id', $a->id)->update(['ends_at' => now()->subSecond()]);
    $this->postJson('/catalog/items', ['version' => 0, 'name' => 'Товар'])->assertForbidden();
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin, 'developer_plan_code' => 'basic']);
    $this->actingAs($owner)->get('/catalog')->assertInertia(fn (Assert $p) => $p->where('enabled', false));
    $owner->forceFill(['developer_plan_code' => 'pro'])->save();
    $this->get('/catalog')->assertInertia(fn (Assert $p) => $p->where('enabled', true));
});

function priceFixture(string $tier): array
{
    $user = User::factory()->create();
    Entitlement::query()->create(['user_id' => $user->id, 'plan_id' => app(PlanCatalog::class)->byCode($tier)->id,
        'code' => 'active_queries', 'status' => 'active', 'value' => 10, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Каталог', 'keywords' => ['сайт'], 'status' => 'active']);
    $key = 'catalog-'.$user->id;
    $tender = Tender::query()->create(['source' => 'workspace_ru', 'external_id' => $key, 'canonical_url' => 'https://example.test/'.$key,
        'canonical_url_hash' => hash('sha256', $key), 'title' => 'Разработка сайта', 'currency' => 'RUB', 'region' => 'Москва', 'deadline_at' => now()->addDays(5)]);
    TenderQueryMatch::query()->create(['tender_id' => $tender->id, 'search_query_id' => $query->id, 'matched_at' => now(), 'match_reasons' => []]);

    return [$user, $tender, $query];
}

function priceXlsx(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'catalog-test-');
    $writer = new Writer;
    $writer->openToFile($path);
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();
    $file = UploadedFile::fake()->createWithContent('catalog.xlsx', file_get_contents($path));
    unlink($path);

    return $file;
}

function priceVersion(array $view): array
{
    return array_intersect_key($view, array_flip(['version', 'catalog_version', 'tender_hash']));
}
