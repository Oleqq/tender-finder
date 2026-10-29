<?php

use App\Models\CostGapCheck;
use App\Models\Entitlement;
use App\Models\ParticipationDocument;
use App\Models\ParticipationDocumentVersion;
use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\TenderQuestion;
use App\Models\User;
use App\Services\CostGap\CostGapService;
use App\Services\PlanCatalog;
use App\Services\TenderQuestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('tender.temporary_full_access_until', null);
    config()->set('tender.local_mvp_full_access.enabled', false);
});

function questionFixture(string $plan = 'pro'): array
{
    $user = User::factory()->create();
    Entitlement::query()->create(['user_id' => $user->id, 'plan_id' => app(PlanCatalog::class)->byCode($plan)->id, 'code' => 'active_queries',
        'status' => 'active', 'value' => 10, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Закупки', 'keywords' => ['оборудование'], 'status' => 'active']);
    $tender = Tender::query()->create(['source' => 'workspace_ru', 'external_id' => (string) Str::uuid(), 'title' => 'Поставка оборудования',
        'canonical_url' => 'https://example.test/question', 'canonical_url_hash' => hash('sha256', (string) Str::uuid()),
        'description' => "Требуется монтаж.\nНеобходимо обучение персонала.", 'currency' => 'RUB']);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'match_reasons' => [], 'matched_at' => now()]);

    return [$user, $tender];
}

function questionPayload(Tender $tender): array
{
    return ['creation_key' => (string) Str::uuid(), 'version' => 0, 'title' => 'Объём обучения', 'topic' => 'scope', 'context' => 'В одном разделе 20 рабочих мест, в другом 25 комплектов.',
        'mode' => 'manual', 'quote' => 'Провести обучение персонала.', 'source_title' => 'Техническое задание', 'source_ref' => 'Страница 4, пункт 2.3',
        'source_url' => 'https://example.test/specification.pdf', 'tender_hash' => app(TenderQuestionService::class)->fingerprint($tender), 'confirmed' => true];
}

function questionData(int $version = 1, array $extra = []): array
{
    return ['version' => $version, 'confirmed' => true, ...$extra];
}

it('gates all question content and actions by active Pro or Ultra without side effects on reads', function (string $plan, bool $allowed) {
    [$user, $tender] = questionFixture($plan);
    $root = '/tenders/'.$tender->id.'/questions';
    $response = $this->actingAs($user)->get($root);
    if ($allowed) {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('TenderQuestions')->has('questions', 0)->has('sources.card', 2));
    } else {
        $response->assertForbidden();
        $this->postJson($root.'/compose', questionPayload($tender))->assertForbidden();
        $this->patchJson($root.'/1', [])->assertForbidden();
        $this->postJson($root.'/1/sent', [])->assertForbidden();
        $this->patchJson($root.'/1/answer', [])->assertForbidden();
    }
    expect(TenderParticipation::query()->count())->toBe(0);
    Entitlement::query()->where('user_id', $user->id)->update(['ends_at' => now()]);
    $this->get($root)->assertForbidden();
    $this->postJson($root.'/compose', questionPayload($tender))->assertForbidden();
})->with([['basic', false], ['pro', true], ['ultra', true]]);

it('composes a cited editable question only from confirmed inputs and deduplicates creation retries', function () {
    [$user, $tender] = questionFixture();
    $root = '/tenders/'.$tender->id.'/questions';
    $payload = questionPayload($tender);
    $this->actingAs($user)->postJson($root.'/compose', [...$payload, 'confirmed' => false])->assertUnprocessable();
    $result = $this->postJson($root.'/compose', [...$payload, 'body' => 'INJECTED BODY', 'source' => ['kind' => 'verified']])->assertOk();
    $question = TenderQuestion::query()->sole();
    expect($question->body)->toContain('Провести обучение персонала.', 'Страница 4, пункт 2.3', '20 рабочих мест')->not->toContain('INJECTED BODY');
    $result->assertJsonPath('questions.0.source.kind', 'manual')->assertJsonPath('questions.0.source.user_supplied', true)->assertJsonPath('questions.0.status', 'draft');
    $this->postJson($root.'/compose', $payload)->assertOk()->assertJsonPath('question_id', $question->id)->assertJsonPath('questions.0.version', 1);
    $this->postJson($root.'/compose', [...$payload, 'title' => 'Другой вопрос'])->assertConflict();
    expect(TenderQuestion::query()->count())->toBe(1);
    expect(DB::table('tender_question_events')->count())->toBe(1);
    Http::assertNothingSent();
});

