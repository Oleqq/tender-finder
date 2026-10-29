<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WinnerOpportunity;
use App\Services\PriceCatalog\CatalogService;
use App\Services\WinnerOpportunityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class WinnerOpportunityController extends Controller
{
    public function __construct(private readonly WinnerOpportunityService $opportunities) {}

    public function index(Request $request): Response
    {
        $this->personal($request);
        $user = $request->user();
        $enabled = $this->opportunities->enabled($user);
        $archived = $request->query('archived') === '1';

        return Inertia::render('WinnerOpportunities', ['enabled' => $enabled, 'archived' => $archived,
            'opportunities' => $enabled ? $this->opportunities->owned($user)
                ->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
                ->orderByDesc('id')->paginate(10)->withQueryString()->through(fn (WinnerOpportunity $row): array => [
                    'id' => $row->id, 'title' => $row->evidence['title'], 'winner_name' => $row->evidence['winner_name'],
                    'checked_on' => $row->evidence['checked_on'], 'outcome' => $row->outcome,
                ]) : null]);
    }

    public function show(Request $request, int $opportunity): Response
    {
        $user = $this->user($request);
        $row = $this->opportunities->owned($user)->findOrFail($opportunity);
        $events = DB::table('winner_opportunity_events')->where('opportunity_id', $row->id)->orderByDesc('id')
            ->paginate(10)->through(fn (\stdClass $event): array => ['id' => $event->id, 'action' => $event->action,
                'created_at' => Carbon::parse($event->created_at, 'UTC')->toAtomString(), 'snapshot' => json_decode($event->snapshot, true, 512, JSON_THROW_ON_ERROR)]);

        return Inertia::render('WinnerOpportunity', ['opportunity' => $this->opportunities->present($row, $user),
            'catalog' => app(CatalogService::class)->present($user), 'candidates' => $this->opportunities->candidates($row, $user), 'events' => $events]);
    }

    public function saveEvidence(Request $request, ?int $opportunity = null): JsonResponse
    {
        $this->user($request);
        $today = now('Europe/Moscow')->toDateString();
        $data = $this->validateInput($request, [
            'version' => ['required', 'integer', 'min:0'], 'confirmed' => ['required', 'accepted'],
            'creation_key' => [$opportunity === null ? 'required' : 'nullable', 'uuid'],
            'title' => ['required', 'string', 'max:300'], 'winner_name' => ['required', 'string', 'max:300'],
            'source_url' => ['required', 'url:https', 'max:2000'], 'source_reference' => ['required', 'string', 'max:500'],
            'source_quote' => ['required', 'string', 'max:6000'],
            'result_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'checked_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:result_on', 'before_or_equal:'.$today],
            'scope' => ['required', 'string', 'max:3000'],
        ], ['confirmed.accepted' => 'Подтвердите сверку победителя и предмета закупки с оригиналом.']);
        $evidence = array_intersect_key($data, array_flip(['title', 'winner_name', 'source_url', 'source_reference', 'source_quote', 'result_on', 'checked_on', 'scope']));
        $id = DB::transaction(function () use ($request, $opportunity, $data, $evidence): int {
            $user = $this->lock($request);
            if ($opportunity === null) {
                $hash = $this->opportunities->fingerprint($evidence);
                $existing = $this->opportunities->owned($user)->where('creation_key', $data['creation_key'])->first();
                if ($existing) {
                    abort_unless($existing->creation_hash === $hash, 409, 'Эта форма уже использована. Обновите страницу.');

                    return $existing->id;
                }
                abort_unless((int) $data['version'] === 0, 409);
                abort_if($this->opportunities->owned($user)->whereNull('archived_at')->count() >= 100, 422, 'До 100 активных возможностей. Перенесите завершённые в архив.');
                $row = WinnerOpportunity::query()->create(['user_id' => $user->id, 'creation_key' => $data['creation_key'],
                    'creation_hash' => $hash, 'evidence' => $evidence, 'version' => 1]);
            } else {
                $row = $this->row($user, $opportunity, $data['version']);
                $changed = $this->opportunities->fingerprint($row->evidence) !== $this->opportunities->fingerprint($evidence);
                $row->fill(['evidence' => $evidence, 'version' => $row->version + 1,
                    'outcome' => $changed ? 'new' : $row->outcome, 'outcome_note' => $changed ? null : $row->outcome_note])->save();
            }
            $this->opportunities->event($row, 'evidence', ['evidence' => $evidence]);

            return $row->id;
        });

        return response()->json(['id' => $id]);
    }

    public function compose(Request $request, int $opportunity): JsonResponse
    {
        $this->user($request);
        $data = $this->validateInput($request, [
            'version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted'],
            'catalog_version' => ['required', 'integer', 'min:0'], 'item_ids' => ['required', 'array', 'min:1', 'max:10'],
            'item_ids.*' => ['required', 'integer', 'min:1', 'distinct'], 'rationale' => ['required', 'string', 'max:2000'],
            'company' => ['required', 'string', 'max:500'], 'contacts' => ['required', 'string', 'max:1000'],
        ]);

        return $this->mutate($request, $opportunity, $data['version'], function (WinnerOpportunity $row, User $user) use ($data): void {
            $this->opportunities->compose($row, $user, array_map('intval', $data['item_ids']), $data);
        });
    }

    public function saveDraft(Request $request, int $opportunity): JsonResponse
    {
        $this->user($request);
        $data = $this->validateInput($request, ['version' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:20000'], 'confirmed' => ['required', 'accepted']]);

        return $this->mutate($request, $opportunity, $data['version'], function (WinnerOpportunity $row, User $user) use ($data): void {
            abort_if($this->opportunities->stale($row, $user), 409, 'Источники или прайс изменились. Соберите новый черновик.');
            $row->body = $data['body'];
        });
    }

    public function outcome(Request $request, int $opportunity): JsonResponse
    {
        $this->user($request);
        $data = $this->validateInput($request, ['version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted'],
            'outcome' => ['required', Rule::in(['contacted', 'reply', 'requested', 'declined', 'unsuitable'])],
            'note' => ['required', 'string', 'max:4000']]);

        return $this->mutate($request, $opportunity, $data['version'], function (WinnerOpportunity $row, User $user) use ($data): void {
            if ($data['outcome'] === 'contacted') {
                abort_if($row->body === null || $this->opportunities->stale($row, $user), 409, 'Нужен сохранённый актуальный черновик обращения.');
            } elseif (in_array($data['outcome'], ['reply', 'requested', 'declined'], true)) {
                $sent = DB::table('winner_opportunity_events')->where('opportunity_id', $row->id)->where('action', 'contacted')->orderByDesc('id')->first();
                $snapshot = $sent ? json_decode($sent->snapshot, true, 512, JSON_THROW_ON_ERROR) : null;
                abort_unless($snapshot !== null && $this->opportunities->fingerprint($snapshot['evidence']) === $this->opportunities->fingerprint($row->evidence), 422, 'Сначала зафиксируйте самостоятельное обращение по текущему основанию. Прежние ответы остаются в истории.');
            }
            $row->outcome = $data['outcome'];
            $row->outcome_note = $data['note'];
        }, $data['outcome']);
    }

    public function archive(Request $request, int $opportunity): JsonResponse
    {
        $this->user($request);
        $data = $this->validateInput($request, ['version' => ['required', 'integer', 'min:1'], 'archived' => ['required', 'boolean'], 'confirmed' => ['required', 'accepted']]);

        return $this->mutate($request, $opportunity, $data['version'], function (WinnerOpportunity $row, User $user) use ($data): void {
            if (! $data['archived'] && $row->archived_at !== null) {
                abort_if($this->opportunities->owned($user)->whereNull('archived_at')->count() >= 100, 422, 'До 100 активных возможностей. Освободите место в списке.');
            }
            $row->archived_at = $data['archived'] ? now() : null;
        }, 'archive', true);
    }

    public function export(Request $request, int $opportunity): HttpResponse
    {
        $user = $this->user($request);
        $row = $this->opportunities->owned($user)->findOrFail($opportunity);
        abort_unless($row->version === (int) $request->query('version'), 409, 'Обновите страницу перед выгрузкой.');
        abort_if($row->body === null || $this->opportunities->stale($row, $user), 409, 'Соберите актуальный черновик.');

        return response("\xEF\xBB\xBF".$row->body, 200, ['Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="TenderFinder-winner-'.$row->id.'.txt"', 'Cache-Control' => 'private, no-store']);
    }

    /** @param callable(WinnerOpportunity, User): void $change */
    private function mutate(Request $request, int $id, int $version, callable $change, string $event = 'draft', bool $allowArchive = false): JsonResponse
    {
        return DB::transaction(function () use ($request, $id, $version, $change, $event, $allowArchive): JsonResponse {
            $user = $this->lock($request);
            $row = $this->row($user, $id, $version, $allowArchive);
            $change($row, $user);
            $row->version++;
            $row->save();
            if ($event !== 'draft') {
                $this->opportunities->event($row, $event, ['evidence' => $row->evidence,
                    'body' => $row->body, 'draft_source' => $row->draft_source,
                    'outcome' => $row->outcome, 'note' => $row->outcome_note, 'archived' => $row->archived_at !== null]);
            }

            return response()->json(['opportunity' => $this->opportunities->present($row, $user)]);
        });
    }

    private function row(User $user, int $id, int $version, bool $allowArchive = false): WinnerOpportunity
    {
        $row = $this->opportunities->owned($user)->lockForUpdate()->findOrFail($id);
        abort_unless($row->version === $version, 409, 'Запись изменилась в другой вкладке. Введённый текст остался в форме; обновите страницу после его копирования.');
        abort_if(! $allowArchive && $row->archived_at !== null, 409, 'Сначала верните запись из архива.');

        return $row;
    }

    private function lock(Request $request): User
    {
        $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
        abort_unless($this->opportunities->enabled($user), 403);

        return $user;
    }

    /** @param array<string, mixed> $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    private function validateInput(Request $request, array $rules, array $messages = []): array
    {
        return $request->validate($rules, [...[
            'required' => 'Заполните поле «:attribute».', 'accepted' => 'Подтвердите проверку данных.',
            'url' => 'Укажите корректную HTTPS-ссылку на итоги.', 'date_format' => 'Укажите корректную дату в поле «:attribute».',
            'before_or_equal' => 'Дата в поле «:attribute» не может быть позже :date.',
            'after_or_equal' => 'Дата проверки не может быть раньше даты итогов.',
            'max.string' => 'Поле «:attribute» допускает до :max символов.',
            'max.array' => 'Можно выбрать не более :max позиций.', 'min.array' => 'Выберите хотя бы одну позицию.',
            'integer' => 'Обновите страницу: неверный номер или версия записи.',
            'uuid' => 'Обновите страницу перед созданием записи.', 'distinct' => 'Позиции не должны повторяться.',
            'in' => 'Выберите результат из списка.',
        ], ...$messages], [
            'title' => 'Закупка и номер извещения', 'winner_name' => 'Победитель', 'source_url' => 'Ссылка на итоги',
            'source_reference' => 'Документ и место цитаты', 'source_quote' => 'Цитата', 'result_on' => 'Дата итогов',
            'checked_on' => 'Дата вашей проверки', 'scope' => 'Интересующая часть закупки', 'rationale' => 'Основание соответствия',
            'company' => 'Компания или имя', 'contacts' => 'Контакты', 'note' => 'Описание результата', 'body' => 'Текст обращения',
            'item_ids' => 'Позиции прайса',
        ]);
    }

    private function personal(Request $request): void
    {
        abort_if($request->filled('team_id'), 422, 'Подбор поставок победителю и прайс пока личные. Откройте раздел из профиля без выбора команды.');
    }

    private function user(Request $request): User
    {
        $this->personal($request);
        $user = $request->user();
        abort_unless($user instanceof User && $this->opportunities->enabled($user), 403, 'Для поставок победителю нужен действующий Ultra.');

        return $user;
    }
}
