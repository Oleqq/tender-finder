<?php

use App\Models\CostGapCheck;
use App\Models\CostGapTransfer;
use App\Models\Entitlement;
use App\Models\ParticipationDocument;
use App\Models\ParticipationDocumentVersion;
use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\CostGap\CostGapService;
use App\Services\CostGap\DocumentParser;
use App\Services\PlanCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('tender.temporary_full_access_until', null);
    config()->set('tender.local_mvp_full_access.enabled', false);
});

function costGapFixture(string $plan = 'pro'): array
{
    $user = User::factory()->create();
    Entitlement::query()->create(['user_id' => $user->id, 'plan_id' => app(PlanCatalog::class)->byCode($plan)->id,
        'code' => 'active_queries', 'status' => 'active', 'value' => 10, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Мои закупки', 'keywords' => ['оборудование'], 'status' => 'active']);
    $tender = Tender::query()->create(['source' => 'workspace_ru', 'external_id' => bin2hex(random_bytes(8)), 'title' => 'Поставка оборудования',
        'canonical_url' => 'https://example.test/pro02', 'canonical_url_hash' => hash('sha256', bin2hex(random_bytes(8))), 'budget_amount' => '500000.00', 'currency' => 'RUB']);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'match_reasons' => [], 'matched_at' => now()]);

    return [$user, $tender];
}

function costGapPayload(Tender $tender, int $version = 0): array
{
    return ['version' => $version, 'tender_hash' => app(CostGapService::class)->fingerprint($tender), 'confirmed' => true,
        'customer_mode' => 'text', 'customer_text' => "Поставщик обязан обеспечить доставку.\nТребуется монтаж.\nНеобходимо обучение персонала.",
        'supplier_mode' => 'text', 'supplier_text' => "Доставка включена в стоимость.\nМонтаж возможен по согласованию."];
}

function costGapReview(Tender $tender, int $version = 1, string $key = 'training', ?string $amount = '1234,56'): array
{
    return ['version' => $version, 'tender_hash' => app(CostGapService::class)->fingerprint($tender), 'key' => $key,
        'status' => 'not_mentioned', 'amount' => $amount, 'note' => 'Проверено по смете', 'confirmed' => true];
}

function costGapTransfer(Tender $tender, int $version = 2, int $economicsVersion = 1): array
{
    return ['version' => $version, 'tender_hash' => app(CostGapService::class)->fingerprint($tender), 'key' => 'training',
        'economics_version' => $economicsVersion, 'category' => 'other_cost', 'confirmed_new_expense' => true];
}

it('gates the entire check by active Pro or Ultra and never creates records on a read', function (string $plan, bool $allowed) {
    [$user, $tender] = costGapFixture($plan);
    $root = '/tenders/'.$tender->id.'/cost-check';
    $response = $this->actingAs($user)->get($root);
    if ($allowed) {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('CostGapCheck')->where('check.version', 0)->where('check.sources', null));
    } else {
        $response->assertForbidden();
        $this->postJson($root.'/analyze', costGapPayload($tender))->assertForbidden();
    }
    expect(CostGapCheck::query()->count())->toBe(0);
    expect(TenderParticipation::query()->count())->toBe(0);
    Entitlement::query()->where('user_id', $user->id)->update(['ends_at' => now()]);
    $this->get($root)->assertForbidden();
    $this->postJson($root.'/analyze', costGapPayload($tender))->assertForbidden();
})->with([['basic', false], ['pro', true], ['ultra', true]]);

it('compares three states with original citations and never infers unknown costs or contacts external services', function () {
    [$user, $tender] = costGapFixture();
    $root = '/tenders/'.$tender->id.'/cost-check';
    $response = $this->actingAs($user)->postJson($root.'/analyze', costGapPayload($tender))->assertOk();
    $rows = collect($response->json('check.rows'))->keyBy('key');
    expect($rows['delivery']['status'])->toBe('included');
    expect($rows['installation']['status'])->toBe('clarify');
    expect($rows['training']['status'])->toBe('not_mentioned');
    expect($rows['delivery']['requirement_evidence'][0]['text'])->toContain('обязан');
    expect($rows['delivery']['offer_evidence'][0]['text'])->toBe('Доставка включена в стоимость.');
    expect($rows['delivery']['offer_evidence'][0]['ref'])->toContain('строка 1');
    $response->assertJsonPath('check.economics.other_cost', null)->assertJsonCount(0, 'check.decisions');
    Http::assertNothingSent();
});

