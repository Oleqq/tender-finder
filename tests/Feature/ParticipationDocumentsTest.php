<?php

use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function documentFixture(): array
{
    $user = User::factory()->create();
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Документы', 'keywords' => ['сервер'], 'status' => 'active']);
    $tender = Tender::query()->create([
        'source' => 'rostender', 'external_id' => 'documents-101',
        'canonical_url' => 'https://rostender.info/tender/documents-101',
        'canonical_url_hash' => hash('sha256', 'documents-101'), 'title' => 'Поставка серверов',
    ]);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'matched_at' => now(), 'match_reasons' => []]);
    $participation = TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'stage' => 'preparing']);

    return [$user, $tender, $participation];
}

it('stores private files and keeps immutable replacement history', function () {
    Storage::fake('local');
    [$user, $tender, $participation] = documentFixture();
    $task = $participation->items()->create(['title' => 'Собрать пакет']);
    $root = '/tenders/'.$tender->id.'/documents';

    $created = $this->actingAs($user)->post($root, [
        'title' => 'Техническое предложение', 'type' => 'proposal', 'status' => 'needed',
        'checklist_item_id' => $task->id,
        'file' => UploadedFile::fake()->create('proposal.pdf', 24, 'application/pdf'),
    ])->assertCreated()->assertJsonPath('documents.0.checklist_item_id', $task->id)
        ->assertJsonPath('documents.0.versions.0.original_name', 'proposal.pdf');
    $document = $created->json('documents.0');
    $stored = DB::table('participation_document_versions')->where('document_id', $document['id'])->value('storage_path');
    Storage::disk('local')->assertExists($stored);

    $this->post($root.'/'.$document['id'].'/versions', [
        'version' => 1, 'source_url' => 'https://files.example.test/proposal-v2.pdf',
    ])->assertOk()->assertJsonPath('documents.0.status', 'ready')->assertJsonCount(2, 'documents.0.versions')
        ->assertJsonPath('documents.0.versions.0.source_kind', 'link');
    expect(DB::table('participation_document_versions')->where('document_id', $document['id'])->count())->toBe(2);

    $download = route('tenders.documents.download', [
        'tender' => $tender->id, 'document' => $document['id'],
        'version' => $created->json('documents.0.versions.0.id'),
    ], false);
    $this->get($download)->assertOk()->assertDownload('proposal.pdf');
    $this->actingAs(User::factory()->create())->get($download)->assertNotFound();
});

it('validates ownership assignments limits and archive deletion', function () {
    Storage::fake('local');
    [$user, $tender, $participation] = documentFixture();
    $otherParticipation = TenderParticipation::query()->create([
        'user_id' => User::factory()->create()->id, 'tender_id' => $tender->id, 'stage' => 'studying',
    ]);
    $foreignTask = $otherParticipation->items()->create(['title' => 'Чужая задача']);
    $root = '/tenders/'.$tender->id.'/documents';
    $this->actingAs($user)->postJson($root, [
        'title' => 'Неверная связь', 'type' => 'other', 'status' => 'needed',
        'checklist_item_id' => $foreignTask->id,
    ])->assertUnprocessable();
    $document = $this->post($root, [
        'title' => 'Декларация', 'type' => 'qualification', 'status' => 'ready',
        'file' => UploadedFile::fake()->create('declaration.pdf', 10, 'application/pdf'),
    ])->assertCreated()->json('documents.0');
    $path = DB::table('participation_document_versions')->where('document_id', $document['id'])->value('storage_path');

    $this->patchJson($root.'/'.$document['id'].'/archive', ['archived' => true, 'version' => 1])
        ->assertOk()->assertJsonPath('documents.0.archived_at', fn ($value) => is_string($value));
    $this->deleteJson($root.'/'.$document['id'], ['version' => 1])->assertConflict();
    $this->deleteJson($root.'/'.$document['id'], ['version' => 2])->assertOk()->assertJsonCount(0, 'documents');
    Storage::disk('local')->assertMissing($path);
});

it('keeps team documents readable to viewers but writable only to editors', function () {
    [$owner, $tender] = documentFixture();
    $viewer = User::factory()->create();
    $team = Team::query()->create(['name' => 'Отдел закупок', 'owner_id' => $owner->id]);
    DB::table('team_members')->insert([
        ['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner', 'created_at' => now()],
        ['team_id' => $team->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'created_at' => now()],
    ]);
    TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id]);
    $url = '/tenders/'.$tender->id.'/documents?team_id='.$team->id;
    $this->actingAs($owner)->postJson($url, ['title' => 'Общий файл', 'type' => 'other', 'status' => 'needed'])->assertCreated();
    $this->actingAs($viewer)->get('/tenders/'.$tender->id.'/work?team_id='.$team->id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('documents', 1)->where('can_edit', false));
    $this->postJson($url, ['title' => 'Запрещено', 'type' => 'other', 'status' => 'needed'])->assertForbidden();
});
