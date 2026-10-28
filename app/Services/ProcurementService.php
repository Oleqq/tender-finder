<?php

namespace App\Services;

use App\Models\ProcurementComparison;
use App\Models\ProcurementNeed;
use App\Models\Team;
use App\Models\TenderParticipation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ProcurementService
{
    public const UNITS = ['шт', 'комплект', 'кг', 'м', 'л', 'час', 'услуга'];

    public function enabled(User $user): bool
    {
        return app(ParticipationExecutionService::class)->enabled($user);
    }

    /** @return Builder<TenderParticipation> */
    public function participations(User $user, ?Team $team): Builder
    {
        return app(TenderWorkService::class)->participations($user, $team)->where('stage', 'won')
            ->when($team === null, fn (Builder $q) => $q->whereIn('tender_id', app(TenderWorkService::class)->accessible($user)->select('tenders.id')));
    }

    /** @return Builder<ProcurementNeed> */
    public function needs(User $user, ?Team $team): Builder
    {
        return ProcurementNeed::query()->whereIn('participation_id', $this->participations($user, $team)->select('id'));
    }

    /** @return Builder<ProcurementComparison> */
    public function comparisons(User $user, ?Team $team): Builder
    {
        return ProcurementComparison::query()->when($team,
            fn (Builder $q) => $q->where('team_id', $team->id),
            fn (Builder $q) => $q->whereNull('team_id')->where('user_id', $user->id));
    }

    public function matchKey(string $title): string
    {
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', str_replace('ё', 'е', mb_strtolower($title))) ?? '');
    }

    public function quantity(string $value): int
    {
        $parts = explode('.', str_replace(',', '.', $value));

        return (int) $parts[0] * 1000 + (int) str_pad($parts[1] ?? '', 3, '0');
    }

    public function quantityText(int $value): string
    {
        return intdiv($value, 1000).'.'.str_pad((string) ($value % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** @return array{id: int, version: int, participation_id: int, tender_id: int, contract: string, title: string, specification: ?string, unit: string, quantity: string, required_on: ?string, confirmed_comparison_id: ?int} */
    public function need(ProcurementNeed $need): array
    {
        return ['id' => $need->id, 'version' => $need->version, 'participation_id' => $need->participation_id,
            'tender_id' => $need->participation->tender_id, 'contract' => $need->participation->tender->title,
            'title' => $need->title, 'specification' => $need->specification, 'unit' => $need->unit,
            'quantity' => $need->quantity, 'required_on' => $need->required_on?->toDateString(),
            'confirmed_comparison_id' => $need->confirmed_comparison_id];
    }

    /** @param list<array<string, mixed>> $needs
     * @return list<array<string, mixed>>
     */
    public function candidates(array $needs): array
    {
        $groups = [];
        foreach ($needs as $need) {
            if ($need['confirmed_comparison_id'] !== null) {
                continue;
            }
            $key = $this->matchKey($need['title']).'|'.$need['unit'];
            $groups[$key][] = $need;
        }
        $result = [];
        foreach ($groups as $rows) {
            if (count(array_unique(array_column($rows, 'participation_id'))) < 2) {
                continue;
            }
            $result[] = ['title' => $rows[0]['title'], 'unit' => $rows[0]['unit'], 'need_ids' => array_column($rows, 'id'),
                'quantity' => $this->quantityText(array_sum(array_map(fn (array $row): int => $this->quantity($row['quantity']), $rows))),
                'different_specs' => count(array_unique(array_column($rows, 'specification'))) > 1,
                'different_dates' => count(array_unique(array_column($rows, 'required_on'))) > 1];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function emptyOffer(): array
    {
        return ['supplier' => '', 'reference' => '', 'quoted_on' => null, 'valid_until' => null,
            'unit_price' => null, 'delivery_cost' => null, 'delivery_on' => null];
    }

    /** @param list<array<string, mixed>> $selection
     * @return list<string>
     */
    public function staleReasons(User $user, ?Team $team, array $selection): array
    {
        $current = $this->needs($user, $team)->whereIn('id', array_column($selection, 'id'))->get()->keyBy('id');
        $reasons = [];
        foreach ($selection as $row) {
            $need = $current->get($row['id']);
            if ($need === null || $need->version !== $row['version']) {
                $reasons[] = 'Изменилась потребность «'.$row['title'].'» или заявка больше не доступна на этапе «Победа». Создайте новое сравнение.';
            }
        }

        return $reasons;
    }

    private function minor(?string $value): ?int
    {
        return app(ParticipationExecutionService::class)->minor($value);
    }

    private function amount(?int $value): ?string
    {
        return app(ParticipationExecutionService::class)->amount($value);
    }

    /** Values are bounded so both multiplication and integer rounding fit int64. */
    private function goods(int $quantity, int $unitPrice): int
    {
        return intdiv($quantity * $unitPrice + 500, 1000);
    }

    /** Exact largest-remainder allocation, including deterministic ties.
     * @param  list<array{id: int, weight: int}>  $weights
     * @return array<int, int>
     */
    public function distribute(int $total, array $weights): array
    {
        $sum = array_sum(array_column($weights, 'weight'));
        $costs = [];
        $remainders = [];
        foreach ($weights as $row) {
            // Split the product to avoid overflowing total * weight.
            $part = ($total % $sum) * $row['weight'];
            $costs[$row['id']] = intdiv($total, $sum) * $row['weight'] + intdiv($part, $sum);
            $remainders[] = ['id' => $row['id'], 'value' => $part % $sum];
        }
        usort($remainders, fn (array $a, array $b): int => $b['value'] <=> $a['value'] ?: $a['id'] <=> $b['id']);
        $left = $total - array_sum($costs);
        for ($i = 0; $i < $left; $i++) {
            $costs[$remainders[$i]['id']]++;
        }

        return $costs;
    }

    /** @param array<string, mixed> $offer
     * @return list<string>
     */
    private function offerReasons(array $offer, string $label, ?string $requiredOn): array
    {
        $reasons = [];
        $today = now('Europe/Moscow')->toDateString();
        foreach (['supplier' => 'поставщик', 'reference' => 'источник предложения', 'quoted_on' => 'дата предложения',
            'valid_until' => 'срок действия цены', 'unit_price' => 'цена за единицу', 'delivery_cost' => 'доставка', 'delivery_on' => 'дата поставки'] as $field => $name) {
            if ($offer[$field] === null || $offer[$field] === '') {
                $reasons[] = $label.': не указаны '.$name.'.';
            }
        }
        if ($offer['quoted_on'] !== null && $offer['quoted_on'] > $today) {
            $reasons[] = $label.': дата предложения ещё не наступила.';
        }
        if ($offer['valid_until'] !== null && ($offer['valid_until'] < $today || ($offer['quoted_on'] !== null && $offer['valid_until'] < $offer['quoted_on']))) {
            $reasons[] = $label.': срок действия цены истёк или указан раньше даты предложения.';
        }
        if ($offer['delivery_on'] !== null && $requiredOn !== null && $offer['delivery_on'] > $requiredOn) {
            $reasons[] = $label.': поставка позже потребности по контракту.';
        }
        if ($offer['delivery_on'] !== null && $offer['delivery_on'] < $today) {
            $reasons[] = $label.': плановая дата поставки уже прошла.';
        }

        return $reasons;
    }

    /** @return array<string, mixed> */
    public function calculate(ProcurementComparison $comparison): array
    {
        $reasons = [];
        if (trim($comparison->conditions_note ?? '') === '') {
            $reasons[] = 'Опишите сопоставимый состав расходов: налоги, комплектацию, доставку и условия поставки.';
        }
        $weights = [];
        $separate = [];
        $offers = collect($comparison->offers)->keyBy('need_id');
        $dates = [];
        foreach ($comparison->selection as $row) {
            $weights[] = ['id' => $row['id'], 'weight' => $this->quantity($row['quantity'])];
            if ($row['required_on'] === null || trim($row['specification'] ?? '') === '') {
                $reasons[] = 'У потребности «'.$row['title'].'» должны быть указаны характеристики и срок.';
            }
            if ($row['required_on'] !== null) {
                $dates[] = $row['required_on'];
            }
            $offer = $offers->get($row['id']);
            $reasons = [...$reasons, ...$this->offerReasons($offer, $row['contract'], $row['required_on'])];
            $price = $this->minor($offer['unit_price']);
            $delivery = $this->minor($offer['delivery_cost']);
            $separate[$row['id']] = $price === null || $delivery === null ? null : $this->goods($this->quantity($row['quantity']), $price) + $delivery;
        }
        $combined = $comparison->combined_offer;
        $reasons = [...$reasons, ...$this->offerReasons($combined, 'Общая закупка', $dates === [] ? null : min($dates))];
        $totalQuantity = array_sum(array_column($weights, 'weight'));
        $price = $this->minor($combined['unit_price']);
        $delivery = $this->minor($combined['delivery_cost']);
        $combinedTotal = $price === null || $delivery === null ? null : $this->goods($totalQuantity, $price) + $delivery;
        $separateTotal = in_array(null, $separate, true) ? null : array_sum($separate);
        $complete = $reasons === [];
        $allocated = $combinedTotal === null ? [] : $this->distribute($combinedTotal, $weights);
        $allocations = [];
        foreach ($comparison->selection as $row) {
            $allocations[] = ['need_id' => $row['id'], 'participation_id' => $row['participation_id'], 'contract' => $row['contract'],
                'quantity' => $row['quantity'], 'separate_cost' => $this->amount($separate[$row['id']]),
                'combined_cost' => $this->amount($allocated[$row['id']] ?? null),
                'saving' => $complete ? $this->amount($separate[$row['id']] - $allocated[$row['id']]) : null];
        }

        return ['complete' => $complete, 'reasons' => $reasons, 'quantity' => $this->quantityText($totalQuantity), 'unit' => $comparison->selection[0]['unit'],
            'separate_total' => $this->amount($separateTotal), 'combined_total' => $this->amount($combinedTotal),
            'saving' => $complete ? $this->amount($separateTotal - $combinedTotal) : null, 'allocations' => $allocations,
            'different_specs' => count(array_unique(array_column($comparison->selection, 'specification'))) > 1,
            'different_dates' => count(array_unique(array_column($comparison->selection, 'required_on'))) > 1];
    }

    /** @return array<string, mixed> */
    public function present(ProcurementComparison $comparison, User $user, ?Team $team): array
    {
        return ['id' => $comparison->id, 'title' => $comparison->title, 'status' => $comparison->status, 'version' => $comparison->version,
            'selection' => $comparison->selection, 'offers' => $comparison->offers, 'combined_offer' => $comparison->combined_offer,
            'conditions_note' => $comparison->conditions_note, 'result' => $comparison->confirmed_result ?? $this->calculate($comparison),
            'current_reasons' => $this->calculate($comparison)['reasons'], 'stale_reasons' => $this->staleReasons($user, $team, $comparison->selection),
            'confirmed_at' => $comparison->confirmed_at?->toAtomString(), 'canceled_at' => $comparison->canceled_at?->toAtomString()];
    }
}