it('takes a card citation from the server and blocks stale copying until confirmed recomposition', function () {
    [$user, $tender] = questionFixture();
    $root = '/tenders/'.$tender->id.'/questions';
    $payload = [...questionPayload($tender), 'mode' => 'card', 'fragment' => 1, 'quote' => 'Поддельная цитата'];
    $this->actingAs($user)->postJson($root.'/compose', $payload)->assertOk()->assertJsonPath('questions.0.source.text', 'Необходимо обучение персонала.');
    $question = TenderQuestion::query()->sole();
    $this->get($root.'/'.$question->id.'/export?version=1&copy=1')->assertOk()->assertJsonFragment(['text' => $question->body]);
    $tender->update(['description' => 'Требуется обучение 30 специалистов.']);
    $this->get($root)->assertInertia(fn (Assert $page) => $page->has('questions.0.stale', 1));
    $this->get($root.'/'.$question->id.'/export?version=1&copy=1')->assertConflict();
    $this->postJson($root.'/'.$question->id.'/sent', questionData())->assertConflict();
    $this->postJson($root.'/compose', [...$payload, 'question_id' => $question->id, 'version' => 1])->assertConflict();
    $this->postJson($root.'/compose', [...questionPayload($tender), 'question_id' => $question->id, 'version' => 1, 'mode' => 'card', 'fragment' => 0])->assertOk()
        ->assertJsonPath('questions.0.source_revision', 2)->assertJsonPath('questions.0.source.text', 'Требуется обучение 30 специалистов.')->assertJsonCount(0, 'questions.0.stale');
    $this->get($root.'/'.$question->id.'/history')->assertOk()->assertJsonPath('events.1.snapshot.source.text', 'Необходимо обучение персонала.');
});

it('requires review before saving and sending and freezes the exact saved sent version', function () {
    [$user, $tender] = questionFixture();
    $root = '/tenders/'.$tender->id.'/questions';
    $this->actingAs($user)->postJson($root.'/compose', questionPayload($tender))->assertOk();
    $id = TenderQuestion::query()->sole()->id;
    $url = $root.'/'.$id;
    $edit = questionData(1, ['title' => 'Число участников', 'body' => 'Просим уточнить: 20 или 25 рабочих мест?']);
    $this->patchJson($url, [...$edit, 'confirmed' => false])->assertUnprocessable();
    $this->patchJson($url, $edit)->assertOk()->assertJsonPath('questions.0.version', 2);
    $this->patchJson($url, $edit)->assertConflict();
    $this->get($url.'/export?version=1')->assertConflict();
    $this->get($url.'/export?version=2')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee('20 или 25');
    $this->postJson($url.'/sent', questionData(2, ['confirmed' => false]))->assertUnprocessable();
    $this->postJson($url.'/sent', questionData(2, ['sent_reference' => 'ЭТП, обращение №5']))->assertOk()->assertJsonPath('questions.0.status', 'sent')->assertJsonPath('questions.0.version', 3);
    $this->postJson($url.'/sent', questionData(2))->assertOk()->assertJsonPath('questions.0.version', 3);
    $this->patchJson($url, [...$edit, 'version' => 3])->assertConflict();
    $this->postJson($root.'/compose', [...questionPayload($tender), 'question_id' => $id, 'version' => 3])->assertConflict();
    $this->get($url.'/history')->assertOk()->assertJsonCount(3, 'events')->assertJsonPath('events.0.snapshot.body', $edit['body'])->assertJsonPath('events.0.action', 'marked_sent');
});

