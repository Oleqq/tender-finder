<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Services\TenderFacts;
use App\Services\TenderTitle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TenderDetailController extends Controller
{
    public function __invoke(Request $request, Tender $tender): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $match = TenderQueryMatch::query()
            ->where('tender_id', $tender->id)
            ->whereHas('searchQuery', fn (Builder $query) => $query->where('user_id', $user->id))
            ->with('searchQuery:id,name')
            ->latest('matched_at')
            ->firstOrFail();
        $reasons = $match->match_reasons ?? [];
        $reasonLabels = [];
        if (($reasons['keywords'] ?? []) !== []) {
            $reasonLabels[] = 'ключевые слова';
        }
        foreach (['region' => 'регион', 'budget' => 'сумма', 'deadline' => 'срок'] as $key => $label) {
            if (($reasons[$key] ?? null) === 'matched') {
                $reasonLabels[] = $label;
            }
        }
        $sourceLabel = match ($tender->source) {
            'rostender' => 'RosTender',
            'sber_ast' => 'Сбер АСТ',
            'workspace_ru' => 'Workspace.ru',
            'b2b_center' => 'B2B-Center',
            'roseltorg' => 'Росэлторг',
            'rts_tender' => 'РТС-Тендер',
            default => 'Источник закупки',
        };

        return Inertia::render('TenderDetail', [
            'tender' => [
                'id' => $tender->id,
                'title' => TenderTitle::display($tender->title),
                'description' => $tender->description,
                'reg_number' => $tender->reg_number,
                'customer' => TenderFacts::customer($tender),
                'region' => $tender->region,
                'budget_amount' => $tender->budget_amount,
                'currency' => $tender->currency,
                'published_at' => $tender->published_at?->toAtomString(),
                'deadline_at' => $tender->deadline_at?->toAtomString(),
                'canonical_url' => $tender->canonical_url,
                'platform_url' => in_array($tender->source, ['roseltorg', 'rts_tender'], true)
                    ? ($tender->metadata['platform_url'] ?? null) : null,
                'source_label' => $sourceLabel,
                'query_name' => $match->searchQuery->name,
                'match_reasons' => $reasonLabels === [] ? ['настройки мониторинга'] : $reasonLabels,
            ],
        ]);
    }
}
