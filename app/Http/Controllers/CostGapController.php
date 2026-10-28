<?php

namespace App\Http\Controllers;

use App\Models\CostGapCheck;
use App\Models\CostGapTransfer;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\User;
use App\Services\CostGap\CostGapService;
use App\Services\CostGap\ObligationAnalyzer;
use App\Services\CostGap\SourceReader;
use App\Services\TeamActivityService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class CostGapController extends Controller
{
    public function __construct(private readonly CostGapService $checks, private readonly SourceReader $reader) {}

    public function show(Request $request, Tender $tender): Response
    {
        $user = $this->user($request);
        $team = $this->authorizeTender($request, $user, $tender);
        $participation = $this->participation($user, $tender, $team);
        $documents = $participation?->documents()->whereNull('archived_at')->with('versions')->orderBy('id')->get()->map(function ($document): ?array {
            $version = $document->versions->sortByDesc('id')->first();
            if ($version === null || $version->source_kind !== 'file') {
                return null;
            }

            return ['id' => $version->id, 'title' => $document->title.' · v'.$document->version.' · '.$version->original_name];
        })->filter()->values()->all() ?? [];

        return Inertia::render('CostGapCheck', [
            'tender' => ['id' => $tender->id, 'title' => $tender->title, 'fingerprint' => $this->checks->fingerprint($tender)],
            'check' => $this->checks->present($this->current($participation), $tender, $participation), 'documents' => $documents,
            'teamId' => $team?->id, 'canEdit' => $team === null || ($team->archived_at === null && app(TeamWorkspaceService::class)->role($user, $team) !== 'viewer'),
        ]);
    }

    public function analyze(Request $request, Tender $tender): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->authorizeTender($request, $user, $tender, true);
        $rules = [...$this->versionRules(), 'confirmed' => ['required', 'accepted'], 'manual_requirements' => ['nullable', 'string', 'max:15000']];
        foreach (['customer', 'supplier'] as $side) {
            $rules[$side.'_mode'] = ['required', Rule::in(['text', 'file', 'document', 'keep'])];
            $rules[$side.'_text'] = ['nullable', 'required_if:'.$side.'_mode,text', 'string', 'max:40000'];
            $rules[$side.'_file'] = ['nullable', 'required_if:'.$side.'_mode,file', 'file', 'max:5120'];
            $rules[$side.'_document'] = ['nullable', 'required_if:'.$side.'_mode,document', 'integer', 'min:1'];
        }
        $data = $request->validate($rules);
        $participation = $this->participation($user, $tender, $team);
        $previous = $this->current($participation);
        $sources = [];
        foreach (['customer' => 'Требования заказчика', 'supplier' => 'Предложение поставщика'] as $side => $title) {
            try {
                $sources[$side] = match ($data[$side.'_mode']) {
                    'text' => $this->reader->text($data[$side.'_text'], $title),
                    'file' => $this->reader->upload($request->file($side.'_file')),
                    'document' => $participation !== null ? $this->reader->document($participation, (int) $data[$side.'_document']) : abort(404),
                    'keep' => $previous?->sources[$side] ?? abort(422, 'Сохранённый источник отсутствует. Выберите текст или файл.'),
                    default => abort(422),
                };
            } catch (ValidationException $error) {
                throw ValidationException::withMessages([$side.'_source' => array_merge(...array_values($error->errors()))]);
            }
        }
        $manual = trim($data['manual_requirements'] ?? '');
        $rows = app(ObligationAnalyzer::class)->compare($sources['customer'], $sources['supplier'], $manual);
        $hash = hash('sha256', json_encode([$sources, $manual], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($request, $user, $tender, $data, $sources, $manual, $rows, $hash): void {
            [$actor, $current, $team] = $this->lock($request, $user, $tender);
            $this->tenderVersion($current, $data);
            $participation = $this->participation($actor, $current, $team, true);
            foreach ($sources as $source) {
                abort_unless($participation === null ? $source['kind'] !== 'document' : $this->reader->current($source, $participation, true), 409, 'Документ изменился. Выберите его актуальную версию.');
            }
            $check = $this->current($participation, true);
            $this->version($check, $data);
            $participation ??= TenderParticipation::query()->create(['user_id' => $actor->id, 'team_id' => $team?->id, 'tender_id' => $current->id, 'stage' => 'studying']);
            $decisions = $check !== null && $check->source_hash === $hash && $check->tender_hash === $this->checks->fingerprint($current) ? $check->decisions : [];
            $check ??= new CostGapCheck;
            $check->fill(['participation_id' => $participation->id, 'sources' => $sources, 'rows' => $rows,
                'manual_requirements' => $manual, 'source_hash' => $hash, 'tender_hash' => $this->checks->fingerprint($current),
                'decisions' => $decisions, 'updated_by' => $actor->id, 'version' => (int) $data['version'] + 1])->save();
            $this->audit($actor, $team, 'cost_check_analyzed', $participation, $check);
        });

        return $this->response($user, $tender, $team);
    }

    public function review(Request $request, Tender $tender): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->authorizeTender($request, $user, $tender, true);
        $data = $request->validate([...$this->versionRules(), 'key' => ['required', 'string', 'max:80'], 'confirmed' => ['required', 'accepted'],
            'status' => ['required', Rule::in(['included', 'not_mentioned', 'clarify'])], 'note' => ['nullable', 'string', 'max:2000'],
            'amount' => ['nullable', 'string', 'regex:/^\d{1,12}([.,]\d{1,2})?$/']]);
        DB::transaction(function () use ($request, $user, $tender, $data): void {
            [$actor, $current, $team] = $this->lock($request, $user, $tender);
            $participation = $this->participation($actor, $current, $team, true);
            $check = $this->current($participation, true);
            abort_if($check === null || $participation === null, 404);
            $this->assertCurrent($check, $current, $participation, $data);
            abort_unless(collect($check->rows)->contains('key', $data['key']), 404);
            abort_if(CostGapTransfer::query()->where('cost_gap_check_id', $check->id)->where('requirement_key', $data['key'])->exists(), 409, 'Расход уже перенесён. Корректируйте его в экономике заявки; история проверки сохранена.');
            $decisions = $check->decisions ?? [];
            $decisions[$data['key']] = ['status' => $data['status'], 'note' => trim($data['note'] ?? ''),
                'amount' => isset($data['amount']) ? $this->checks->decimal($this->checks->minor($data['amount'])) : null,
                'reviewed_by' => $actor->id, 'reviewed_at' => now()->toAtomString()];
            $check->update(['decisions' => $decisions, 'version' => $check->version + 1, 'updated_by' => $actor->id]);
            $this->audit($actor, $team, 'cost_check_reviewed', $participation, $check);
        });

        return $this->response($user, $tender, $team);
    }

    public function transfer(Request $request, Tender $tender): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->authorizeTender($request, $user, $tender, true);
        $data = $request->validate([...$this->versionRules(), 'key' => ['required', 'string', 'max:80'],
            'economics_version' => ['required', 'integer', 'min:1'], 'category' => ['required', Rule::in(CostGapService::CATEGORIES)],
            'confirmed_new_expense' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $user, $tender, $data): void {
            [$actor, $current, $team] = $this->lock($request, $user, $tender);
            $participation = $this->participation($actor, $current, $team, true);
            $check = $this->current($participation, true);
            abort_if($check === null || $participation === null, 404);
            // A successful receipt wins over stale retry versions. Authorization is always checked first.
            if (CostGapTransfer::query()->where('cost_gap_check_id', $check->id)->where('requirement_key', $data['key'])->exists()) {
                return;
            }
            $this->assertCurrent($check, $current, $participation, $data);
            abort_unless($participation->economics_version === (int) $data['economics_version'], 409, 'Экономика изменена. Обновите страницу перед переносом.');
            abort_unless(strtoupper($current->currency ?? '') === 'RUB', 422, 'Перенос поддерживается для закупок в рублях. Конвертация валют не выполняется.');
            $row = collect($check->rows)->firstWhere('key', $data['key']);
            abort_if($row === null, 404);
            $decision = $check->decisions[$data['key']] ?? null;
            abort_unless($decision !== null && $decision['status'] !== 'included' && $decision['amount'] !== null && $this->checks->minor($decision['amount']) > 0, 422, 'Подтвердите вывод и укажите положительную сумму дополнительного расхода.');
            $category = $data['category'];
            $previous = $participation->getAttribute($category);
            abort_if($previous === null, 422, 'Сумма категории неизвестна. Сначала задайте её в экономике заявки, включая явный ноль, если расходов пока нет.');
            $result = $this->checks->minor($previous) + $this->checks->minor($decision['amount']);
            abort_if($result > 999999999999999999, 422, 'Итог превышает допустимую сумму категории.');
            $participation->update([$category => $this->checks->decimal($result), 'economics_version' => $participation->economics_version + 1]);
            CostGapTransfer::query()->create(['cost_gap_check_id' => $check->id, 'requirement_key' => $data['key'],
                'category' => $category, 'amount' => $decision['amount'], 'previous_amount' => $previous,
                'result_amount' => $this->checks->decimal($result), 'economics_version' => $participation->economics_version,
                'applied_by' => $actor->id, 'snapshot' => ['row' => $row, 'decision' => $decision, 'source_hash' => $check->source_hash]]);
            $check->update(['version' => $check->version + 1, 'updated_by' => $actor->id]);
            $this->audit($actor, $team, 'cost_check_transferred', $participation, $check);
        });

        return $this->response($user, $tender, $team);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->checks->enabled($user), 403, 'Проверка расходов доступна при действующем Pro или Ultra.');

        return $user;
    }

    private function authorizeTender(Request $request, User $user, Tender $tender, bool $write = false): ?Team
    {
        $team = app(TeamWorkspaceService::class)->context($request, $write);
        if ($team !== null) {
            abort_if(TenderParticipation::onlyTrashed()->where('team_id', $team->id)->where('tender_id', $tender->id)->exists(), 404);
            $shared = DB::table('tender_query_matches')->where('tender_id', $tender->id)
                ->whereIn('search_query_id', DB::table('team_search_queries')->where('team_id', $team->id)->select('search_query_id'))->exists();
            abort_unless($shared || $this->participation($user, $tender, $team) !== null, 404);
        } else {
            app(TenderWorkService::class)->authorize($user, $tender);
            abort_if(TenderParticipation::onlyTrashed()->where('user_id', $user->id)->whereNull('team_id')->where('tender_id', $tender->id)->exists(), 404);
        }

        return $team;
    }

    /**
     * Lock order: actor → team → tender → participation → check → source documents.
     *
     * @return array{User, Tender, ?Team}
     */
    private function lock(Request $request, User $user, Tender $tender): array
    {
        $actor = User::query()->lockForUpdate()->findOrFail($user->id);
        abort_unless($this->checks->enabled($actor), 403);
        $teamId = $request->query('team_id');
        if ($teamId !== null) {
            $team = Team::query()->lockForUpdate()->findOrFail($teamId);
            app(TeamWorkspaceService::class)->authorize($actor, $team, true);
        }
        $current = Tender::query()->lockForUpdate()->findOrFail($tender->id);
        $team = $this->authorizeTender($request, $actor, $current, true);

        return [$actor, $current, $team];
    }

    private function participation(User $user, Tender $tender, ?Team $team, bool $lock = false): ?TenderParticipation
    {
        $query = app(TenderWorkService::class)->participations($user, $team)->where('tender_id', $tender->id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function current(?TenderParticipation $participation, bool $lock = false): ?CostGapCheck
    {
        if ($participation === null) {
            return null;
        }
        $query = CostGapCheck::query()->where('participation_id', $participation->id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** @return array<string, list<string>> */
    private function versionRules(): array
    {
        return ['version' => ['required', 'integer', 'min:0'], 'tender_hash' => ['required', 'string', 'size:64']];
    }

    /** @param array<string, mixed> $data */
    private function version(?CostGapCheck $check, array $data): void
    {
        abort_unless(($check->version ?? 0) === (int) $data['version'], 409, 'Проверка изменена в другой вкладке. Обновите страницу.');
    }

    /** @param array<string, mixed> $data */
    private function tenderVersion(Tender $tender, array $data): void
    {
        abort_unless($this->checks->fingerprint($tender) === $data['tender_hash'], 409, 'Карточка закупки изменилась. Обновите страницу.');
    }

    /** @param array<string, mixed> $data */
    private function assertCurrent(CostGapCheck $check, Tender $tender, TenderParticipation $participation, array $data): void
    {
        $this->version($check, $data);
        $this->tenderVersion($tender, $data);
        abort_if($this->checks->stale($check, $tender, $participation, true) !== [], 409, 'Исходные данные изменились. Проверьте их и повторите сравнение.');
    }

    private function audit(User $actor, ?Team $team, string $event, TenderParticipation $participation, CostGapCheck $check): void
    {
        if ($team !== null) {
            app(TeamActivityService::class)->record($team, $actor, $event, ['participation_id' => $participation->id,
                'cost_gap_check_id' => $check->id, 'version' => $check->version]);
        }
    }

    private function response(User $user, Tender $tender, ?Team $team): JsonResponse
    {
        $participation = $this->participation($user, $tender, $team);

        return response()->json(['check' => $this->checks->present($this->current($participation), $tender->refresh(), $participation)]);
    }
}