it('stores attributed answers and prior answer revisions without changing the question or economics even after sources change', function () {
    [$user, $tender] = questionFixture();
    $root = '/tenders/'.$tender->id.'/questions';
    $this->actingAs($user)->postJson($root.'/compose', questionPayload($tender))->assertOk();
    $question = TenderQuestion::query()->sole();
    $url = $root.'/'.$question->id;
    $reply = ['answer' => 'Необходимо обучить 25 специалистов.', 'answer_source' => 'Разъяснение на ЭТП №7', 'answer_received_on' => now('Europe/Moscow')->toDateString()];
    $this->patchJson($url.'/answer', questionData(1, $reply))->assertUnprocessable();
    $this->postJson($url.'/sent', questionData())->assertOk();
    $this->patchJson($url.'/answer', questionData(2, [...$reply, 'answer_source' => '']))->assertUnprocessable();
    $this->patchJson($url.'/answer', questionData(2, [...$reply, 'answer_received_on' => now()->addDays(2)->toDateString()]))->assertUnprocessable();
    $tender->update(['description' => 'Новая редакция документации']);
    $this->patchJson($url.'/answer', questionData(2, $reply))->assertOk()->assertJsonPath('questions.0.status', 'answered');
    $this->patchJson($url.'/answer', questionData(3, [...$reply, 'answer' => 'Уточнение: обучение 20 специалистов.', 'answer_received_on' => null]))->assertOk()->assertJsonPath('questions.0.answer_received_on', null);
    $this->get($url.'/history')->assertJsonPath('events.1.snapshot.answer', $reply['answer'])->assertJsonPath('events.0.snapshot.answer', 'Уточнение: обучение 20 специалистов.');
    $this->get($url.'/export?version=4&copy=1')->assertOk()->assertJsonPath('text', $question->body);
    $participation = TenderParticipation::query()->sole();
    expect($participation->planned_cost)->toBeNull();
    expect($participation->economics_version)->toBe(1);
});

it('creates a PRO-02 question from a server citation without disclosing the suppliers offer and rejects changed sources', function () {
    [$user, $tender] = questionFixture();
    $part = TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'stage' => 'studying']);
    $evidence = ['ref' => 'с. 4, пункт 2.3', 'text' => 'Требуется монтаж оборудования.'];
    $check = CostGapCheck::query()->create(['participation_id' => $part->id, 'source_hash' => str_repeat('a', 64), 'tender_hash' => app(CostGapService::class)->fingerprint($tender),
        'sources' => ['customer' => ['kind' => 'file', 'title' => 'ТЗ.pdf', 'segments' => [$evidence]], 'supplier' => ['kind' => 'text', 'title' => 'КП', 'segments' => [['ref' => '1', 'text' => 'СЕКРЕТНАЯ СКИДКА ПОСТАВЩИКА']]]],
        'rows' => [['key' => 'installation', 'title' => 'Монтаж', 'requirement_evidence' => [$evidence]]], 'version' => 1]);
    $root = '/tenders/'.$tender->id.'/questions';
    $payload = [...questionPayload($tender), 'mode' => 'costgap', 'cost_key' => 'installation', 'evidence_index' => 0, 'cost_source_hash' => $check->source_hash, 'topic' => 'costs'];
    $this->actingAs($user)->postJson($root.'/compose', [...$payload, 'cost_source_hash' => str_repeat('b', 64)])->assertConflict();
    $this->postJson($root.'/compose', [...$payload, 'evidence_index' => 15])->assertUnprocessable();
    $this->postJson($root.'/compose', $payload)->assertOk()->assertJsonPath('questions.0.source.text', $evidence['text'])->assertJsonPath('questions.0.source_href', '/tenders/'.$tender->id.'/cost-check#cost-source-customer');
    $question = TenderQuestion::query()->sole();
    expect($question->body)->toContain('ТЗ.pdf', $evidence['text'])->not->toContain('СЕКРЕТНАЯ');
    $check->update(['source_hash' => str_repeat('c', 64)]);
    $this->get($root.'/'.$question->id.'/export?version=1')->assertConflict();
    $this->postJson($root.'/'.$question->id.'/sent', questionData())->assertConflict();
});

