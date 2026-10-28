<?php

namespace App\Http\Controllers;

use App\Models\ProcurementComparison;
use App\Models\ProcurementNeed;
use App\Models\Team;
use App\Models\User;
use App\Services\ParticipationExecutionService;
use App\Services\ProcurementService;
use App\Services\TeamActivityService;
use App\Services\TeamWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ProcurementController extends Controller
{
    public function __construct(private readonly ProcurementService $procurement) {}

    public function index(Request $request, TeamWorkspaceService $scope): Response
    {
        $user = $request->user();
        $team = $scope->context($request);
        $enabled = $this->procurement->enabled($user);
        $needs = $enabled ? $this->procurement->needs($user, $team)->with('participation.tender')->orderBy('id')->get()
            ->map(fn (ProcurementNeed $need): array => $this->procurement->need($need))->all() : [];
        $contracts = $enabled ? $this->procurement->participations($user, $team)->with('tender')->orderByDesc('id')->limit(501)->get() : collect();

        return Inertia::render('Procurement', [...$scope->props($user, $team), 'enabled' => $enabled, 'needs' => $needs,
            'candidates' => $this->procurement->candidates($needs), 'units' => ProcurementService::UNITS,
            'contracts' => $contracts->take(500)->map(fn ($p): array => ['id' => $p->id, 'title' => $p->tender->title])->values(),
            'moreContracts' => $contracts->count() > 500,
            'comparisons' => $this->procurement->comparisons($user, $team)->when(! $enabled, fn ($q) => $q->whereRaw('1=0'))
                ->orderByDesc('id')->paginate(10)->withQueryString()->through(fn (ProcurementComparison $c): array => [
                    'id' => $c->id, 'title' => $c->title, 'status' => $c->status, 'confirmed_at' => $c->confirmed_at?->toAtomString(),
                ]),
        ]);
    }

    public function saveNeed(Request $request, TeamWorkspaceService $scope, ?int $need = null): JsonResponse
    {
        $this->assertEnabled($request->user());
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'],
            'participation_id' => [$need === null ? 'required' : 'sometimes', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:160'], 'specification' => ['present', 'nullable', 'string', 'max:2000'],
            'quantity' => ['required', 'string', 'regex:/^\d{1,5}([.,]\d{1,3})?$/'], 'unit' => ['required', Rule::in(ProcurementService::UNITS)],
            'required_on' => ['present', 'nullable', 'date_format:Y-m-d'],
        ], $this->messages());
        abort_unless($this->procurement->quantity($data['quantity']) > 0, 422, 'Количество должно быть больше нуля.');
        abort_if($this->procurement->matchKey($data['title']) === '', 422, 'В названии нужны буквы или цифры.');
        $id = DB::transaction(function () use ($request, $scope, $data, $need): int {
            [$user, $team] = $this->lock($request, $scope);
            $row = $need === null ? new ProcurementNeed : $this->procurement->needs($user, $team)->lockForUpdate()->findOrFail($need);
            abort_if($row->confirmed_comparison_id !== null, 409, 'Потребность уже распределена в подтверждённой закупке. Сначала отмените её подтверждение.');
            abort_unless(($row->version ?? 0) === (int) $data['version'], 409, 'Потребность изменилась. Обновите страницу.');
            if ($need === null) {
                $p = $this->procurement->participations($user, $team)->lockForUpdate()->findOrFail($data['participation_id']);
                abort_if($this->procurement->needs($user, $team)->count() >= 200, 422, 'В рабочем пространстве допускается до 200 активных потребностей. Уберите ненужные позиции.');
                $row->participation_id = $p->id;
            }
            $row->fill(['title' => $data['title'], 'specification' => $data['specification'], 'match_key' => $this->procurement->matchKey($data['title']),
                'quantity' => $this->procurement->quantityText($this->procurement->quantity($data['quantity'])), 'unit' => $data['unit'],
                'required_on' => $data['required_on'], 'version' => (int) $data['version'] + 1])->save();
            $this->activity($team, $user, 'procurement_need_saved', ['need_id' => $row->id, 'participation_id' => $row->participation_id]);

            return $row->id;
        });

        return response()->json(['id' => $id]);
    }

    public function deleteNeed(Request $request, int $need, TeamWorkspaceService $scope): JsonResponse
    {
        $this->assertEnabled($request->user());
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $scope, $need, $data): void {
            [$user, $team] = $this->lock($request, $scope);
            $row = $this->procurement->needs($user, $team)->lockForUpdate()->findOrFail($need);
            abort_if($row->confirmed_comparison_id !== null, 409, 'Сначала отмените подтверждённую закупку, в которую входит эта потребность.');
            abort_unless($row->version === (int) $data['version'], 409, 'Потребность изменилась. Обновите страницу.');
            $row->delete();
            $this->activity($team, $user, 'procurement_need_deleted', ['need_id' => $need]);
        });

        return response()->json(['deleted' => true]);
    }

    public function createComparison(Request $request, TeamWorkspaceService $scope): JsonResponse
    {
        $this->assertEnabled($request->user());
        $data = $request->validate(['needs' => ['required', 'array', 'min:2', 'max:20'], 'needs.*' => ['array:id,version'],
            'needs.*.id' => ['required', 'integer', 'distinct', 'min:1'], 'needs.*.version' => ['required', 'integer', 'min:1']], $this->messages());
        $comparison = DB::transaction(function () use ($request, $scope, $data): ProcurementComparison {
            [$user, $team] = $this->lock($request, $scope);
            abort_if($this->procurement->comparisons($user, $team)->where('status', '!=', 'canceled')->count() >= 30, 422, 'Закройте ненужные сравнения: допускается до 30 открытых.');
            $selection = [];
            $offers = [];
            $contracts = [];
            $unit = null;
            foreach ($data['needs'] as $selected) {
                $need = $this->procurement->needs($user, $team)->with('participation.tender')->lockForUpdate()->findOrFail($selected['id']);
                abort_if($need->confirmed_comparison_id !== null, 409, 'Одна из потребностей уже распределена в другой закупке.');
                abort_unless($need->version === (int) $selected['version'], 409, 'Потребности изменились. Обновите страницу.');
                abort_if(isset($contracts[$need->participation_id]), 422, 'Выберите одну потребность на контракт. Одинаковые позиции внутри одного контракта сначала объедините вручную.');
                abort_if($unit !== null && $unit !== $need->unit, 422, 'Сравнивайте позиции с одинаковой единицей измерения. Пересчёт единиц автоматически не выполняется.');
                $unit = $need->unit;
                $contracts[$need->participation_id] = true;
                $selection[] = $this->procurement->need($need);
                $offers[] = ['need_id' => $need->id, ...$this->procurement->emptyOffer()];
            }
            $comparison = ProcurementComparison::query()->create(['user_id' => $user->id, 'team_id' => $team?->id, 'title' => $selection[0]['title'],
                'selection' => $selection, 'offers' => $offers, 'combined_offer' => $this->procurement->emptyOffer(), 'version' => 1]);
            $this->activity($team, $user, 'procurement_comparison_created', ['comparison_id' => $comparison->id]);

            return $comparison;
        });

        return response()->json(['id' => $comparison->id]);
    }

    public function show(Request $request, int $comparison, TeamWorkspaceService $scope): Response
    {
        $user = $request->user();
        $this->assertEnabled($user);
        $team = $scope->context($request);
        $row = $this->procurement->comparisons($user, $team)->findOrFail($comparison);

        return Inertia::render('ProcurementComparison', [...$scope->props($user, $team),
            'comparison' => $this->procurement->present($row, $user, $team)]);
    }

    public function saveComparison(Request $request, int $comparison, TeamWorkspaceService $scope): JsonResponse
    {
        $this->assertEnabled($request->user());
        $offerRules = ['supplier' => ['present', 'nullable', 'string', 'max:160'], 'reference' => ['present', 'nullable', 'string', 'max:500'],
            'quoted_on' => ['present', 'nullable', 'date_format:Y-m-d'], 'valid_until' => ['present', 'nullable', 'date_format:Y-m-d'],
            'unit_price' => ['present', 'nullable', 'string', 'regex:/^\d{1,6}([.,]\d{1,2})?$/'],
            'delivery_cost' => ['present', 'nullable', 'string', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'], 'delivery_on' => ['present', 'nullable', 'date_format:Y-m-d']];
        $rules = ['version' => ['required', 'integer', 'min:1'], 'conditions_note' => ['present', 'nullable', 'string', 'max:2000'],
            'offers' => ['required', 'array', 'min:2', 'max:20'], 'offers.*' => ['array:need_id,'.implode(',', array_keys($offerRules))],
            'offers.*.need_id' => ['required', 'integer', 'distinct', 'min:1'], 'combined_offer' => ['required', 'array:'.implode(',', array_keys($offerRules))]];
        foreach ($offerRules as $field => $rule) {
            $rules['offers.*.'.$field] = $rule;
            $rules['combined_offer.'.$field] = $rule;
        }
        $data = $request->validate($rules, $this->messages());
        $result = DB::transaction(function () use ($request, $scope, $comparison, $data): array {
            [$user, $team] = $this->lock($request, $scope);
            $row = $this->procurement->comparisons($user, $team)->lockForUpdate()->findOrFail($comparison);
            $this->assertVersion($row, $data['version']);
            abort_unless($row->status === 'draft', 409, 'Подтверждённое или закрытое сравнение не редактируется. Создайте новое.');
            abort_if($this->procurement->staleReasons($user, $team, $row->selection) !== [], 409, 'Исходные потребности изменились. Создайте новое сравнение.');
            $ids = array_map('intval', array_column($data['offers'], 'need_id'));
            $expected = array_column($row->selection, 'id');
            sort($ids);
            sort($expected);
            abort_unless($ids === $expected, 422, 'Для каждой выбранной потребности нужно ровно одно отдельное предложение.');
            $normalize = function (array $offer): array {
                if (isset($offer['need_id'])) {
                    $offer['need_id'] = (int) $offer['need_id'];
                }
                foreach (['unit_price', 'delivery_cost'] as $field) {
                    $offer[$field] = app(ParticipationExecutionService::class)->amount(app(ParticipationExecutionService::class)->minor($offer[$field]));
                }

                return $offer;
            };
            $row->fill(['offers' => array_map($normalize, $data['offers']), 'combined_offer' => $normalize($data['combined_offer']),
                'conditions_note' => $data['conditions_note'], 'version' => $row->version + 1])->save();
            $this->activity($team, $user, 'procurement_quotes_saved', ['comparison_id' => $row->id, 'version' => $row->version]);

            return $this->procurement->present($row, $user, $team);
        });

        return response()->json(['comparison' => $result]);
    }

    public function decision(Request $request, int $comparison, string $decision, TeamWorkspaceService $scope): JsonResponse
    {
        $this->assertEnabled($request->user());
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']], $this->messages());
        $result = DB::transaction(function () use ($request, $scope, $comparison, $decision, $data): array {
            [$user, $team] = $this->lock($request, $scope);
            $row = $this->procurement->comparisons($user, $team)->lockForUpdate()->findOrFail($comparison);
            if (($decision === 'confirm' && $row->status === 'confirmed') || ($decision === 'cancel' && $row->status === 'canceled')) {
                return $this->procurement->present($row, $user, $team);
            }
            $this->assertVersion($row, $data['version']);
            if ($decision === 'confirm') {
                abort_unless($row->status === 'draft', 409, 'Закрытое сравнение подтвердить нельзя. Создайте новое.');
                // Lock source participations too: stage changes/deletion must not race confirmation.
                $this->procurement->participations($user, $team)->whereIn('id', array_column($row->selection, 'participation_id'))->orderBy('id')->lockForUpdate()->get();
                abort_if($this->procurement->staleReasons($user, $team, $row->selection) !== [], 409, 'Заявки изменились. Обновите страницу.');
                $calculation = $this->procurement->calculate($row);
                abort_unless($calculation['complete'], 422, 'Расчёт неполный. Проверьте характеристики, сроки и реальные предложения поставщиков.');
                abort_unless($calculation['saving'] !== null && $calculation['saving'] !== '0.00' && ! str_starts_with($calculation['saving'], '-'), 422, 'Общая закупка не даёт экономии. Скорректируйте предложение или закройте сравнение.');
                $needs = $this->procurement->needs($user, $team)->whereIn('id', array_column($row->selection, 'id'))->orderBy('id')->lockForUpdate()->get();
                abort_unless($needs->count() === count($row->selection), 409, 'Одна из потребностей больше не доступна. Создайте новое сравнение.');
                abort_if($needs->contains(fn (ProcurementNeed $need): bool => $need->confirmed_comparison_id !== null), 409, 'Одна из потребностей уже распределена. Повторный учёт запрещён.');
                foreach ($needs as $need) {
                    $need->update(['confirmed_comparison_id' => $row->id]);
                }
                $row->fill(['status' => 'confirmed', 'confirmed_result' => $calculation, 'confirmed_at' => now(), 'confirmed_by' => $user->id, 'version' => $row->version + 1])->save();
            } else {
                ProcurementNeed::query()->where('confirmed_comparison_id', $row->id)->update(['confirmed_comparison_id' => null]);
                $row->fill(['status' => 'canceled', 'canceled_at' => now(), 'version' => $row->version + 1])->save();
            }
            $this->activity($team, $user, 'procurement_'.$decision, ['comparison_id' => $row->id, 'version' => $row->version]);

            return $this->procurement->present($row, $user, $team);
        });

        return response()->json(['comparison' => $result]);
    }

    /** @return array{User, ?Team} */
    private function lock(Request $request, TeamWorkspaceService $scope): array
    {
        $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
        $this->assertEnabled($user);
        $team = $scope->context($request, true);
        $scope->lock($user, $team);
        if ($team) {
            $team->refresh();
            $scope->authorize($user, $team, true);
        }

        return [$user, $team];
    }

    private function assertEnabled(User $user): void
    {
        abort_unless($this->procurement->enabled($user), 403, 'Объединение закупок доступно в действующем Ultra.');
    }

    private function assertVersion(ProcurementComparison $row, int $version): void
    {
        abort_unless($row->version === $version, 409, 'Сравнение изменено в другой вкладке. Обновите страницу; ваши правки остались в форме.');
    }

    /** @param array<string, int> $context */
    private function activity(?Team $team, User $user, string $action, array $context): void
    {
        if ($team) {
            app(TeamActivityService::class)->record($team, $user, $action, $context);
        }
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return ['regex' => 'Проверьте формат: количество до 99 999,999; цена до 999 999,99 ₽; доставка до 999 999 999,99 ₽.',
            'required' => 'Заполните обязательные поля.', 'present' => 'Переданы не все поля. Обновите страницу.',
            'accepted' => 'Подтвердите проверку условий.', 'distinct' => 'Одна позиция выбрана несколько раз.',
            'date_format' => 'Укажите дату в формате ГГГГ-ММ-ДД.', 'max' => 'Превышен размер поля или число позиций.'];
    }
}
