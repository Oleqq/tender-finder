<?php

namespace App\Http\Controllers;

use App\Models\RepeatTenderCandidate;
use App\Models\RepeatTenderWatch;
use App\Models\User;
use App\Services\RepeatTenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class RepeatTenderController extends Controller
{
    public function __construct(private readonly RepeatTenderService $repeats) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $this->personal($request);
        $user = $request->user();
        $enabled = $this->repeats->enabled($user);
        $archived = $request->query('archived') === '1';
        $from = null;
        if ($enabled && $request->filled('from')) {
            $id = $request->validate(['from' => ['required', 'integer', 'min:1']])['from'];
            $from = $this->repeats->snapshot($this->repeats->tenders($user)->findOrFail($id));
            $existing = $this->repeats->watches($user)->where('tender_id', $id)->first();
            if ($existing) {
                return redirect('/repeat-tenders/'.$existing->id);
            }
        }

        return Inertia::render('RepeatTenders', ['enabled' => $enabled, 'archived' => $archived, 'onlyUnread' => $request->query('unread') === '1', 'from' => $from,
            'unread' => $this->repeats->unread($user),
            'watches' => $enabled ? $this->repeats->watches($user)
                ->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
                ->when($request->query('unread') === '1', fn ($q) => $q->whereIn('id', $this->repeats->alerts($user)->select('watch_id')))
                ->orderByDesc('id')->paginate(10)->withQueryString()->through(fn (RepeatTenderWatch $watch): array => $this->repeats->presentWatch($watch, $user)) : null]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $this->validateInput($request, ['search' => ['required', 'string', 'min:2', 'max:100']]);
        $query = $this->repeats->tenders($user);
        $exact = (clone $query)->where('reg_number', $data['search'])->limit(20)->get();
        $recent = (clone $query)->orderByDesc('id')->limit(501)->get();
        $matches = $recent->take(500)->filter(fn ($t): bool => mb_stripos($t->title, $data['search']) !== false);
        $rows = $exact->concat($matches)->unique('id')->values();

        return response()->json(['tenders' => $rows->take(20)->map(fn ($t): array => $this->repeats->snapshot($t))->values(),
            'limited' => $recent->count() > 500 || $rows->count() > 20]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->user($request);
        $data = $this->validateInput($request, ['tender_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:3000'], 'confirmed' => ['required', 'accepted']]);
        $id = DB::transaction(function () use ($request, $data): int {
            $user = $this->lock($request);
            $tender = $this->repeats->tenders($user)->lockForUpdate()->findOrFail($data['tender_id']);
            $existing = $this->repeats->watches($user)->where('tender_id', $tender->id)->first();
            if ($existing) {
                abort_unless($existing->reason === $data['reason'], 409, 'Для этой закупки уже есть наблюдение. Откройте его из списка.');

                return $existing->id;
            }
            abort_if($this->repeats->watches($user)->whereNull('archived_at')->count() >= 100, 422, 'Допускается до 100 активных наблюдений. Перенесите завершённые в архив.');
            $watch = RepeatTenderWatch::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'reason' => $data['reason'],
                'original' => $this->repeats->snapshot($tender), 'version' => 1, 'scan_after' => now()]);

            return $watch->id;
        });

        return response()->json(['id' => $id]);
    }

    public function show(Request $request, int $watch): Response
    {
        $user = $this->user($request);
        $row = $this->repeats->watches($user)->findOrFail($watch);

        return Inertia::render('RepeatTenderWatch', ['watch' => $this->repeats->presentWatch($row, $user),
            'candidates' => RepeatTenderCandidate::query()->where('watch_id', $row->id)->orderByRaw("CASE status WHEN 'confirmed' THEN 0 WHEN 'possible' THEN 1 ELSE 2 END")
                ->orderByDesc('id')->paginate(10)->through(fn (RepeatTenderCandidate $c): array => $this->repeats->presentCandidate($c, $user))]);
    }

    public function update(Request $request, int $watch): JsonResponse
    {
        $this->user($request);
        $data = $this->validateInput($request, ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:3000'], 'confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $watch, $data): void {
            $user = $this->lock($request);
            $row = $this->row($user, $watch, $data['version']);
            $this->editable($row, $user);
            $row->update(['reason' => $data['reason'], 'version' => $row->version + 1]);
        });

        return response()->json(['saved' => true]);
    }

    public function archive(Request $request, int $watch): JsonResponse
    {
        $this->user($request);
        $data = $this->validateInput($request, ['version' => ['required', 'integer', 'min:1'], 'archived' => ['required', 'boolean'], 'confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $watch, $data): void {
            $user = $this->lock($request);
            $row = $this->row($user, $watch, $data['version']);
            if (! $data['archived']) {
                abort_unless($this->repeats->available($user, $row->tender_id), 409, 'Исходная закупка недоступна. Сначала восстановите доступ к ней.');
                abort_if($row->archived_at !== null && $this->repeats->watches($user)->whereNull('archived_at')->count() >= 100, 422, 'Сначала освободите место в активном списке.');
            }
            $row->update(['archived_at' => $data['archived'] ? now() : null, 'scan_after' => now(), 'version' => $row->version + 1]);
        });

        return response()->json(['saved' => true]);
    }

    public function scan(Request $request, int $watch): JsonResponse
    {
        $user = $this->user($request);
        $row = $this->repeats->watches($user)->findOrFail($watch);
        $this->editable($row, $user);
        $count = $this->repeats->scanUser($user->id, $row->id);

        return response()->json(['found' => $count]);
    }

    public function addCandidate(Request $request, int $watch): JsonResponse
    {
        $this->user($request);
        $data = $this->validateInput($request, ['tender_id' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $watch, $data): void {
            $user = $this->lock($request);
            $row = $this->repeats->watches($user)->lockForUpdate()->findOrFail($watch);
            $this->editable($row, $user);
            $tender = $this->repeats->tenders($user)->lockForUpdate()->findOrFail($data['tender_id']);
            abort_unless($this->repeats->distinctNotice($row->original, $tender), 422, 'Это та же закупка или копия с тем же номером/ссылкой. Выберите другое извещение.');
            $existing = RepeatTenderCandidate::query()->where('watch_id', $row->id)->where('tender_id', $tender->id)->first();
            if ($existing) {
                return;
            }
            abort_if(RepeatTenderCandidate::query()->where('watch_id', $row->id)->where('status', 'possible')->count() >= 100, 422, 'До 100 непроверенных совпадений. Сначала разберите существующие.');
            RepeatTenderCandidate::query()->create(['watch_id' => $row->id, 'tender_id' => $tender->id,
                'snapshot' => $this->repeats->snapshot($tender), 'basis' => ['kind' => 'manual', 'terms' => []], 'version' => 1]);
        });

        return response()->json(['saved' => true]);
    }

    public function decision(Request $request, int $watch, int $candidate, string $decision): JsonResponse
    {
        $this->user($request);
        $rules = ['version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']];
        if ($decision === 'confirm') {
            $rules += ['watch_version' => ['required', 'integer', 'min:1'], 'latest_hash' => ['required', 'string', 'size:64'], 'source_url' => ['required', 'url:https', 'max:2000'],
                'source_reference' => ['required', 'string', 'max:500'], 'quote' => ['required', 'string', 'max:6000'],
                'checked_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Moscow')->toDateString()],
                'previous_outcome' => ['required', 'string', 'max:1000'], 'note' => ['required', 'string', 'max:3000']];
        } elseif ($decision === 'dismiss') {
            $rules += ['note' => ['required', 'string', 'max:3000']];
        }
        $data = $this->validateInput($request, $rules);
        DB::transaction(function () use ($request, $watch, $candidate, $decision, $data): void {
            $user = $this->lock($request);
            $row = $this->repeats->watches($user)->lockForUpdate()->findOrFail($watch);
            $this->editable($row, $user);
            $c = RepeatTenderCandidate::query()->where('watch_id', $row->id)->lockForUpdate()->findOrFail($candidate);
            abort_unless($c->version === (int) $data['version'], 409, 'Совпадение изменилось. Обновите страницу; поля остались в форме.');
            if ($decision === 'confirm') {
                abort_unless($row->version === (int) $data['watch_version'], 409, 'Причина или состояние наблюдения изменились. Обновите страницу перед подтверждением.');
                abort_unless($c->status === 'possible', 409, 'Подтверждение уже зафиксировано или совпадение отклонено.');
                $tender = $this->repeats->tenders($user)->lockForUpdate()->findOrFail($c->tender_id);
                $snapshot = $this->repeats->snapshot($tender);
                abort_unless($this->repeats->fingerprint($snapshot) === $data['latest_hash'], 409, 'Карточка изменилась. Обновите страницу и сверьте источник заново.');
                abort_unless($this->repeats->distinctNotice($row->original, $tender), 409, 'Извещение теперь имеет тот же номер или ссылку. Проверьте, что это разные процедуры.');
                $c->snapshot = $snapshot;
                $c->evidence = array_intersect_key($data, array_flip(['source_url', 'source_reference', 'quote', 'checked_on', 'previous_outcome', 'note']));
                $c->evidence = [...$c->evidence, 'reason_at_confirmation' => $row->reason, 'confirmed_at' => now()->toAtomString()];
                $c->status = 'confirmed';
                $c->read_at = null;
            } elseif ($decision === 'dismiss') {
                $c->status = 'dismissed';
                $c->decision_note = $data['note'];
                $c->read_at = now();
            } else {
                $c->read_at = now();
            }
            $c->version++;
            $c->save();
        });

        return response()->json(['saved' => true]);
    }

    private function row(User $user, int $id, int $version): RepeatTenderWatch
    {
        $row = $this->repeats->watches($user)->lockForUpdate()->findOrFail($id);
        abort_unless($row->version === $version, 409, 'Наблюдение изменено в другой вкладке. Обновите страницу.');

        return $row;
    }

    private function editable(RepeatTenderWatch $watch, User $user): void
    {
        abort_if($watch->archived_at !== null, 409, 'Сначала верните наблюдение из архива.');
        abort_unless($this->repeats->available($user, $watch->tender_id), 409, 'Исходная закупка удалена или недоступна. История сохранена; наблюдение можно архивировать.');
    }

    private function personal(Request $request): void
    {
        abort_if($request->filled('team_id'), 422, 'Наблюдения за повторными закупками пока личные. Откройте раздел из профиля.');
    }

    private function user(Request $request): User
    {
        $this->personal($request);
        $user = $request->user();
        abort_unless($user instanceof User && $this->repeats->enabled($user), 403, 'Для повторных закупок нужен действующий Ultra.');

        return $user;
    }

    private function lock(Request $request): User
    {
        $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
        abort_unless($this->repeats->enabled($user), 403);

        return $user;
    }

    /** @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function validateInput(Request $request, array $rules): array
    {
        return $request->validate($rules, ['required' => 'Заполните поле «:attribute».', 'accepted' => 'Подтвердите проверку данных.',
            'url' => 'Нужна корректная HTTPS-ссылка.', 'max.string' => 'В поле «:attribute» допускается до :max символов.',
            'min.string' => 'Введите не менее :min символов.', 'date_format' => 'Укажите корректную дату проверки.',
            'before_or_equal' => 'Дата проверки не может быть в будущем.'],
            ['reason' => 'Причина отказа', 'search' => 'Название или номер', 'source_url' => 'Источник связи',
                'source_reference' => 'Документ и место цитаты', 'quote' => 'Цитата', 'checked_on' => 'Дата проверки',
                'previous_outcome' => 'Чем завершилась прежняя закупка', 'note' => 'Основание решения']);
    }
}
