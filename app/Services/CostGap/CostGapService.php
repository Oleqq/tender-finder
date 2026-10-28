<?php

namespace App\Services\CostGap;

use App\Models\CostGapCheck;
use App\Models\CostGapTransfer;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\User;
use App\Services\AccessService;
use App\Services\TenderAnalyticsService;

final class CostGapService
{
    public const CATEGORIES = ['planned_cost', 'commission_cost', 'other_cost'];

    public function enabled(User $user): bool
    {
        return app(AccessService::class)->hasActiveAccess($user)
            && in_array(app(TenderAnalyticsService::class)->tierFor($user), ['pro', 'ultra'], true);
    }

    public function fingerprint(Tender $tender): string
    {
        return hash('sha256', json_encode([$tender->title, $tender->description, $tender->metadata,
            $tender->budget_amount, $tender->currency, $tender->deadline_at?->toAtomString()], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function stale(CostGapCheck $check, Tender $tender, TenderParticipation $participation, bool $lock = false): array
    {
        $reasons = [];
        if ($check->tender_hash !== $this->fingerprint($tender)) {
            $reasons[] = 'Карточка закупки изменилась. Проверьте материалы и повторите сравнение.';
        }
        foreach ($check->sources as $source) {
            if (! app(SourceReader::class)->current($source, $participation, $lock)) {
                $reasons[] = 'Документ «'.$source['title'].'» заменён или архивирован. Выберите актуальный источник.';
            }
        }

        return $reasons;
    }

    /** @return array<string, mixed> */
    public function present(?CostGapCheck $check, Tender $tender, ?TenderParticipation $participation): array
    {
        $economics = ['version' => $participation->economics_version ?? 1];
        foreach (self::CATEGORIES as $field) {
            $economics[$field] = $participation?->getAttribute($field);
        }
        $transfers = $check === null ? [] : CostGapTransfer::query()->where('cost_gap_check_id', $check->id)->orderBy('id')->get()->map(fn (CostGapTransfer $row): array => [
            'key' => $row->requirement_key, 'title' => $row->snapshot['row']['title'], 'amount' => $row->amount,
            'category' => $row->category, 'previous_amount' => $row->previous_amount, 'result_amount' => $row->result_amount,
            'economics_version' => $row->economics_version, 'created_at' => $row->created_at?->toAtomString(),
        ])->all();

        return ['version' => $check->version ?? 0, 'source_hash' => $check->source_hash ?? '', 'sources' => $check?->sources,
            'manual_requirements' => $check->manual_requirements ?? '', 'rows' => $check->rows ?? [],
            'decisions' => (object) ($check->decisions ?? []), 'transfers' => $transfers,
            'stale' => $check !== null && $participation !== null ? $this->stale($check, $tender, $participation) : [],
            'economics' => $economics];
    }

    public function minor(string $amount): int
    {
        $parts = explode('.', str_replace(',', '.', $amount));

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