it('blocks outdated PRO-02 document citations after replacement or archive', function () {
    [$user, $tender] = questionFixture();
    $part = TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'stage' => 'studying']);
    $document = ParticipationDocument::query()->create(['participation_id' => $part->id, 'title' => 'ТЗ', 'type' => 'other', 'status' => 'draft', 'version' => 1]);
    $version = ParticipationDocumentVersion::query()->create(['document_id' => $document->id, 'source_kind' => 'file', 'created_at' => now()]);
    $evidence = ['ref' => 'абзац 2', 'text' => 'Требуется монтаж.'];
    $check = CostGapCheck::query()->create(['participation_id' => $part->id, 'source_hash' => str_repeat('a', 64), 'tender_hash' => app(CostGapService::class)->fingerprint($tender),
        'sources' => ['customer' => ['kind' => 'document', 'title' => 'ТЗ.docx', 'document_id' => $document->id, 'version_id' => $version->id, 'document_version' => 1]],
        'rows' => [['key' => 'installation', 'title' => 'Монтаж', 'requirement_evidence' => [$evidence]]]]);
    $root = '/tenders/'.$tender->id.'/questions';
    $payload = [...questionPayload($tender), 'mode' => 'costgap', 'cost_key' => 'installation', 'evidence_index' => 0, 'cost_source_hash' => $check->source_hash];
    $this->actingAs($user)->postJson($root.'/compose', $payload)->assertOk();
    $id = TenderQuestion::query()->sole()->id;
    $document->update(['version' => 2]);
    $this->postJson($root.'/'.$id.'/sent', questionData())->assertConflict();
    $document->update(['version' => 1, 'archived_at' => now()]);
    $this->get($root.'/'.$id.'/export?version=1')->assertConflict();
    $this->postJson($root.'/compose', [...$payload, 'creation_key' => (string) Str::uuid()])->assertConflict();
});

it('keeps questions scoped to the owning participation even when another user can read the same tender', function () {
    [$user, $tender] = questionFixture();
    [$other, $otherTender] = questionFixture();
    TenderQueryMatch::query()->create(['search_query_id' => SearchQuery::query()->where('user_id', $other->id)->sole()->id, 'tender_id' => $tender->id, 'match_reasons' => [], 'matched_at' => now()]);
    $root = '/tenders/'.$tender->id.'/questions';
    $this->actingAs($user)->postJson($root.'/compose', questionPayload($tender))->assertOk();
    $id = TenderQuestion::query()->sole()->id;
    $this->actingAs($other)->get($root)->assertInertia(fn (Assert $page) => $page->has('questions', 0));
    $this->get($root.'/'.$id.'/export?version=1')->assertNotFound();
    $this->get($root.'/'.$id.'/history')->assertNotFound();
    $this->patchJson($root.'/'.$id, questionData(1, ['title' => 'Чужое', 'body' => 'Попытка']))->assertNotFound();
    $this->postJson($root.'/'.$id.'/sent', questionData())->assertNotFound();
    $this->postJson($root.'/compose', [...questionPayload($tender), 'question_id' => $id, 'version' => 1])->assertNotFound();
    $this->actingAs($user)->get('/tenders/'.$otherTender->id.'/questions/'.$id.'/history')->assertNotFound();
});

