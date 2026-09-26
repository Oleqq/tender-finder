<?php

namespace App\Http\Controllers;

use App\Enums\QueryStatus;
use App\Models\RostenderFeedSearchQuery;
use App\Models\SearchQuery;
use App\Services\RostenderManualCheckService;
use App\Services\SearchQueryPresenter;
use App\Tenders\RostenderAccessDisabledException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class SavedSearchRunController extends Controller
{
    public function __invoke(
        Request $request,
        SearchQuery $query,
        SearchQueryPresenter $presenter,
        RostenderManualCheckService $rostender,
    ): JsonResponse {
        abort_unless(
            $query->user_id === $request->user()?->id && $query->status !== QueryStatus::Deleted,
            404,
        );

        $feed = RostenderFeedSearchQuery::query()
            ->where('search_query_id', $query->id)
            ->with('feed')
            ->first()?->feed;

        if ($feed === null) {
            throw ValidationException::withMessages([
                'query' => 'Этот старый мониторинг не подключён к RosTender. Выберите шаблон источника в настройках.',
            ]);
        }

        try {
            $rostender->queue($request->user(), $feed);
        } catch (RostenderAccessDisabledException) {
            throw ValidationException::withMessages([
                'query' => 'RosTender временно недоступен для этого мониторинга.',
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'query' => $exception->getMessage() === 'rostender_manual_check_limit_reached'
                    ? 'Лимит ручных проверок RosTender на сегодня исчерпан.'
                    : 'Не удалось запустить проверку RosTender. Попробуйте ещё раз.',
            ]);
        }

        return response()->json([
            'queued' => true,
            'query' => $presenter->toArray($query),
        ], 202);
    }
}
