<?php

namespace App\Services;

use App\Models\ParticipationExecution;
use App\Models\TenderParticipation;
use App\Models\User;
use Illuminate\Support\Str;

final class ParticipationExecutionService
{
    public const CATEGORIES = ['planned_cost' => 'Закупки и работы', 'security_cost' => 'Обеспечение',
        'commission_cost' => 'Комиссии', 'other_cost' => 'Прочие расходы'];

    public function enabled(User $user): bool
    {
        return app(AccessService::class)->hasActiveAccess($user)
            && app(TenderAnalyticsService::class)->tierFor($user) === 'ultra';
    }

    public function current(TenderParticipation $participation): ?ParticipationExecution
    {
        return ParticipationExecution::query()->where('participation_id', $participation->id)->first();
    }

    public function start(TenderParticipation $participation, User $user): ParticipationExecution
    {
        $baseline = ['economics_version' => $participation->economics_version, 'captured_at' => now()->toAtomString()];
        foreach (['planned_revenue', ...array_keys(self::CATEGORIES)] as $field) {
            $value = $participation->getAttribute($field);
            abort_if($value !== null && ! preg_match('/^\d{1,10}\.\d{2}$/', (string) $value), 422,
                'Сумма исходного расчёта превышает лимит исполнения 9 999 999 999,99 ₽. Уточните экономику заявки.');
            $baseline[$field] = $value;
        }
        $rows = [];
        foreach (self::CATEGORIES as $category => $label) {
            // Aggregate actuals from the bid are deliberately not copied into detailed expenses.
            $rows[] = ['id' => (string) Str::uuid(), 'title' => $label, 'category' => $category,
                'actual' => null, 'remaining' => $baseline[$category], 'note' => null, 'task_id' => null];
        }

        return ParticipationExecution::query()->create(['participation_id' => $participation->id, 'created_by' => $user->id,
            'updated_by' => $user->id, 'baseline' => $baseline, 'expenses' => $rows, 'version' => 1]);
    }

    public function minor(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $parts = explode('.', str_replace(',', '.', $value));

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public function amount(?int $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $absolute = abs($value);

        return ($value < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Unknown amounts never become zero; all arithmetic uses integer kopecks.
     * @param  list<?int>  $values
     */
    private function total(array $values): ?int
    {
        return in_array(null, $values, true) ? null : array_sum($values);
    }

    /** @return array<string, mixed> */
    public function present(ParticipationExecution $execution, TenderParticipation $participation): array
    {
        $baseline = $execution->baseline;
        $tasks = $participation->items()->get()->keyBy('id');
        $expenses = [];
        $categories = [];
        foreach (self::CATEGORIES as $category => $label) {
            $rows = array_values(array_filter($execution->expenses, fn (array $row): bool => $row['category'] === $category));
            $actual = $this->total(array_map(fn (array $row): ?int => $this->minor($row['actual']), $rows));
            $remaining = $this->total(array_map(fn (array $row): ?int => $this->minor($row['remaining']), $rows));
            $forecast = $this->total([$actual, $remaining]);
            $planned = $this->minor($baseline[$category]);
            $categories[] = ['key' => $category, 'label' => $label, 'planned' => $this->amount($planned),
                'actual' => $this->amount($actual), 'remaining' => $this->amount($remaining), 'forecast' => $this->amount($forecast),
                'deviation' => $planned !== null && $forecast !== null ? $this->amount($forecast - $planned) : null,
                'expense_ids' => array_column($rows, 'id')];
        }
        foreach ($execution->expenses as $row) {
            $task = $row['task_id'] === null ? null : $tasks->get($row['task_id']);
            $expenses[] = [...$row, 'forecast' => $this->amount($this->total([$this->minor($row['actual']), $this->minor($row['remaining'])])),
                'task' => $task === null ? null : ['id' => $task->id, 'title' => $task->title, 'completed' => $task->completed_at !== null],
                'task_missing' => $row['task_id'] !== null && $task === null];
        }
        $plannedCosts = $this->total(array_map(fn (string $key): ?int => $this->minor($baseline[$key]), array_keys(self::CATEGORIES)));
        $plannedRevenue = $this->minor($baseline['planned_revenue']);
        $actual = $this->total(array_map(fn (array $row): ?int => $this->minor($row['actual']), $expenses));
        $remaining = $this->total(array_map(fn (array $row): ?int => $this->minor($row['remaining']), $expenses));
        $forecastCosts = $this->total([$actual, $remaining]);
        $received = $this->minor($execution->received_revenue);
        $revenue = $this->total([$received, $this->minor($execution->remaining_revenue)]);
        $complete = $execution->costs_confirmed && $forecastCosts !== null && $revenue !== null;
        $plannedResult = $plannedRevenue !== null && $plannedCosts !== null ? $plannedRevenue - $plannedCosts : null;
        $forecastResult = $complete ? $revenue - $forecastCosts : null;

        return ['version' => $execution->version, 'baseline' => $baseline, 'expenses' => $expenses,
            'received_revenue' => $execution->received_revenue, 'remaining_revenue' => $execution->remaining_revenue,
            'costs_confirmed' => $execution->costs_confirmed, 'categories' => $categories,
            'summary' => ['complete' => $complete, 'planned_costs' => $this->amount($plannedCosts),
                'planned_result' => $this->amount($plannedResult), 'actual_costs' => $this->amount($actual),
                'remaining_costs' => $this->amount($remaining), 'forecast_costs' => $this->amount($forecastCosts),
                'forecast_revenue' => $this->amount($revenue), 'forecast_result' => $this->amount($forecastResult),
                'result_change' => $forecastResult !== null && $plannedResult !== null ? $this->amount($forecastResult - $plannedResult) : null,
                'cash_balance' => $received !== null && $actual !== null ? $this->amount($received - $actual) : null,
                'revenue_change' => $revenue !== null && $plannedRevenue !== null ? $this->amount($revenue - $plannedRevenue) : null],
            'baseline_changed' => $baseline['economics_version'] !== $participation->economics_version,
            'updated_at' => $execution->updated_at?->toAtomString()];
    }
}
