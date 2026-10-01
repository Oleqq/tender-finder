<?php

namespace App\Http\Controllers;

use App\Enums\QueryStatus;
use App\Models\RostenderFeedSearchQuery;
use App\Models\SearchQuery;
use App\Services\AccessService;
use App\Services\B2bCenterSearchService;
use App\Services\CachedMonitoringMatchService;
use App\Services\MonitoringStatusService;
use App\Services\PublicTenderSources;
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

        abort_unless(app(AccessService::class)->hasActiveAccess($request->user()), 403);
        $public = app(PublicTenderSources::class);
        $public->synchronize();
        if ($public->feeds()->isNotEmpty() && $query->status === QueryStatus::Active) {
            app(B2bCenterSearchService::class)->synchronize($query);
            $queued = $public->dispatchDueChecks($query);
            app(CachedMonitoringMatchService::class)->fill($query);
            $feed = RostenderFeedSearchQuery::query()->where('search_query_id', $query->id)->with('feed')->first()?->feed;
            if ($feed !== null) {
                try {
                    $rostender->queue($request->user(), $feed);
                    $queued = true;
                } catch (RostenderAccessDisabledException|RuntimeException) {
                    // Public feeds continue independently of RosTender's quota.
                }
            }

            return response()->json([
                'queued' => $queued,
                'message' => $queued
                    ? 'Проверяем подключённые источники. Результаты появятся в вашей ленте.'
                    : 'Источники уже проверены или ожидают следующей попытки. Доступные результаты добавлены в вашу ленту.',
                'query' => [...$presenter->toArray($query),
                    'source_statuses' => app(MonitoringStatusService::class)->forQueries(collect([$query]))[$query->id]],
            ], $queued ? 202 : 200);
        }

        $feed = RostenderFeedSearchQuery::query()
            ->where('search_query_id', $query->id)
            ->with('feed')
            ->first()?->feed;

        if ($feed === null) {
            throw ValidationException::withMessages([
                'query' => $query->status !== QueryStatus::Active
                    ? 'Возобновите мониторинг, чтобы проверить источники.'
                    : 'У мониторинга нет доступного источника. Проверьте настройки.',
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
                'query' => match ($exception->getMessage()) {
                    'rostender_manual_check_limit_reached' => 'Лимит ручных проверок RosTender на сегодня исчерпан.',
                    'rostender_source_quota_exhausted' => 'Проверки RosTender сейчас ограничены. Следующая автоматическая попытка уже запланирована.',
                    default => 'Не удалось запустить проверку RosTender. Попробуйте ещё раз.',
                },
            ]);
        }

        return response()->json([
            'queued' => true,
            'query' => $presenter->toArray($query),
        ], 202);
    }
}
