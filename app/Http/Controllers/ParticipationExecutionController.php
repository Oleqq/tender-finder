<?php

namespace App\Http\Controllers;

use App\Enums\ParticipationStage;
use App\Models\Tender;
use App\Models\User;
use App\Services\ParticipationExecutionService;
use App\Services\TeamActivityService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ParticipationExecutionController extends Controller
{
    public function __construct(private readonly ParticipationExecutionService $execution) {}

    public function show(Request $request, Tender $tender, TenderWorkService $work, TeamWorkspaceService $scope): Response
    {
        abort_unless($this->execution->enabled($request->user()), 403, 'Контроль исполнения доступен в Ultra.');
        $team = $scope->context($request);
        $work->authorize($request->user(), $tender, $team);
        $p = $work->participations($request->user(), $team)->where('tender_id', $tender->id)->firstOrFail();
        $current = $this->execution->current($p);

        return Inertia::render('ParticipationExecution', [...$scope->props($request->user(), $team),
            'tender' => ['id' => $tender->id, 'title' => $tender->title], 'stage' => $p->stage->value,
            'economicsVersion' => $p->economics_version, 'categories' => ParticipationExecutionService::CATEGORIES,
            'execution' => $current ? $this->execution->present($current, $p) : null,
            'tasks' => $p->items()->orderBy('id')->get(['id', 'title']),
            'source' => array_intersect_key($work->economics($p), array_flip(['planned_revenue', ...array_keys(ParticipationExecutionService::CATEGORIES)])),
        ]);
    }

    public function save(Request $request, Tender $tender, TenderWorkService $work, TeamWorkspaceService $scope): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->execution->enabled($user), 403, 'Контроль исполнения доступен в Ultra.');
        $team = $scope->context($request, true);
        $work->authorize($user, $tender, $team);
        $start = $request->isMethod('post');
        $money = ['present', 'nullable', 'string', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'];
        $data = $request->validate($start ? ['economics_version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']] : [
            'version' => ['required', 'integer', 'min:1'], 'received_revenue' => $money, 'remaining_revenue' => $money,
            'costs_confirmed' => ['required', 'boolean'], 'expenses' => ['required', 'array', 'min:4', 'max:100'],
            'expenses.*' => ['array:id,title,category,actual,remaining,note,task_id'],
            'expenses.*.id' => ['required', 'uuid', 'distinct'], 'expenses.*.title' => ['required', 'string', 'max:160'],
            'expenses.*.category' => ['required', Rule::in(array_keys(ParticipationExecutionService::CATEGORIES))],
            'expenses.*.actual' => $money, 'expenses.*.remaining' => $money,
            'expenses.*.note' => ['present', 'nullable', 'string', 'max:2000'],
            'expenses.*.task_id' => ['present', 'nullable', 'integer', 'min:1'],
        ], [
            'regex' => 'Введите сумму от 0 до 9 999 999 999,99 ₽, не более двух знаков после запятой.',
            'string' => 'Введите сумму или текст в указанном формате.',
            'required' => 'Заполните обязательные поля.',
            'present' => 'Переданы не все поля расчёта. Обновите страницу.',
            'accepted' => 'Подтвердите сохранение исходного плана.',
            'distinct' => 'Одна статья расходов передана несколько раз.',
            'uuid' => 'Неверный идентификатор статьи. Обновите страницу.',
            'max' => 'Превышен допустимый размер поля или число статей.',
        ]);
        $result = DB::transaction(function () use ($user, $team, $scope, $work, $tender, $data, $start): array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($this->execution->enabled($locked), 403);
            $scope->lock($locked, $team);
            $work->authorize($locked, $tender, $team);
            $p = $work->participations($locked, $team)->where('tender_id', $tender->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->stage === ParticipationStage::Won, 409, 'Редактирование исполнения доступно на этапе «Победа».');
            $current = $this->execution->current($p);
            if ($start) {
                // A repeated start never overwrites the immutable baseline or duplicates the plan.
                if ($current) {
                    return $this->execution->present($current, $p);
                }
                abort_unless($p->economics_version === (int) $data['economics_version'], 409, 'Исходная экономика изменилась. Обновите страницу.');
                $current = $this->execution->start($p, $locked);
            } else {
                abort_unless($current !== null, 409, 'Сначала зафиксируйте исходный план.');
                abort_unless($current->version === (int) $data['version'], 409, 'Исполнение изменено в другой вкладке. Обновите страницу; ваши правки ещё не сохранены.');
                foreach (array_keys(ParticipationExecutionService::CATEGORIES) as $category) {
                    abort_unless(in_array($category, array_column($data['expenses'], 'category'), true), 422, 'Оставьте хотя бы одну статью в каждой категории исходного плана.');
                }
                $oldTasks = collect($current->expenses)->pluck('task_id', 'id');
                $expenses = [];
                foreach ($data['expenses'] as $row) {
                    // Existing links to deleted tasks can be retained, but no foreign task can be assigned.
                    abort_if($row['task_id'] !== null && $oldTasks->get($row['id']) !== $row['task_id']
                        && ! $p->items()->whereKey($row['task_id'])->exists(), 422, 'Задача должна относиться к этой заявке.');
                    $expenses[] = [...$row, 'actual' => $this->execution->amount($this->execution->minor($row['actual'])),
                        'remaining' => $this->execution->amount($this->execution->minor($row['remaining']))];
                }
                $current->fill(['expenses' => $expenses, 'received_revenue' => $this->execution->amount($this->execution->minor($data['received_revenue'])),
                    'remaining_revenue' => $this->execution->amount($this->execution->minor($data['remaining_revenue'])),
                    'costs_confirmed' => $data['costs_confirmed'], 'version' => $current->version + 1, 'updated_by' => $locked->id])->save();
            }
            if ($team) {
                app(TeamActivityService::class)->record($team, $locked, $start ? 'execution_started' : 'execution_updated',
                    ['participation_id' => $p->id, 'tender_id' => $tender->id, 'version' => $current->version]);
            }

            return $this->execution->present($current, $p);
        });

        return response()->json(['execution' => $result]);
    }
}