it('requires source confirmation and leaves the previous check intact on invalid input or stale writes', function () {
    [$user, $tender] = costGapFixture();
    $root = '/tenders/'.$tender->id.'/cost-check';
    $payload = costGapPayload($tender);
    $this->actingAs($user)->postJson($root.'/analyze', [...$payload, 'confirmed' => false])->assertUnprocessable();
    $this->postJson($root.'/analyze', $payload)->assertOk();
    $this->postJson($root.'/analyze', $payload)->assertConflict();
    $this->postJson($root.'/analyze', [...$payload, 'version' => 1, 'customer_text' => "\x00\x01"])->assertUnprocessable();
    $this->patchJson($root.'/review', [...costGapReview($tender), 'amount' => '-10'])->assertUnprocessable();
    $this->patchJson($root.'/review', [...costGapReview($tender), 'key' => 'fake'])->assertNotFound();
    expect(CostGapCheck::query()->sole()->version)->toBe(1);
    expect(CostGapCheck::query()->sole()->sources['customer']['segments'][0]['text'])->toContain('доставку');
});

it('keeps unknown money distinct from zero and transfers a manually reviewed delta exactly once in kopecks', function () {
    [$user, $tender] = costGapFixture();
    $root = '/tenders/'.$tender->id.'/cost-check';
    $this->actingAs($user)->postJson($root.'/analyze', costGapPayload($tender))->assertOk();
    $this->postJson($root.'/transfer', costGapTransfer($tender, 1))->assertUnprocessable();
    $this->patchJson($root.'/review', costGapReview($tender))->assertOk()->assertJsonPath('check.decisions.training.amount', '1234.56');
    $this->postJson($root.'/transfer', costGapTransfer($tender))->assertUnprocessable(); // null baseline
    $participation = TenderParticipation::query()->sole();
    $participation->update(['other_cost' => '1000.01']);
    $data = costGapTransfer($tender);
    $this->postJson($root.'/transfer', [...$data, 'confirmed_new_expense' => false])->assertUnprocessable();
    $this->postJson($root.'/transfer', $data)->assertOk()->assertJsonPath('check.economics.other_cost', '2234.57')->assertJsonPath('check.economics.version', 2);
    $this->postJson($root.'/transfer', $data)->assertOk()->assertJsonPath('check.version', 3)->assertJsonPath('check.economics.version', 2);
    expect(CostGapTransfer::query()->count())->toBe(1);
    expect(CostGapTransfer::query()->sole()->snapshot['decision']['note'])->toBe('Проверено по смете');
    expect($participation->refresh()->actual_cost)->toBeNull();
    $this->patchJson($root.'/review', costGapReview($tender, 3))->assertConflict();
});

it('does not transfer included zero unknown or stale economics and does not accept arbitrary finance categories', function () {
    [$user, $tender] = costGapFixture();
    $root = '/tenders/'.$tender->id.'/cost-check';
    $this->actingAs($user)->postJson($root.'/analyze', costGapPayload($tender))->assertOk();
    TenderParticipation::query()->sole()->update(['other_cost' => '0.00']);
    foreach ([null, '0', '0.00'] as $index => $amount) {
        $this->patchJson($root.'/review', costGapReview($tender, $index + 1, 'training', $amount))->assertOk();
        $this->postJson($root.'/transfer', costGapTransfer($tender, $index + 2))->assertUnprocessable();
    }
    $this->patchJson($root.'/review', [...costGapReview($tender, 4), 'status' => 'included'])->assertOk();
    $this->postJson($root.'/transfer', costGapTransfer($tender, 5))->assertUnprocessable();
    $this->patchJson($root.'/review', costGapReview($tender, 5))->assertOk();
    $this->postJson($root.'/transfer', costGapTransfer($tender, 6, 2))->assertConflict();
    $this->postJson($root.'/transfer', [...costGapTransfer($tender, 6), 'category' => 'actual_cost'])->assertUnprocessable();
    expect(CostGapTransfer::query()->count())->toBe(0);
    expect(TenderParticipation::query()->sole()->other_cost)->toBe('0.00');
});

it('preserves reviewed decisions only for unchanged inputs and preserves transfer receipts through source changes', function () {
    [$user, $tender] = costGapFixture();
    $root = '/tenders/'.$tender->id.'/cost-check';
    $payload = costGapPayload($tender);
    $this->actingAs($user)->postJson($root.'/analyze', $payload)->assertOk();
    TenderParticipation::query()->sole()->update(['other_cost' => '0.00']);
    $this->patchJson($root.'/review', costGapReview($tender))->assertOk();
    $this->postJson($root.'/analyze', [...$payload, 'version' => 2])->assertOk()->assertJsonPath('check.decisions.training.amount', '1234.56');
    $this->postJson($root.'/transfer', costGapTransfer($tender, 3))->assertOk();
    $this->postJson($root.'/analyze', [...$payload, 'version' => 4, 'supplier_text' => 'Только поставка товара.'])->assertOk()->assertJsonCount(0, 'check.decisions')->assertJsonCount(1, 'check.transfers');
    $this->postJson($root.'/transfer', costGapTransfer($tender, 1))->assertOk()->assertJsonPath('check.economics.other_cost', '1234.56');
    expect(CostGapTransfer::query()->count())->toBe(1);
});

