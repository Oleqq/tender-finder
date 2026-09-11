<?php

namespace App\Http\Controllers;

use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderChange;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
use App\Services\TenderFacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenderFeedbackController extends Controller
{
    public function changes(Request $request, Tender $tender): JsonResponse
    {
        abort_unless($tender->matches()->whereHas('searchQuery', fn ($q) => $q->where('user_id', $request->user()->id))->exists(), 404);

        return response()->json(['changes' => TenderChange::query()->where('tender_id', $tender->id)
            ->latest('id')->limit(30)->get(['id', 'changes', 'created_at'])]);
    }

    public function store(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate([
            'search_query_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
            'exclusion_kind' => ['nullable', 'in:keyword,customer'],
            'exclusion_value' => ['nullable', 'string', 'max:100'],
            'apply_filter' => ['required', 'boolean'],
        ]);
        $user = $request->user();
        $query = SearchQuery::query()->where('user_id', $user->id)->where('status', '!=', 'deleted')->findOrFail($data['search_query_id']);
        abort_unless(TenderQueryMatch::query()->where('tender_id', $tender->id)->where('search_query_id', $query->id)->exists(), 404);
        $kind = $data['exclusion_kind'] ?? null;
        $value = $kind === 'customer' ? TenderFacts::customer($tender) : trim($data['exclusion_value'] ?? '');
        if ($data['apply_filter'] && ($kind === null || $value === null || $value === '' || mb_strlen($value) > 200)) {
            throw ValidationException::withMessages(['exclusion_value' => 'Укажите исключаемое слово или выберите заказчика из карточки.']);
        }

        DB::transaction(function () use ($data, $query, $user, $tender, $kind, $value): void {
            $query = SearchQuery::query()->lockForUpdate()->findOrFail($query->id);
            if ($data['apply_filter']) {
                if ($kind === 'keyword') {
                    $words = array_values(array_unique([...($query->minus_keywords ?? []), $value]));
                    if (count($words) > 20) {
                        throw ValidationException::withMessages(['exclusion_value' => 'В мониторинге уже 20 минус-слов. Отредактируйте их в настройках.']);
                    }
                    $query->minus_keywords = $words;
                } else {
                    $filters = $query->filters ?? [];
                    $customers = array_values(array_unique([...($filters['excluded_customers'] ?? []), $value]));
                    if (count($customers) > 50) {
                        throw ValidationException::withMessages(['exclusion_value' => 'Достигнут лимит 50 исключённых заказчиков.']);
                    }
                    $query->filters = [...$filters, 'excluded_customers' => $customers];
                }
                $query->save();
            }
            TenderUserState::query()->updateOrCreate(['user_id' => $user->id, 'tender_id' => $tender->id], ['status' => 'dismissed']);
            DB::table('tender_feedback')->insert([
                'user_id' => $user->id, 'tender_id' => $tender->id, 'search_query_id' => $query->id,
                'reason' => trim($data['reason']), 'exclusion_kind' => $kind, 'exclusion_value' => $value ?: null,
                'applied_at' => $data['apply_filter'] ? now() : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return response()->json(['applied' => $data['apply_filter']]);
    }
}
