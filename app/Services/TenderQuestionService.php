<?php

namespace App\Services;

use App\Models\CostGapCheck;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQuestion;
use App\Models\User;
use App\Services\CostGap\CostGapService;
use App\Services\CostGap\DocumentParseException;
use App\Services\CostGap\DocumentParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenderQuestionService
{
    public const TOPICS = [
        'general' => 'Уточнение условия', 'scope' => 'Объём и комплектация', 'deadlines' => 'Сроки исполнения',
        'costs' => 'Состав цены и расходы', 'documents' => 'Документы и подтверждения',
    ];

    private const PROMPTS = [
        'general' => 'Просим разъяснить указанное условие и порядок его исполнения.',
        'scope' => 'Просим уточнить требуемые объём, состав работ или комплектацию по указанному условию.',
        'deadlines' => 'Просим уточнить сроки исполнения и событие, с которого они исчисляются, по указанному условию.',
        'costs' => 'Просим уточнить, какие работы и расходы по указанному условию необходимо включить в цену предложения и какая сторона их оплачивает.',
        'documents' => 'Просим уточнить перечень подтверждающих документов и этап, на котором их необходимо предоставить.',
    ];

    public function enabled(User $user): bool
    {
        return app(AccessService::class)->hasActiveAccess($user)
            && in_array(app(TenderAnalyticsService::class)->tierFor($user), ['pro', 'ultra'], true);
    }

    public function fingerprint(Tender $tender): string
    {
        return app(CostGapService::class)->fingerprint($tender);
    }

    public function scope(Request $request, User $user, Tender $tender, bool $write = false): ?Team
    {
        $team = app(TeamWorkspaceService::class)->context($request, $write);
        $query = app(TenderWorkService::class)->participations($user, $team)->where('tender_id', $tender->id);
        abort_if((clone $query)->onlyTrashed()->exists(), 404);
        if ($team !== null) {
            $shared = DB::table('tender_query_matches')->where('tender_id', $tender->id)
                ->whereIn('search_query_id', DB::table('team_search_queries')->where('team_id', $team->id)->select('search_query_id'))->exists();
            abort_unless($shared || $query->exists(), 404);
        } else {
            app(TenderWorkService::class)->authorize($user, $tender);
        }

        return $team;
    }

    /** @return array{User, Tender, ?Team} */
    public function lock(Request $request, User $user, Tender $tender): array
    {
        $actor = User::query()->lockForUpdate()->findOrFail($user->id);
        abort_unless($this->enabled($actor), 403);
        if ($request->query('team_id') !== null) {
            $team = Team::query()->lockForUpdate()->findOrFail($request->query('team_id'));
            app(TeamWorkspaceService::class)->authorize($actor, $team, true);
        }
        $current = Tender::query()->lockForUpdate()->findOrFail($tender->id);

        return [$actor, $current, $this->scope($request, $actor, $current, true)];
    }

    public function participation(User $user, Tender $tender, ?Team $team, bool $lock = false): ?TenderParticipation
    {
        $query = app(TenderWorkService::class)->participations($user, $team)->where('tender_id', $tender->id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** @return list<array{ref: string, text: string}> */
    public function cardFragments(Tender $tender): array
    {
        $text = html_entity_decode(strip_tags($tender->description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        try {
            return (new DocumentParser)->segments($text, 'Описание карточки, строка');
        } catch (DocumentParseException) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    public function sources(Tender $tender, ?TenderParticipation $participation): array
    {
        $check = $this->check($participation);
        $stale = $check !== null && $participation !== null ? app(CostGapService::class)->stale($check, $tender, $participation) : [];

        return ['card' => $this->cardFragments($tender), 'cost_hash' => $check->source_hash ?? '', 'cost_stale' => $stale,
            'cost' => $check === null ? [] : array_map(fn (array $row): array => [
                'key' => $row['key'], 'title' => $row['title'], 'evidence' => $row['requirement_evidence'],
                'source_title' => $check->sources['customer']['title'],
            ], $check->rows)];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function source(Tender $tender, ?TenderParticipation $participation, array $data): array
    {
        if ($data['mode'] === 'manual') {
            return ['kind' => 'manual', 'title' => $data['source_title'], 'ref' => $data['source_ref'],
                'text' => $data['quote'], 'url' => $data['source_url'] ?? null, 'user_supplied' => true];
        }
        if ($data['mode'] === 'card') {
            $fragment = $this->cardFragments($tender)[$data['fragment']] ?? null;
            abort_if($fragment === null, 422, 'Выберите доступный фрагмент описания или вставьте условие вручную.');

            return ['kind' => 'card', 'title' => 'Карточка закупки', ...$fragment, 'user_supplied' => false];
        }
        $check = $this->check($participation, true);
        abort_if($check === null || $participation === null, 404);
        abort_unless($check->source_hash === $data['cost_source_hash'], 409, 'Источники PRO-02 изменились. Обновите страницу и выберите актуальную цитату.');
        abort_if(app(CostGapService::class)->stale($check, $tender, $participation, true) !== [], 409, 'Сначала обновите сравнение PRO-02: его материалы изменились.');
        $row = collect($check->rows)->firstWhere('key', $data['cost_key']);
        $evidence = $row['requirement_evidence'][$data['evidence_index']] ?? null;
        abort_if($evidence === null, 422, 'Выберите существующую цитату требования PRO-02.');
        $manual = str_starts_with($evidence['ref'], 'Ваш перечень');

        return ['kind' => 'costgap', 'title' => $manual ? 'Ваш перечень PRO-02' : $check->sources['customer']['title'],
            ...$evidence, 'user_supplied' => $manual || $check->sources['customer']['kind'] === 'text',
            'cost_check_id' => $check->id, 'cost_source_hash' => $check->source_hash,
            'cost_key' => $data['cost_key'], 'evidence_index' => (int) $data['evidence_index']];
    }

    /** @param array<string, mixed> $source */
    public function compose(Tender $tender, array $source, string $topic, ?string $context): string
    {
        return "Здравствуйте!\n\nПо закупке «".$tender->title."».\nВ источнике «".$source['title'].'» ('.$source['ref'].") указано:\n«".$source['text']."».\n\n"
            .(filled($context) ? 'Нужно уточнить: '.$context."\n\n" : '').self::PROMPTS[$topic];
    }

    /** @return list<string> */
    public function stale(TenderQuestion $question, Tender $tender, TenderParticipation $participation, bool $lock = false): array
    {
        $reasons = [];
        if ($question->tender_hash !== $this->fingerprint($tender)) {
            $reasons[] = 'Карточка закупки изменилась.';
        }
        if ($question->source['kind'] === 'costgap') {
            $check = $this->check($participation, $lock);
            if ($check === null || $check->id !== $question->source['cost_check_id'] || $check->source_hash !== $question->source['cost_source_hash']) {
                $reasons[] = 'Исходные материалы PRO-02 изменились.';
            } else {
                $reasons = [...$reasons, ...app(CostGapService::class)->stale($check, $tender, $participation, $lock)];
            }
        }

        return array_values(array_unique($reasons));
    }

    /** @return list<array<string, mixed>> */
    public function listing(Tender $tender, ?TenderParticipation $participation, ?Team $team): array
    {
        if ($participation === null) {
            return [];
        }

        return TenderQuestion::query()->where('participation_id', $participation->id)->orderByDesc('id')->get()
            ->map(fn (TenderQuestion $question): array => $this->present($question, $tender, $participation, $team))->all();
    }

    /** @return array<string, mixed> */
    public function present(TenderQuestion $question, Tender $tender, TenderParticipation $participation, ?Team $team): array
    {
        $suffix = $team !== null ? '?team_id='.$team->id : '';
        $source = $question->source;
        $href = match ($source['kind']) {
            'costgap' => '/tenders/'.$tender->id.'/cost-check'.$suffix.'#cost-source-customer',
            'card' => '/tenders/'.$tender->id.'/questions'.$suffix.'#question-card-source',
            default => $source['url'] ?? null,
        };

        return [...$question->only(['id', 'title', 'topic', 'context', 'body', 'source', 'source_revision', 'version', 'sent_reference', 'answer', 'answer_source']),
            'source_href' => $href, 'stale' => $this->stale($question, $tender, $participation),
            'marked_sent_at' => $question->marked_sent_at?->toAtomString(), 'archived_at' => $question->archived_at?->toAtomString(),
            'answer_received_on' => $question->answer_received_on?->toDateString(), 'answer_updated_at' => $question->answer_updated_at?->toAtomString(),
            'status' => $question->answer !== null ? 'answered' : ($question->marked_sent_at !== null ? 'sent' : 'draft')];
    }

    public function record(TenderQuestion $question, User $actor, ?Team $team, string $action): void
    {
        DB::table('tender_question_events')->insert(['question_id' => $question->id, 'actor_id' => $actor->id,
            'action' => $action, 'version' => $question->version, 'snapshot' => json_encode($question->only([
                'title', 'topic', 'context', 'body', 'source', 'tender_hash', 'marked_sent_at', 'sent_reference', 'answer', 'answer_source', 'answer_received_on', 'archived_at',
            ]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now()]);
        if ($team !== null) {
            app(TeamActivityService::class)->record($team, $actor, 'question_'.$action,
                ['participation_id' => $question->participation_id, 'question_id' => $question->id, 'version' => $question->version]);
        }
    }

    private function check(?TenderParticipation $participation, bool $lock = false): ?CostGapCheck
    {
        if ($participation === null) {
            return null;
        }
        $query = CostGapCheck::query()->where('participation_id', $participation->id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
}
