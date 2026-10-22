<?php

namespace App\Services;

use App\Enums\AccessState;
use App\Enums\UserRole;
use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class TenderAnalyticsService
{
    public function __construct(private readonly AccessService $access, private readonly TenderWorkService $work) {}

    public function tierFor(User $user): string
    {
        $access = $this->access->snapshotFor($user);
        if (! in_array($access->state, [AccessState::Active, AccessState::Trialing], true)) {
            return 'basic';
        }
        if ($access->mode !== 'developer' && ($user->role === UserRole::SuperAdmin || in_array($access->planCode, ['local_mvp_full_access', 'local_mvp_operator'], true))) {
            return 'ultra';
        }

        return in_array($access->planCode, ['pro', 'ultra'], true) ? $access->planCode : 'basic';
    }

    /** Call only after the controller has authorized access to the tender and team.
     * @return array<string, mixed>
     */
    public function forTender(User $user, Tender $tender, ?Team $team = null): array
    {
        $tier = $this->tierFor($user);
        $result = ['tier' => $tier, 'generated_at' => now()->toAtomString()];
        if ($tier === 'basic') {
            return $result;
        }
        $result['pro'] = $this->overview($tender);
        if ($tier === 'ultra') {
            $result['ultra'] = [
                'comparison' => $this->comparison($user, $tender, $team),
                'changes' => $this->changes($tender),
                'readiness' => $this->readiness($user, $tender, $team),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function overview(Tender $tender): array
    {
        $summary = TenderSummary::forTender($tender);
        $deadline = $tender->deadline_at;
        $published = $tender->published_at;
        $contradictory = $deadline !== null && $published !== null && $deadline->lessThanOrEqualTo($published);
        $futurePublication = $published?->isFuture() ?? false;
        $hours = $deadline === null || $contradictory || $futurePublication ? null : now()->diffInHours($deadline, false);
        $window = $deadline !== null && $published !== null && ! $contradictory && ! $futurePublication
            ? $published->diffInHours($deadline, false) : null;
        $priority = match (true) {
            $contradictory || $futurePublication => 'conflict',
            $hours === null => 'unknown',
            $hours <= 0 => 'elapsed',
            $hours <= 24 => 'urgent',
            $hours <= 72 => 'soon',
            default => 'normal',
        };
        $checks = [
            ['code' => 'budget', 'label' => 'Бюджет', 'present' => $tender->budget_amount !== null],
            ['code' => 'publication', 'label' => 'Дата публикации', 'present' => $published !== null],
            ['code' => 'deadline', 'label' => 'Срок подачи', 'present' => $deadline !== null],
            ['code' => 'customer', 'label' => 'Заказчик', 'present' => TenderFacts::customer($tender) !== null],
            ['code' => 'place', 'label' => 'Место / регион', 'present' => $summary['delivery_place'] !== null || filled($tender->region)],
            ['code' => 'number', 'label' => 'Номер закупки', 'present' => filled($tender->reg_number)],
            ['code' => 'application_security', 'label' => 'Обеспечение заявки', 'present' => $summary['application_security'] !== null],
            ['code' => 'contract_security', 'label' => 'Обеспечение контракта', 'present' => $summary['contract_security'] !== null],
        ];
        $known = count(array_filter($checks, fn (array $check): bool => $check['present']));
        $actions = [];
        $signals = [];
        if ($priority === 'conflict') {
            $signals[] = ['tone' => 'warning', 'title' => 'Даты требуют проверки', 'detail' => 'Публикация находится в будущем или срок подачи не позже публикации. Расчёт окна подготовки недоступен.'];
            $actions[] = 'Сверьте дату публикации и точное время окончания подачи на площадке.';
        } elseif ($priority === 'elapsed') {
            $signals[] = ['tone' => 'warning', 'title' => 'Указанный срок прошёл', 'detail' => 'Это расчёт по сохранённой дате, а не подтверждение закрытия процедуры.'];
            $actions[] = 'Проверьте, продлён ли приём заявок, прежде чем готовить документы.';
        } elseif (in_array($priority, ['urgent', 'soon'], true)) {
            $signals[] = ['tone' => 'warning', 'title' => $priority === 'urgent' ? 'До подачи меньше суток' : 'До подачи не больше трёх суток', 'detail' => 'Оцените время на документы, согласование и загрузку заявки.'];
            $actions[] = 'Сначала подтвердите готовность документов и доступ к электронной подписи.';
        } elseif ($priority === 'unknown') {
            $actions[] = 'Найдите точный срок подачи в извещении или документации.';
        }
        if ($tender->budget_amount === null) {
            $signals[] = ['tone' => 'neutral', 'title' => 'Нет начальной цены', 'detail' => 'Бюджет не передан источником. Сравнение цены и денежные сценарии ограничены.'];
            $actions[] = 'Уточните начальную цену, валюту и включение НДС в документации.';
        } else {
            $actions[] = 'Сверьте состав цены, НДС, условия оплаты и свою себестоимость.';
        }
        if ($summary['application_security'] === null || $summary['contract_security'] === null) {
            $actions[] = 'Проверьте, требуются ли обеспечения заявки и исполнения: отсутствие поля не означает отсутствие требования.';
        }
        if (TenderFacts::customer($tender) === null) {
            $actions[] = 'Уточните заказчика и контакт для вопросов по закупке.';
        }
        $observed = $tender->details_fetched_at ?? $tender->created_at;
        $age = max(0, now()->diffInHours($observed, true));
        if ($age > 48) {
            $signals[] = ['tone' => 'neutral', 'title' => 'Сверьте сохранённый снимок', 'detail' => 'С момента получения этих данных прошло больше двух суток. Это не свидетельствует об отсутствии изменений на площадке.'];
        }
        $actions[] = 'Перед отправкой сопоставьте заявку с последней редакцией извещения.';

        return [
            'priority' => $priority,
            'window_hours' => $window === null ? null : round($window, 1),
            'buffer_at' => $hours !== null && $hours > 24 ? $deadline->copy()->subDay()->toAtomString() : null,
            'checks' => $checks,
            'known_fields' => $known,
            'total_fields' => count($checks),
            'completeness_percent' => (int) round($known / count($checks) * 100),
            'signals' => $signals,
            'actions' => $actions,
        ];
    }

    /** Same-monitoring, same-currency, title-related samples from the authorized workspace.
     * @return array<string, mixed>
     */
    private function comparison(User $user, Tender $tender, ?Team $team): array
    {
        $queryIds = SearchQuery::query()->where('status', '!=', 'deleted')
            ->whereHas('matches', fn (Builder $q) => $q->where('tender_id', $tender->id));
        if ($team) {
            $queryIds->whereIn('id', DB::table('team_search_queries')->where('team_id', $team->id)->select('search_query_id'));
        } else {
            $queryIds->where('user_id', $user->id);
        }
        $candidates = Tender::query()->where('id', '!=', $tender->id)
            ->where('currency', $tender->currency)->where('budget_amount', '>', 0)
            ->whereBetween('published_at', [now()->subDays(90), now()])
            ->whereHas('matches', fn (Builder $q) => $q->whereIn('search_query_id', $queryIds->select('id')))
            ->orderByDesc('published_at')->orderByDesc('id')->limit(301)->get();
        $result = [
            'window_days' => 90, 'candidate_limit' => 300, 'truncated' => $candidates->count() > 300,
            'sample_count' => 0, 'minimum_sample' => 3, 'currency' => $tender->currency,
            'median' => null, 'lower_quartile' => null, 'upper_quartile' => null,
            'difference_percent' => null,
            'scope' => $team ? 'shared_monitorings' : 'personal_monitorings',
        ];
        $tokens = $this->titleTokens($tender->title);
        if ($tokens === []) {
            return $result;
        }
        $seen = [$this->identity($tender) => true];
        $prices = [];
        foreach ($candidates->take(300) as $candidate) {
            $identity = $this->identity($candidate);
            if (isset($seen[$identity]) || count(array_intersect($tokens, $this->titleTokens($candidate->title))) < min(2, count($tokens))) {
                continue;
            }
            $seen[$identity] = true;
            $prices[] = (float) $candidate->budget_amount;
        }
        sort($prices, SORT_NUMERIC);
        $result['sample_count'] = count($prices);
        if (count($prices) < 3) {
            return $result;
        }
        foreach (['median' => .5, 'lower_quartile' => .25, 'upper_quartile' => .75] as $key => $fraction) {
            $result[$key] = number_format($this->quantile($prices, $fraction), 2, '.', '');
        }
        $median = (float) $result['median'];
        $result['difference_percent'] = $tender->budget_amount !== null && $median > 0
            ? round(((float) $tender->budget_amount / $median - 1) * 100, 1) : null;

        return $result;
    }

    /** @return list<string> */
    private function titleTokens(string $title): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title)) ?: [];

        return array_values(array_unique(array_filter($parts, fn (string $word): bool => mb_strlen($word) >= 4
            && ! in_array($word, ['поставка', 'оказание', 'выполнение', 'работ', 'работы', 'услуг', 'услуги', 'закупка', 'право', 'договора', 'заключение', 'нужд', 'компании'], true))));
    }

    private function identity(Tender $tender): string
    {
        return filled($tender->reg_number) ? 'number:'.mb_strtolower(trim($tender->reg_number)) : 'url:'.$tender->canonical_url;
    }

    /** @param list<float> $values */
    private function quantile(array $values, float $fraction): float
    {
        $position = (count($values) - 1) * $fraction;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return $values[$lower] + ($values[$upper] - $values[$lower]) * ($position - $lower);
    }

    /** @return array<string, mixed> */
    private function changes(Tender $tender): array
    {
        $rows = TenderChange::query()->where('tender_id', $tender->id)->latest('id')->limit(11)->get();
        $items = [];
        foreach ($rows->take(10) as $row) {
            $fields = [];
            /** @var mixed $rawChanges */
            $rawChanges = $row->getAttribute('changes');
            foreach (is_array($rawChanges) ? $rawChanges : [] as $field => $values) {
                if (! in_array($field, ['budget_amount', 'currency', 'deadline_at', 'stage'], true) || ! is_array($values)) {
                    continue;
                }
                $before = $values['before'] ?? null;
                $after = $values['after'] ?? null;
                if (($before !== null && ! is_string($before)) || ($after !== null && ! is_string($after))) {
                    continue;
                }
                $fields[] = ['field' => $field, 'before' => $before, 'after' => $after];
            }
            if ($fields !== []) {
                $items[] = ['at' => $row->created_at?->toAtomString(), 'fields' => $fields];
            }
        }

        return ['items' => $items, 'has_more' => $rows->count() > 10];
    }

    /** @return array<string, mixed> */
    private function readiness(User $user, Tender $tender, ?Team $team): array
    {
        $participation = $this->work->participations($user, $team)->where('tender_id', $tender->id)->first();
        $items = $participation?->items()->get();
        $total = $items?->count() ?? 0;
        $completed = $items?->whereNotNull('completed_at')->count() ?? 0;
        $today = now(app(TenderCalendarService::class)->timezone($user))->toDateString();
        $overdue = $items?->filter(fn ($item): bool => $item->completed_at === null && $item->due_on !== null && $item->due_on->format('Y-m-d') < $today)->count() ?? 0;

        return [
            'started' => $participation !== null, 'stage' => $participation?->stage->value,
            'tasks_total' => $total, 'tasks_completed' => $completed, 'tasks_overdue' => $overdue,
            'completion_percent' => $total > 0 ? (int) round($completed / $total * 100) : null,
            'scope' => $team ? 'team' : 'personal',
        ];
    }
}
