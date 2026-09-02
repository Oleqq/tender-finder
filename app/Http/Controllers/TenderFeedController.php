<?php

namespace App\Http\Controllers;

use App\Models\TenderQueryMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenderFeedController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        return Inertia::render('Tenders', [
            'tenderMatches' => TenderQueryMatch::query()
                ->whereHas('searchQuery', fn ($query) => $query->where('user_id', $user->id))
                ->with(['searchQuery:id,name', 'tender'])
                ->latest('matched_at')
                ->limit(50)
                ->get()
                ->map(fn (TenderQueryMatch $match): array => [
                    'id' => $match->id,
                    'title' => $match->tender->title,
                    'description' => $match->tender->description,
                    'canonical_url' => $match->tender->canonical_url,
                    'reg_number' => $match->tender->reg_number,
                    'region' => $match->tender->region,
                    'budget_amount' => $match->tender->budget_amount,
                    'currency' => $match->tender->currency,
                    'deadline_at' => $match->tender->deadline_at?->toAtomString(),
                    'matched_at' => $match->matched_at->toAtomString(),
                    'query_name' => $match->searchQuery->name,
                    'match_reasons' => $this->reasonLabels($match->match_reasons ?? []),
                ])
                ->values(),
        ]);
    }

    /** @param array<string, mixed> $reasons
     * @return list<string>
     */
    private function reasonLabels(array $reasons): array
    {
        $labels = [];

        if (($reasons['keywords'] ?? []) !== []) {
            $labels[] = 'ключевые слова';
        }

        if (($reasons['region'] ?? null) === 'matched') {
            $labels[] = 'регион';
        }

        if (($reasons['budget'] ?? null) === 'matched') {
            $labels[] = 'сумма';
        }

        if (($reasons['deadline'] ?? null) === 'matched') {
            $labels[] = 'срок';
        }

        return $labels === [] ? ['настройки мониторинга'] : $labels;
    }
}