it('shares team questions with authorized members and enforces personal viewer archive revoked and deleted boundaries', function () {
    [$owner, $tender] = questionFixture();
    [$member] = questionFixture();
    $team = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Отдел']);
    DB::table('team_members')->insert([['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner'], ['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'viewer']]);
    DB::table('team_search_queries')->insert(['team_id' => $team->id, 'search_query_id' => SearchQuery::query()->where('user_id', $owner->id)->sole()->id, 'shared_by_id' => $owner->id]);
    $root = '/tenders/'.$tender->id.'/questions';
    $suffix = '?team_id='.$team->id;
    $this->actingAs($owner)->postJson($root.'/compose'.$suffix, questionPayload($tender))->assertOk();
    $id = TenderQuestion::query()->sole()->id;
    $this->get($root)->assertInertia(fn (Assert $page) => $page->has('questions', 0));
    $this->actingAs($member)->get($root.$suffix)->assertInertia(fn (Assert $page) => $page->where('canEdit', false)->has('questions', 1));
    $this->get($root.'/'.$id.'/export?version=1&team_id='.$team->id)->assertOk();
    $this->patchJson($root.'/'.$id.$suffix, [])->assertForbidden();
    $this->postJson($root.'/'.$id.'/sent'.$suffix, [])->assertForbidden();
    DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member->id)->update(['role' => 'member']);
    $this->patchJson($root.'/'.$id.$suffix, questionData(1, ['title' => 'Монтаж', 'body' => 'Уточните монтаж.']))->assertOk();
    $team->update(['archived_at' => now()]);
    $this->postJson($root.'/'.$id.'/sent'.$suffix, questionData(2))->assertConflict();
    $this->get($root.$suffix)->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member->id)->delete();
    $this->get($root.'/'.$id.'/history'.$suffix)->assertNotFound();
    $team->update(['archived_at' => null]);
    $this->actingAs($owner);
    TenderParticipation::query()->sole()->delete();
    $this->get($root.$suffix)->assertNotFound();
    $this->postJson($root.'/compose'.$suffix, questionPayload($tender))->assertNotFound();
});

it('archives and restores without deleting evidence or allowing edits to archived drafts', function () {
    [$user, $tender] = questionFixture();
    $root = '/tenders/'.$tender->id.'/questions';
    $this->actingAs($user)->postJson($root.'/compose', questionPayload($tender))->assertOk();
    $url = $root.'/'.TenderQuestion::query()->sole()->id;
    $this->patchJson($url.'/archive', questionData(1, ['archived' => true]))->assertOk();
    $this->get($url.'/export?version=2')->assertConflict();
    $this->patchJson($url, questionData(2, ['title' => 'Нет', 'body' => 'Нет']))->assertConflict();
    $this->patchJson($url.'/archive', questionData(2, ['archived' => false]))->assertOk();
    $this->postJson($url.'/sent', questionData(3))->assertOk();
    $this->patchJson($url.'/archive', questionData(4, ['archived' => true]))->assertOk();
    $this->get($url.'/export?version=5')->assertOk();
    $this->get($url.'/history')->assertJsonCount(5, 'events');
});

it('rejects missing evidence invalid references and unsafe link schemes without creating participation', function () {
    [$user, $tender] = questionFixture();
    $root = '/tenders/'.$tender->id.'/questions/compose';
    $payload = questionPayload($tender);
    $this->actingAs($user)->postJson($root, [...$payload, 'source_ref' => ''])->assertUnprocessable();
    $this->postJson($root, [...$payload, 'quote' => ''])->assertUnprocessable();
    $this->postJson($root, [...$payload, 'source_url' => 'javascript:alert(1)'])->assertUnprocessable();
    $this->postJson($root, [...$payload, 'source_url' => 'https://login:secret@example.test/doc'])->assertUnprocessable();
    $this->postJson($root, [...$payload, 'mode' => 'card', 'fragment' => 999])->assertUnprocessable();
    expect(TenderQuestion::query()->count())->toBe(0);
    expect(TenderParticipation::query()->count())->toBe(0);
});

it('counts archived questions toward the limit while still accepting a successful creation retry', function () {
    [$user, $tender] = questionFixture();
    $root = '/tenders/'.$tender->id.'/questions/compose';
    $payload = questionPayload($tender);
    $this->actingAs($user)->postJson($root, $payload)->assertOk();
    $seed = TenderQuestion::query()->sole();
    for ($i = 1; $i < 100; $i++) {
        $copy = $seed->replicate();
        $copy->creation_key = (string) Str::uuid();
        $copy->archived_at = now();
        $copy->save();
    }
    $this->postJson($root, [...$payload, 'creation_key' => (string) Str::uuid()])->assertUnprocessable();
    $this->postJson($root, $payload)->assertOk()->assertJsonPath('question_id', $seed->id)->assertJsonCount(100, 'questions');
    expect(TenderQuestion::query()->count())->toBe(100);
});