it('blocks decisions and transfers after a tender change until a new confirmed analysis', function () {
    [$user, $tender] = costGapFixture();
    $root = '/tenders/'.$tender->id.'/cost-check';
    $this->actingAs($user)->postJson($root.'/analyze', costGapPayload($tender))->assertOk();
    $tender->update(['description' => 'Добавлена новая редакция ТЗ']);
    $this->get($root)->assertInertia(fn (Assert $page) => $page->has('check.stale', 1));
    $this->patchJson($root.'/review', costGapReview($tender))->assertConflict();
    $this->postJson($root.'/analyze', [...costGapPayload($tender, 1), 'customer_mode' => 'keep', 'supplier_mode' => 'keep'])->assertOk()->assertJsonCount(0, 'check.stale');
    $this->patchJson($root.'/review', costGapReview($tender, 2))->assertOk();
});

it('isolates users and team workspaces and enforces viewer archived removed and deleted participation boundaries', function () {
    [$owner, $tender] = costGapFixture();
    [$member] = costGapFixture();
    $team = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Тендерный отдел']);
    DB::table('team_members')->insert([['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner'], ['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'viewer']]);
    DB::table('team_search_queries')->insert(['team_id' => $team->id, 'search_query_id' => SearchQuery::query()->where('user_id', $owner->id)->sole()->id, 'shared_by_id' => $owner->id]);
    $root = '/tenders/'.$tender->id.'/cost-check';
    $suffix = '?team_id='.$team->id;
    $this->actingAs($owner)->postJson($root.'/analyze'.$suffix, costGapPayload($tender))->assertOk();
    $this->get($root)->assertInertia(fn (Assert $page) => $page->where('check.sources', null));
    $this->actingAs($member)->get($root)->assertNotFound();
    $this->get($root.$suffix)->assertInertia(fn (Assert $page) => $page->where('canEdit', false)->has('check.rows', 3));
    $this->postJson($root.'/analyze'.$suffix, costGapPayload($tender, 1))->assertForbidden();
    $this->patchJson($root.'/review'.$suffix, costGapReview($tender))->assertForbidden();
    $this->postJson($root.'/transfer'.$suffix, costGapTransfer($tender))->assertForbidden();
    DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member->id)->update(['role' => 'member']);
    $this->patchJson($root.'/review'.$suffix, costGapReview($tender))->assertOk();
    $team->update(['archived_at' => now()]);
    $this->patchJson($root.'/review'.$suffix, costGapReview($tender, 2))->assertConflict();
    DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member->id)->delete();
    $this->get($root.$suffix)->assertNotFound();
    $this->actingAs($owner);
    $team->update(['archived_at' => null]);
    TenderParticipation::query()->sole()->delete();
    $this->get($root.$suffix)->assertNotFound();
    $this->postJson($root.'/analyze'.$suffix, costGapPayload($tender, 2))->assertNotFound();
});

it('extracts TXT and DOCX in a bounded worker and ties stored documents to their latest authorized version', function () {
    [$user, $tender] = costGapFixture();
    $root = '/tenders/'.$tender->id.'/cost-check';
    $payload = costGapPayload($tender);
    $this->actingAs($user)->postJson($root.'/analyze', [...$payload, 'customer_mode' => 'file', 'customer_file' => UploadedFile::fake()->createWithContent('ТЗ.txt', "Доставка включена.\nТребуется обучение.")])->assertOk()->assertJsonPath('check.sources.customer.kind', 'file');
    $temp = tempnam(sys_get_temp_dir(), 'pro02-docx-');
    $zip = new ZipArchive;
    $zip->open($temp, ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Доставка включена.</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    Storage::fake('local');
    Storage::disk('local')->put('private/pro02.docx', file_get_contents($temp));
    unlink($temp);
    $document = ParticipationDocument::query()->create(['participation_id' => TenderParticipation::query()->sole()->id, 'title' => 'Предложение', 'type' => 'other', 'status' => 'draft', 'version' => 1]);
    $version = ParticipationDocumentVersion::query()->create(['document_id' => $document->id, 'source_kind' => 'file', 'disk' => 'local', 'storage_path' => 'private/pro02.docx', 'original_name' => 'КП.docx', 'created_at' => now()]);
    $this->postJson($root.'/analyze', [...$payload, 'version' => 1, 'supplier_mode' => 'document', 'supplier_document' => $version->id])->assertOk()->assertJsonPath('check.sources.supplier.kind', 'document')->assertJsonPath('check.sources.supplier.segments.0.text', 'Доставка включена.');
    $document->update(['version' => 2, 'archived_at' => now()]);
    $this->get($root)->assertInertia(fn (Assert $page) => $page->has('check.stale', 1));
    $this->patchJson($root.'/review', costGapReview($tender, 2))->assertConflict();
    $this->postJson($root.'/analyze', [...$payload, 'version' => 2, 'supplier_mode' => 'keep'])->assertConflict();
    $document->update(['archived_at' => null]);
    ParticipationDocumentVersion::query()->create(['document_id' => $document->id, 'source_kind' => 'file', 'disk' => 'local', 'storage_path' => 'private/pro02.docx', 'original_name' => 'КП.docx', 'created_at' => now()]);
    $this->postJson($root.'/analyze', [...$payload, 'version' => 2, 'supplier_mode' => 'document', 'supplier_document' => $version->id])->assertConflict();
    [$other] = costGapFixture();
    $this->actingAs($other)->postJson($root.'/analyze', [...$payload, 'supplier_mode' => 'document', 'supplier_document' => $version->id])->assertNotFound();
});

it('rejects unsupported binary oversized encrypted or image-only files without replacing a saved check', function () {
    [$user, $tender] = costGapFixture();
    $root = '/tenders/'.$tender->id.'/cost-check';
    $payload = costGapPayload($tender);
    $this->actingAs($user)->postJson($root.'/analyze', $payload)->assertOk();
    foreach (['fake.pdf' => 'not a PDF', 'old.doc' => 'legacy', 'bad.txt' => "\x00\x01\x02", 'empty.docx' => 'not a zip'] as $name => $body) {
        $this->postJson($root.'/analyze', [...$payload, 'version' => 1, 'supplier_mode' => 'file', 'supplier_file' => UploadedFile::fake()->createWithContent($name, $body)])->assertUnprocessable();
    }
    $this->travel(61)->seconds(); // A sixth attempt would correctly hit the public parser throttle.
    $this->postJson($root.'/analyze', [...$payload, 'version' => 1, 'supplier_mode' => 'file', 'supplier_file' => UploadedFile::fake()->create('huge.pdf', 5121)])->assertUnprocessable();
    expect(CostGapCheck::query()->sole()->version)->toBe(1);
});

it('reads real PDF text with page citations and refuses PDF scans without text', function () {
    $pdf = function (string $text): string {
        $stream = $text === '' ? '' : 'BT /F1 12 Tf 50 750 Td ('.$text.') Tj ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream"];
        $data = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($data);
            $data .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($data);
        $data .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $data .= sprintf("%010d 00000 n \n", $offset);
        }

        return $data."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    };
    [$user, $tender] = costGapFixture();
    $root = '/tenders/'.$tender->id.'/cost-check';
    $payload = costGapPayload($tender);
    $this->actingAs($user)->postJson($root.'/analyze', [...$payload, 'supplier_mode' => 'file', 'supplier_file' => UploadedFile::fake()->createWithContent('Offer.pdf', $pdf('Delivery is included.'))])->assertOk()
        ->assertJsonPath('check.sources.supplier.segments.0.text', 'Delivery is included.')->assertJsonPath('check.sources.supplier.segments.0.ref', 'с. 1, строка 1, фрагмент 1');
    $this->postJson($root.'/analyze', [...$payload, 'version' => 1, 'supplier_mode' => 'file', 'supplier_file' => UploadedFile::fake()->createWithContent('Scan.pdf', $pdf(''))])->assertUnprocessable();
});

it('handles bounded paragraphs and Windows-1251 without executing document markup', function () {
    $parser = new DocumentParser;
    $temp = tempnam(sys_get_temp_dir(), 'pro02-txt-');
    try {
        file_put_contents($temp, mb_convert_encoding('Требуется доставка.', 'Windows-1251', 'UTF-8'));
        expect($parser->parse($temp, 'txt')['segments'][0]['text'])->toBe('Требуется доставка.');
        expect(fn () => $parser->segments(str_repeat('я', 40001)))->toThrow(RuntimeException::class);
        file_put_contents($temp, '');
        $zip = new ZipArchive;
        $zip->open($temp, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<!DOCTYPE document [<!ENTITY x SYSTEM "file:///etc/passwd">]><document><p><t>&x;</t></p></document>');
        $zip->close();
        expect(fn () => $parser->parse($temp, 'docx'))->toThrow(RuntimeException::class, 'Неподдерживаемая структура');
    } finally {
        unlink($temp);
    }
});
